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
}
