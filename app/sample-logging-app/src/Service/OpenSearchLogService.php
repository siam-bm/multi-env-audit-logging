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
 */
class OpenSearchLogService
{
    private Client $http;
    private string $base;

    public function __construct()
    {
        $host = env('OPENSEARCH_HOST', '10.0.2.30');
        $port = env('OPENSEARCH_PORT', '9200');
        $this->base = "http://{$host}:{$port}";
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
        $response = $this->http->post(
            "{$this->base}/{$index}/_search",
            json_encode($body),
            ['type' => 'json']
        );

        $data = $response->getJson() ?? [];
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
     * @param string $index Index pattern.
     * @return array{index:string, query:array, events:array}
     */
    public function recentEvents(array $filters = [], int $size = 50, string $index = 'logs-audit-dev-*'): array
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

        return ['index' => $index, 'query' => $query, 'events' => $this->search($index, $query)];
    }

    /**
     * View A — everything one user did, in chronological order.
     * Returns the index, the exact Query DSL used, and the matching events
     * (so a view can show HOW the data was fetched, not just the result).
     *
     * @param int $userId User id.
     * @param string $index Index pattern.
     * @return array{index:string, query:array, events:array}
     */
    public function userFlow(int $userId, string $index = 'logs-audit-dev-*'): array
    {
        $query = [
            'size' => 200,
            'sort' => [['@timestamp' => 'asc']],
            'query' => ['bool' => ['filter' => [
                ['term' => ['user_id' => $userId]],
            ]]],
        ];

        return ['index' => $index, 'query' => $query, 'events' => $this->search($index, $query)];
    }

    /**
     * View B — the life of one entity (who changed it, before/after).
     *
     * @param string $table Table name, e.g. "products".
     * @param int $entityId Entity id.
     * @param string $index Index pattern.
     * @return array{index:string, query:array, events:array}
     */
    public function entityFlow(string $table, int $entityId, string $index = 'logs-audit-dev-*'): array
    {
        $query = [
            'size' => 200,
            'sort' => [['@timestamp' => 'asc']],
            'query' => ['bool' => ['filter' => [
                ['term' => ['entity_id' => $entityId]],
                ['match' => ['table' => $table]],
            ]]],
        ];

        return ['index' => $index, 'query' => $query, 'events' => $this->search($index, $query)];
    }

    /**
     * The full flow of ONE request/operation, stitched by its correlation id.
     * This is the cross-process/cross-server view: every process writes to the
     * same index and stamps the same trace_id, so filtering by that one id
     * returns the whole flow regardless of which server produced each line.
     * (In RGS this id becomes request_id, propagated through RabbitMQ.)
     *
     * @param string $traceId Correlation id.
     * @param string $index Index pattern.
     * @return array{index:string, query:array, events:array}
     */
    public function traceFlow(string $traceId, string $index = 'logs-audit-dev-*'): array
    {
        $query = [
            'size' => 200,
            'sort' => [['@timestamp' => 'asc']],
            // .keyword = exact match on the whole id (the text field would tokenize it).
            'query' => ['bool' => ['filter' => [
                ['term' => ['trace_id.keyword' => $traceId]],
            ]]],
        ];

        return ['index' => $index, 'query' => $query, 'events' => $this->search($index, $query)];
    }
}
