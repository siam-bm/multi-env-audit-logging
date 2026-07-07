<?php
declare(strict_types=1);

namespace App\Controller;

/**
 * AuditLogs Controller - in-app viewer for the audit trail.
 *
 * Reads DIRECTLY from OpenSearch (the logs-audit-* indices Fluent Bit ships to):
 *  - index:        recent activity feed
 *  - userFlowOs:   the full chronological flow of one person
 *  - productFlowOs: everything that happened to one product, and who did it
 */
class AuditLogsController extends AppController
{
    /**
     * Recent activity feed — read live from OpenSearch.
     * Optional query filters: ?user_id= , ?action= .
     *
     * @return void
     */
    public function index()
    {
        $filters = [
            'user_id' => $this->request->getQuery('user_id'),
            'action' => $this->request->getQuery('action'),
        ];

        $result = (new \App\Service\OpenSearchLogService())->recentEvents($filters);

        $this->set([
            'index' => $result['index'],
            'query' => $result['query'],
            'events' => $result['events'],
            'userId' => $filters['user_id'],
            'action' => $filters['action'],
        ]);
    }

    /**
     * View A read DIRECTLY from OpenSearch — everything one user did.
     * URL: /audit-logs/user-flow-os/1  (add ?format=json for the raw payload).
     *
     * @param string|null $userId User id.
     * @return \Cake\Http\Response|null|void
     */
    public function userFlowOs($userId = null)
    {
        $result = (new \App\Service\OpenSearchLogService())->userFlow((int)$userId);

        return $this->renderFlow($result, [
            'title' => '👤 User flow · user_id ' . (int)$userId,
            'subtitle' => 'Everything user #' . (int)$userId . ' did, oldest first — read live from OpenSearch',
            'filterKey' => 'user_id',
            'filterValue' => (int)$userId,
            'jsonMeta' => ['source' => 'opensearch', 'user_id' => (int)$userId],
        ]);
    }

    /**
     * View B read DIRECTLY from OpenSearch — the life of one product.
     * URL: /audit-logs/product-flow-os/2  (add ?format=json for the raw payload).
     *
     * @param string|null $id Product id.
     * @return \Cake\Http\Response|null|void
     */
    public function productFlowOs($id = null)
    {
        $result = (new \App\Service\OpenSearchLogService())->entityFlow('products', (int)$id);

        return $this->renderFlow($result, [
            'title' => '📦 Product flow · entity_id ' . (int)$id,
            'subtitle' => 'Everything that happened to product #' . (int)$id . ', and who did it — read live from OpenSearch',
            'filterKey' => 'entity_id',
            'filterValue' => (int)$id,
            'jsonMeta' => ['source' => 'opensearch', 'table' => 'products', 'entity_id' => (int)$id],
        ]);
    }

    /**
     * View C — the full flow of ONE request/operation, stitched by its
     * correlation id (trace_id). This is the cross-process / cross-server view:
     * every process writes to the SAME index and stamps the SAME id, so one
     * filter returns the whole flow no matter which server produced each line.
     * URL: /audit-logs/trace-flow-os?trace=<id>  (add &format=json for raw).
     *
     * @return \Cake\Http\Response|null|void
     */
    public function traceFlowOs()
    {
        $traceId = (string)$this->request->getQuery('trace');
        $result = (new \App\Service\OpenSearchLogService())->traceFlow($traceId);

        return $this->renderFlow($result, [
            'title' => '🔗 Request trace · trace_id ' . $traceId,
            'subtitle' => 'Every log line sharing this one id — the same-id-across-servers view',
            'filterKey' => 'trace_id',
            'filterValue' => $traceId,
            'jsonMeta' => ['source' => 'opensearch', 'trace_id' => $traceId],
        ]);
    }

    /**
     * Shared render/JSON path for the OpenSearch flow views.
     *
     * @param array $result Service result ({index, query, events}).
     * @param array $opts {title, subtitle, filterKey, filterValue, jsonMeta}.
     * @return \Cake\Http\Response|null|void
     */
    private function renderFlow(array $result, array $opts)
    {
        if ($this->request->getQuery('format') === 'json') {
            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode($opts['jsonMeta'] + [
                    'index_pattern' => $result['index'],
                    'query' => $result['query'],
                    'count' => count($result['events']),
                    'events' => $result['events'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $service = new \App\Service\OpenSearchLogService();
        $this->set([
            'title' => $opts['title'],
            'subtitle' => $opts['subtitle'],
            'indexPattern' => $result['index'],
            'indicesUsed' => $service->indicesUsed($result['events']),
            'filterKey' => $opts['filterKey'],
            'filterValue' => $opts['filterValue'],
            'query' => $result['query'],
            'events' => $result['events'],
        ]);
        $this->render('os_flow');
    }
}
