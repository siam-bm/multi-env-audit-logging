<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Auth\DefaultPasswordHasher;
use Cake\Event\EventInterface;
use Cake\Log\Log;

/**
 * Users Controller with Centralized Logging
 *
 * @property \App\Model\Table\UsersTable $Users
 * @method \App\Model\Entity\User[]|\Cake\Datasource\ResultSetInterface paginate($object = null, array $settings = [])
 */
class UsersController extends AppController
{
    private $traceId;

    /**
     * Initialize method
     */
    public function initialize(): void
    {
        parent::initialize();
        // Generate or get trace ID for distributed tracing
        $this->traceId = $this->request->getHeader('X-Trace-Id')[0] ?? uniqid('trace_', true);
    }

    /**
     * Require authentication for everything except login/logout.
     *
     * @param \Cake\Event\EventInterface $event Controller event.
     * @return \Cake\Http\Response|null
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        if (in_array($this->request->getParam('action'), ['login', 'logout'], true)) {
            return null;
        }

        return $this->requireLogin();
    }

    /**
     * Login - authenticates a user and records a 'users.login' audit event.
     *
     * @return \Cake\Http\Response|null
     */
    public function login()
    {
        if ($this->request->is('post')) {
            $email = (string)$this->request->getData('email');
            $password = (string)$this->request->getData('password');

            $user = $this->Users->find()->where(['email' => $email])->first();
            $ok = $user && (
                (new DefaultPasswordHasher())->check($password, $user->password)
                || $password === $user->password // plaintext fallback for legacy rows
            );

            if ($ok) {
                $this->request->getSession()->write('Auth.User', [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]);
                $this->recordAuthEvent('users.login', $user->id, "{$user->name} logged in");
                $this->Flash->success("Welcome back, {$user->name}!");

                return $this->redirect(['controller' => 'Products', 'action' => 'index']);
            }

            // Failed attempt (no DB row, but shipped to the audit log stream).
            Log::warning(json_encode([
                'trace_id' => $this->traceId,
                'action' => 'users.login.failed',
                'table' => 'users',
                'email' => $email,
                'ip' => $this->request->clientIp(),
                'timestamp' => date('Y-m-d H:i:s'),
            ]), ['audit', 'application']);
            $this->Flash->error('Invalid email or password.');
        }

        $this->set('title', 'Login');
    }

    /**
     * Logout - records a 'users.logout' audit event and clears the session.
     *
     * @return \Cake\Http\Response|null
     */
    public function logout()
    {
        $user = $this->request->getSession()->read('Auth.User');
        if ($user) {
            $this->recordAuthEvent('users.logout', $user['id'], "{$user['name']} logged out");
        }
        $this->request->getSession()->delete('Auth.User');
        $this->Flash->success('You have been logged out.');

        return $this->redirect(['controller' => 'Users', 'action' => 'login']);
    }

    /**
     * Record a login/logout event to both the audit_logs table and the audit
     * log stream (so it appears in the in-app flow viewer and in OpenSearch).
     *
     * @param string $action Audit action name.
     * @param int $userId Acting user id.
     * @param string $message Human message.
     * @return void
     */
    private function recordAuthEvent(string $action, int $userId, string $message): void
    {
        Log::write('notice', json_encode([
            'trace_id' => $this->traceId,
            'action' => $action,
            'table' => 'users',
            'entity_type' => 'Users',
            'entity_id' => $userId,
            'user_id' => $userId,
            'message' => $message,
            'ip' => $this->request->clientIp(),
            'timestamp' => date('Y-m-d H:i:s'),
        ]), ['audit', 'application']);

        try {
            $auditLogs = $this->fetchTable('AuditLogs');
            $auditLogs->save($auditLogs->newEntity([
                'trace_id' => $this->traceId,
                'action' => $action,
                'table_name' => 'users',
                'entity_id' => $userId,
                'user_id' => $userId,
                'ip_address' => $this->request->clientIp(),
                'user_agent' => $this->request->getHeader('User-Agent')[0] ?? null,
            ]), ['checkRules' => false]);
        } catch (\Throwable $e) {
            Log::error('Audit DB write failed: ' . $e->getMessage(), ['audit']);
        }
    }

    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        Log::info('Users index accessed', [
            'trace_id' => $this->traceId,
            'action' => 'users.index',
            'ip' => $this->request->clientIp()
        ]);

