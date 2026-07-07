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

        if ($this->request->getQuery('format') === 'json') {
            return $this->asJson($result, ['source' => 'opensearch', 'user_id' => (int)$userId]);
        }

        $this->set([
            'title' => '👤 User flow #' . (int)$userId,
            'subtitle' => 'Read live from OpenSearch — everything user #' . (int)$userId . ' did, oldest first',
            'index' => $result['index'],
            'query' => $result['query'],
            'events' => $result['events'],
        ]);
        $this->render('os_flow');
    }

    /**
     * View B read DIRECTLY from OpenSearch (not the DB) — the life of one product.
     * URL: /audit-logs/product-flow-os/2  (add ?format=json for the raw payload).
     *
     * @param string|null $id Product id.
     * @return \Cake\Http\Response|null|void
     */
    public function productFlowOs($id = null)
    {
        $result = (new \App\Service\OpenSearchLogService())->entityFlow('products', (int)$id);

        if ($this->request->getQuery('format') === 'json') {
            return $this->asJson($result, ['source' => 'opensearch', 'table' => 'products', 'entity_id' => (int)$id]);
        }

        $this->set([
            'title' => '📦 Product flow #' . (int)$id,
            'subtitle' => 'Read live from OpenSearch — everything that happened to product #' . (int)$id . ', and who did it',
            'index' => $result['index'],
            'query' => $result['query'],
            'events' => $result['events'],
        ]);
        $this->render('os_flow');
    }

    /**
     * Build a pretty-printed JSON response from a service result.
     *
     * @param array $result Service result ({index, query, events}).
     * @param array $meta Extra top-level fields.
     * @return \Cake\Http\Response
     */
    private function asJson(array $result, array $meta): \Cake\Http\Response
    {
        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode($meta + [
                'index' => $result['index'],
                'query' => $result['query'],
                'count' => count($result['events']),
                'events' => $result['events'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
