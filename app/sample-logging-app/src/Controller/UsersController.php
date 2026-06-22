<?php
declare(strict_types=1);

namespace App\Controller;

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