        try {
            $users = $this->paginate($this->Users);
            
            Log::info('Users list retrieved successfully', [
                'trace_id' => $this->traceId,
                'count' => count($users->toArray())
            ]);

            $this->set(compact('users'));
        } catch (\Exception $e) {
            Log::error('Failed to retrieve users list', [
                'trace_id' => $this->traceId,
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * View method
     *
     * @param string|null $id User id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        Log::info('User view accessed', [
            'trace_id' => $this->traceId,
            'action' => 'users.view',
            'user_id' => $id
        ]);

        try {
            $user = $this->Users->get($id, [
                'contain' => [],
            ]);

            // Audit log for viewing user data
            Log::info('User data viewed', [
                'trace_id' => $this->traceId,
                'action' => 'users.view.success',
                'user_id' => $id,
                'viewed_by_ip' => $this->request->clientIp()
            ]);

            $this->set(compact('user'));
        } catch (\Exception $e) {
            Log::warning('Failed to view user', [
                'trace_id' => $this->traceId,
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        Log::info('User add form accessed', [
            'trace_id' => $this->traceId,
            'action' => 'users.add'
        ]);

        $user = $this->Users->newEmptyEntity();
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            
            // Log user creation attempt (without password)
            $logData = $data;
            unset($logData['password']);
            
            Log::info('User creation attempted', [
                'trace_id' => $this->traceId,
                'action' => 'users.add.attempt',
                'data' => $logData
            ]);

            $user = $this->Users->patchEntity($user, $data);
            if ($this->Users->save($user)) {
                // Audit log for successful user creation
                Log::info('User created successfully', [
                    'trace_id' => $this->traceId,
                    'action' => 'users.add.success',
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'created_by_ip' => $this->request->clientIp()
                ]);

                $this->Flash->success(__('The user has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            
            Log::warning('User creation failed', [
                'trace_id' => $this->traceId,
                'action' => 'users.add.failed',
                'errors' => $user->getErrors()
            ]);
            
            $this->Flash->error(__('The user could not be saved. Please, try again.'));
        }
        $this->set(compact('user'));
    }

    /**
     * Edit method
     *
     * @param string|null $id User id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        Log::info('User edit form accessed', [
            'trace_id' => $this->traceId,
            'action' => 'users.edit',
            'user_id' => $id
        ]);

        $user = $this->Users->get($id, [
            'contain' => [],
        ]);
        
        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            $oldData = $user->extract(['name', 'email']);
            
            // Log edit attempt (without password)
            $logData = $data;
            unset($logData['password']);
            
            Log::info('User update attempted', [
                'trace_id' => $this->traceId,
                'action' => 'users.edit.attempt',
                'user_id' => $id,
                'changes' => $logData
            ]);

            $user = $this->Users->patchEntity($user, $data);
            if ($this->Users->save($user)) {
                // Audit log with before/after data
                Log::info('User updated successfully', [
                    'trace_id' => $this->traceId,
                    'action' => 'users.edit.success',
                    'user_id' => $id,
                    'before' => $oldData,
                    'after' => $user->extract(['name', 'email']),
                    'updated_by_ip' => $this->request->clientIp()
                ]);

                $this->Flash->success(__('The user has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            
            Log::warning('User update failed', [
                'trace_id' => $this->traceId,
                'action' => 'users.edit.failed',
                'user_id' => $id,
                'errors' => $user->getErrors()
            ]);
            
            $this->Flash->error(__('The user could not be saved. Please, try again.'));
        }
        $this->set(compact('user'));
    }

    /**
     * Delete method
     *
     * @param string|null $id User id.
     * @return \Cake\Http\Response|null|void Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        
        Log::info('User deletion attempted', [
            'trace_id' => $this->traceId,
            'action' => 'users.delete.attempt',
            'user_id' => $id
        ]);

        $user = $this->Users->get($id);
        $userEmail = $user->email;
        
        if ($this->Users->delete($user)) {
            // Audit log for user deletion
            Log::warning('User deleted', [
                'trace_id' => $this->traceId,
                'action' => 'users.delete.success',
                'user_id' => $id,
                'email' => $userEmail,
                'deleted_by_ip' => $this->request->clientIp()
            ]);

            $this->Flash->success(__('The user has been deleted.'));
        } else {
            Log::error('User deletion failed', [
                'trace_id' => $this->traceId,
                'action' => 'users.delete.failed',
                'user_id' => $id
            ]);
            
            $this->Flash->error(__('The user could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}