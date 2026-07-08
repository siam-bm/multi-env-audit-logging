<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Http\Client;

/**
 * Reads audit/log data DIRECTLY from OpenSearch (the logs-audit-* indices that
 * Fluent Bit ships to), instead of the relational audit_logs table.
 *
 * This is the OpenSearch twin of AuditLogsController's DB queries:
 *   userFlow   -> filter user_id, sort by time
 *   entityFlow -> filter table + entity_id, sort by time
 *
 * Uses CakePHP's core Http\Client so no extra composer dependency is needed.
 * (In a production app you'd use opensearch-project/opensearch-php.)
 *
 * The index pattern is read from OPENSEARCH_LOG_INDEX so the same code serves
 * dev / staging / prod without edits.
 */
class OpenSearchLogService
{
    private Client $http;
    private string $base;
    private string $indexPattern;

    public function __construct()
    {
        $host = env('OPENSEARCH_HOST', '10.0.2.30');
        $port = env('OPENSEARCH_PORT', '9200');
        $this->base = "http://{$host}:{$port}";
        $this->indexPattern = (string)env('OPENSEARCH_LOG_INDEX', 'logs-audit-dev-*');
        $this->http = new Client(['timeout' => 10]);
    }

    /**
     * Run a Query DSL search and return the raw _source documents.
     *
     * @param string $index Index pattern, e.g. "logs-audit-dev-*".
     * @param array $body Query DSL body.
     * @return array List of _source documents.
     */
    public function search(string $index, array $body): array
    {
        try {
            $response = $this->http->post(
                "{$this->base}/{$index}/_search",
                json_encode($body),
                ['type' => 'json']
            );

            if (!$response->isOk()) {
                \Cake\Log\Log::warning("OpenSearch search on {$index} returned HTTP " . $response->getStatusCode());

                return [];
            }

            $data = $response->getJson() ?? [];
        } catch (\Throwable $e) {
            // OpenSearch unreachable/timed out — degrade gracefully instead of a 500.
            \Cake\Log\Log::error('OpenSearch unreachable: ' . $e->getMessage());

            return [];
        }

        $hits = $data['hits']['hits'] ?? [];

        // Keep the EXACT index each doc lives in (e.g. logs-audit-dev-2026.07.07)
        // alongside the source fields, so the view can show it.
        return array_map(
            fn ($hit) => ['_index' => $hit['_index'] ?? ''] + ($hit['_source'] ?? []),
            $hits
        );
    }

    /**
     * Distinct exact index names present in a result set (for display).
     *
     * @param array $events Events from search().
     * @return array
     */
    public function indicesUsed(array $events): array
    {
        return array_values(array_unique(array_filter(array_column($events, '_index'))));
    }

    /**
     * Recent activity feed (newest first), with optional user_id / action filters.
     *
     * @param array $filters {user_id?: int|string, action?: string}
     * @param int $size Max events.
     * @return array{index:string, query:array, events:array}
     */
    public function recentEvents(array $filters = [], int $size = 50): array
    {
        $must = [];
        if (!empty($filters['user_id'])) {
            $must[] = ['term' => ['user_id' => (int)$filters['user_id']]];
        }
        if (!empty($filters['action'])) {
            $must[] = ['match' => ['action' => $filters['action']]];
        }

        $query = [
            'size' => $size,
            'sort' => [['@timestamp' => 'desc']],
            'query' => $must ? ['bool' => ['filter' => $must]] : ['match_all' => (object)[]],
        ];

        return ['index' => $this->indexPattern, 'query' => $query, 'events' => $this->search($this->indexPattern, $query)];
    }

    /**
     * View A — everything one user did, in chronological order.
     * Returns the index, the exact Query DSL used, and the matching events
     * (so a view can show HOW the data was fetched, not just the result).
     *
     * @param int $userId User id.
     * @return array{index:string, query:array, events:array}
     */
    public function userFlow(int $userId): array
    {
        $query = [
            'size' => 200,
            'sort' => [['@timestamp' => 'asc']],
            'query' => ['bool' => ['filter' => [
                ['term' => ['user_id' => $userId]],
            ]]],
        ];

        return ['index' => $this->indexPattern, 'query' => $query, 'events' => $this->search($this->indexPattern, $query)];
    }

    /**
     * View B — the life of one entity (who changed it, before/after).
     *
     * @param string $table Table name, e.g. "products".
     * @param int $entityId Entity id.
     * @return array{index:string, query:array, events:array}
     */
    public function entityFlow(string $table, int $entityId): array
    {
        $query = [
            'size' => 200,
            'sort' => [['@timestamp' => 'asc']],
            'query' => ['bool' => ['filter' => [
                ['term' => ['entity_id' => $entityId]],
                ['match' => ['table' => $table]],
            ]]],
        ];

        return ['index' => $this->indexPattern, 'query' => $query, 'events' => $this->search($this->indexPattern, $query)];
    }

    /**
     * The full flow of ONE request/operation, stitched by its correlation id.
     * This is the cross-process/cross-server view: every process writes to the
     * same index and stamps the same trace_id, so filtering by that one id
     * returns the whole flow regardless of which server produced each line.
     * (In RGS this id becomes request_id, propagated through RabbitMQ.)
     *
     * @param string $traceId Correlation id.
     * @return array{index:string, query:array, events:array}
     */
    public function traceFlow(string $traceId): array
    {
        $query = [
            'size' => 200,
            'sort' => [['@timestamp' => 'asc']],
            // .keyword = exact match on the whole id (the text field would tokenize it).
            'query' => ['bool' => ['filter' => [
                ['term' => ['trace_id.keyword' => $traceId]],
            ]]],
        ];

        return ['index' => $this->indexPattern, 'query' => $query, 'events' => $this->search($this->indexPattern, $query)];
    }

    /**
     * The full flow of ONE login session, stitched by session_id.
     * A session spans many requests; the id is stamped on every line, so this
     * returns everything that user did during that session across servers.
     *
     * @param string $sessionId Session id.
     * @return array{index:string, query:array, events:array}
     */
    public function sessionFlow(string $sessionId): array
    {
        $query = [
            'size' => 500,
            'sort' => [['@timestamp' => 'asc']],
            'query' => ['bool' => ['filter' => [
                ['term' => ['session_id.keyword' => $sessionId]],
            ]]],
        ];

        return ['index' => $this->indexPattern, 'query' => $query, 'events' => $this->search($this->indexPattern, $query)];
    }
}
