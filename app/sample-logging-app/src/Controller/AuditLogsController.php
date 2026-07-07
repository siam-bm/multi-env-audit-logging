<?php
declare(strict_types=1);

namespace App\Controller;

/**
 * AuditLogs Controller - in-app viewer for the audit trail.
 *
 * Reads the relational audit_logs table to show:
 *  - index:        a filterable activity feed (related by user / table / action)
 *  - userFlow:     the full chronological flow of one person (login -> changes -> logout)
 *  - productFlow:  everything that happened to one product, and who did it
 */
class AuditLogsController extends AppController
{
    /**
     * Activity feed with filters, plus pickers for per-user and per-product flows.
     *
     * @return void
     */
    public function index()
    {
        $auditLogs = $this->fetchTable('AuditLogs');

        $query = $auditLogs->find()
            ->contain(['Users'])
            ->order(['AuditLogs.created' => 'DESC', 'AuditLogs.id' => 'DESC']);

        $userId = $this->request->getQuery('user_id');
        $tableName = $this->request->getQuery('table_name');
        $action = $this->request->getQuery('action');

        if ($userId !== null && $userId !== '') {
            $query->where(['AuditLogs.user_id' => (int)$userId]);
        }
        if ($tableName) {
            $query->where(['AuditLogs.table_name' => $tableName]);
        }
        if ($action) {
            $query->where(['AuditLogs.action LIKE' => '%' . $action . '%']);
        }

        $this->paginate = ['limit' => 50];
        $logs = $this->paginate($query);

        // Pickers for the "flow" views.
        $users = $this->fetchTable('Users')->find()->order(['name' => 'ASC'])->all();
        $products = $this->fetchTable('Products')->find()->order(['name' => 'ASC'])->all();

        $this->set(compact('logs', 'users', 'products', 'userId', 'tableName', 'action'));
    }

    /**
     * Full chronological flow for a single user.
     *
     * @param string|null $userId User id.
     * @return \Cake\Http\Response|null|void
     */
    public function userFlow($userId = null)
    {
        $user = $this->fetchTable('Users')->get($userId);

        $events = $this->fetchTable('AuditLogs')->find()
            ->where(['AuditLogs.user_id' => (int)$userId])
            ->order(['AuditLogs.created' => 'ASC', 'AuditLogs.id' => 'ASC'])
            ->all();

        $this->set(compact('user', 'events'));
    }

    /**
     * Full chronological flow for a single product (everyone who touched it).
     *
     * @param string|null $id Product id.
     * @return \Cake\Http\Response|null|void
     */
    public function productFlow($id = null)
    {
        $product = $this->fetchTable('Products')->find()->where(['id' => $id])->first();

        $events = $this->fetchTable('AuditLogs')->find()
            ->contain(['Users'])
            ->where(['AuditLogs.table_name' => 'products', 'AuditLogs.entity_id' => (int)$id])
            ->order(['AuditLogs.created' => 'ASC', 'AuditLogs.id' => 'ASC'])
            ->all();

        $this->set(compact('product', 'events', 'id'));
    }

    /**
     * View A read DIRECTLY from OpenSearch (not the DB) — everything one user did.
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
