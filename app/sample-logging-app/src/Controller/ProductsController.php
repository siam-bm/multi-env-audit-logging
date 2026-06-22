<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Log\Log;

/**
 * Products Controller with Comprehensive Logging
 *
 * @property \App\Model\Table\ProductsTable $Products
 * @method \App\Model\Entity\Product[]|\Cake\Datasource\ResultSetInterface paginate($object = null, array $settings = [])
 */
class ProductsController extends AppController
{
    private $traceId;

    /**
     * Initialize method
     */
    public function initialize(): void
    {
        parent::initialize();
        // Generate or get trace ID for distributed tracing
        $this->traceId = $this->request->getHeader('X-Trace-Id')[0] ?? uniqid('prod_', true);
    }

    /**
     * Index method - List all products
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        Log::info('Products index accessed', [
            'trace_id' => $this->traceId,
            'action' => 'products.index',
            'ip' => $this->request->clientIp(),
            'user_agent' => $this->request->getHeader('User-Agent')[0] ?? 'Unknown'
        ]);

        try {
            $query = $this->Products->find();
            $products = $this->paginate($query);
            
            Log::info('Products list retrieved successfully', [
                'trace_id' => $this->traceId,
                'action' => 'products.index.success',
                'count' => count($products->toArray()),
                'page' => $this->request->getQuery('page', 1)
            ]);

            $this->set(compact('products'));
        } catch (\Exception $e) {
            Log::error('Failed to retrieve products list', [
                'trace_id' => $this->traceId,
                'action' => 'products.index.error',
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            $this->Flash->error(__('Unable to load products. Please try again.'));
        }
    }

    /**
     * View method - Display single product
     *
     * @param string|null $id Product id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        Log::info('Product view accessed', [
            'trace_id' => $this->traceId,
            'action' => 'products.view',
            'product_id' => $id,
            'ip' => $this->request->clientIp()
        ]);

        try {
            $product = $this->Products->get($id);

            // Audit log for viewing product data
            Log::info('Product data viewed', [
                'trace_id' => $this->traceId,
                'action' => 'products.view.success',
                'product_id' => $id,
                'product_name' => $product->name,
                'viewed_by_ip' => $this->request->clientIp(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            $this->set(compact('product'));
        } catch (\Exception $e) {
            Log::warning('Failed to view product', [
                'trace_id' => $this->traceId,
                'action' => 'products.view.error',
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);
            $this->Flash->error(__('Product not found.'));
            return $this->redirect(['action' => 'index']);
        }
    }

    /**
     * Add method - Create new product
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        Log::info('Product add form accessed', [
            'trace_id' => $this->traceId,
            'action' => 'products.add.form',
            'ip' => $this->request->clientIp()
        ]);

        $product = $this->Products->newEmptyEntity();
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            
            Log::info('Product creation attempted', [
                'trace_id' => $this->traceId,
                'action' => 'products.add.attempt',
                'data' => [
                    'name' => $data['name'] ?? null,
                    'price' => $data['price'] ?? null,
                    'quantity' => $data['quantity'] ?? null,
                    'status' => $data['status'] ?? null
                ],
                'ip' => $this->request->clientIp()
            ]);

            $product = $this->Products->patchEntity($product, $data);
            if ($this->Products->save($product)) {
                // Audit log for successful product creation
                Log::info('Product created successfully', [
                    'trace_id' => $this->traceId,
                    'action' => 'products.add.success',
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $product->price,
                    'quantity' => $product->quantity,
                    'created_by_ip' => $this->request->clientIp(),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);

                $this->Flash->success(__('The product has been saved.'));
                return $this->redirect(['action' => 'index']);
            }
            
            Log::warning('Product creation failed', [
                'trace_id' => $this->traceId,
                'action' => 'products.add.failed',
                'errors' => $product->getErrors(),
                'data' => [
                    'name' => $data['name'] ?? null,
                    'price' => $data['price'] ?? null
                ]
            ]);
            
            $this->Flash->error(__('The product could not be saved. Please, try again.'));
        }
        $this->set(compact('product'));
    }

    /**
     * Edit method - Update existing product
     *
     * @param string|null $id Product id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        Log::info('Product edit form accessed', [
            'trace_id' => $this->traceId,
            'action' => 'products.edit.form',
            'product_id' => $id,
            'ip' => $this->request->clientIp()
        ]);

        try {
            $product = $this->Products->get($id);
            
            if ($this->request->is(['patch', 'post', 'put'])) {
                $data = $this->request->getData();
                
                // Capture old data for audit
                $oldData = [
                    'name' => $product->name,
                    'price' => $product->price,
                    'quantity' => $product->quantity,
                    'status' => $product->status
                ];
                
                Log::info('Product update attempted', [
                    'trace_id' => $this->traceId,
                    'action' => 'products.edit.attempt',
                    'product_id' => $id,
                    'old_data' => $oldData,
                    'new_data' => [
                        'name' => $data['name'] ?? null,
                        'price' => $data['price'] ?? null,
                        'quantity' => $data['quantity'] ?? null,
                        'status' => $data['status'] ?? null
                    ],
                    'ip' => $this->request->clientIp()
                ]);

                $product = $this->Products->patchEntity($product, $data);
                if ($this->Products->save($product)) {
                    // Audit log with before/after data
                    Log::info('Product updated successfully', [
                        'trace_id' => $this->traceId,
                        'action' => 'products.edit.success',
                        'product_id' => $id,
                        'product_name' => $product->name,
                        'changes' => [
                            'before' => $oldData,
                            'after' => [
                                'name' => $product->name,
                                'price' => $product->price,
                                'quantity' => $product->quantity,
                                'status' => $product->status
                            ]
                        ],
                        'updated_by_ip' => $this->request->clientIp(),
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);

                    $this->Flash->success(__('The product has been saved.'));
                    return $this->redirect(['action' => 'index']);
                }
                
                Log::warning('Product update failed', [
                    'trace_id' => $this->traceId,
                    'action' => 'products.edit.failed',
                    'product_id' => $id,
                    'errors' => $product->getErrors()
                ]);
                
                $this->Flash->error(__('The product could not be saved. Please, try again.'));
            }
            $this->set(compact('product'));
        } catch (\Exception $e) {
            Log::error('Failed to edit product', [
                'trace_id' => $this->traceId,
                'action' => 'products.edit.error',
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);
            $this->Flash->error(__('Product not found.'));
            return $this->redirect(['action' => 'index']);
        }
    }

    /**
     * Delete method - Remove product
     *
     * @param string|null $id Product id.
     * @return \Cake\Http\Response|null|void Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        
        Log::info('Product deletion attempted', [
            'trace_id' => $this->traceId,
            'action' => 'products.delete.attempt',
            'product_id' => $id,
            'ip' => $this->request->clientIp()
        ]);

        try {
            $product = $this->Products->get($id);
            $productName = $product->name;
            $productData = [
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => $product->quantity
            ];
            
            if ($this->Products->delete($product)) {
                // Audit log for product deletion
                Log::warning('Product deleted', [
                    'trace_id' => $this->traceId,
                    'action' => 'products.delete.success',
                    'product_id' => $id,
                    'product_name' => $productName,
                    'deleted_data' => $productData,
                    'deleted_by_ip' => $this->request->clientIp(),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);

                $this->Flash->success(__('The product has been deleted.'));
            } else {
                Log::error('Product deletion failed', [
                    'trace_id' => $this->traceId,
                    'action' => 'products.delete.failed',
                    'product_id' => $id,
                    'product_name' => $productName
                ]);
                
                $this->Flash->error(__('The product could not be deleted. Please, try again.'));
            }
        } catch (\Exception $e) {
            Log::error('Failed to delete product', [
                'trace_id' => $this->traceId,
                'action' => 'products.delete.error',
                'product_id' => $id,
                'error' => $e->getMessage()
            ]);
            $this->Flash->error(__('Product not found.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}