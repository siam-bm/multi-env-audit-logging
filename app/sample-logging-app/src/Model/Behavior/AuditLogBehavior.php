<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\ORM\Table;
use Cake\Log\Log;
use Cake\Http\ServerRequest;

/**
 * AuditLog behavior - Centralized logging system for all CRUD operations
 */
class AuditLogBehavior extends Behavior
{
    /**
     * Default configuration
     *
     * @var array
     */
    protected $_defaultConfig = [
        'logScopes' => ['audit', 'application'],
        'fields' => [], // If empty, logs all fields
        'ignoreFields' => ['created', 'modified', 'id'],
        'logLevel' => 'info',
        'includeRequestData' => true,
        'includeSchema' => false
    ];

    /**
     * @var string
     */
    private $traceId;

    /**
     * @var ServerRequest|null
     */
    private $request;

    /**
     * Initialize behavior
     *
     * @param array $config Configuration
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        
        // Generate trace ID for distributed tracing
        $this->traceId = uniqid('audit_', true);
        
        // Get request object if available
        if (class_exists('\Cake\Http\ServerRequestFactory')) {
            $this->request = \Cake\Http\ServerRequestFactory::fromGlobals();
        }
    }

    /**
     * Before save callback - logs create/update attempts
     *
     * @param EventInterface $event Event
     * @param EntityInterface $entity Entity being saved
     * @param ArrayObject $options Options
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $table = $event->getSubject();
        $isNew = $entity->isNew();
        $action = $isNew ? 'create' : 'update';
        $tableName = $table->getTable();
        
        // Store the isNew state for afterSave callback
        $options['_isNew'] = $isNew;
        
        $logData = [
            'trace_id' => $this->traceId,
            'action' => "{$tableName}.{$action}.attempt",
            'table' => $tableName,
            'entity_type' => $table->getAlias(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if (!$entity->isNew()) {
            // For updates, get original values
            $originalEntity = $table->get($entity->id);
            $originalData = $this->extractEntityData($originalEntity);
            $newData = $this->extractEntityData($entity);
            
            // Calculate changes
            $changes = $this->calculateChanges($originalData, $newData);
            
            $logData['entity_id'] = $entity->id;
            $logData['original_values'] = $originalData;
            $logData['new_values'] = $newData;
            $logData['changed_fields'] = array_keys($changes);
            $logData['changes'] = $changes;
        } else {
            // For creates, just log new data
            $logData['new_values'] = $this->extractEntityData($entity);
        }

        // Add request data if configured
        if ($this->getConfig('includeRequestData') && $this->request) {
            $logData['request'] = [
                'ip' => $this->request->clientIp(),
                'user_agent' => $this->request->getHeader('User-Agent')[0] ?? 'Unknown',
                'method' => $this->request->getMethod(),
                'url' => $this->request->getRequestTarget()
            ];
        }

        Log::write($this->getConfig('logLevel'), json_encode($logData), $this->getConfig('logScopes'));
    }

    /**
     * After save callback - logs successful save
     *
     * @param EventInterface $event Event
     * @param EntityInterface $entity Entity that was saved
     * @param ArrayObject $options Options
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $table = $event->getSubject();
        // Check if entity was new before save (for create vs update detection)
        $wasNew = isset($options['_isNew']) ? $options['_isNew'] : false;
        $action = $wasNew ? 'create' : 'update';
        $tableName = $table->getTable();
        
        $logData = [
            'trace_id' => $this->traceId,
            'action' => "{$tableName}.{$action}.success",
            'table' => $tableName,
            'entity_type' => $table->getAlias(),
            'entity_id' => $entity->id,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if ($action === 'create') {
            $logData['created_entity'] = $this->extractEntityData($entity);
            $logData['message'] = "New {$table->getAlias()} created with ID: {$entity->id}";
        } else {
            $logData['updated_entity'] = $this->extractEntityData($entity);
            $logData['message'] = "{$table->getAlias()} {$entity->id} updated successfully";
        }

        // Add request data
        if ($this->getConfig('includeRequestData') && $this->request) {
            $logData['request'] = [
                'ip' => $this->request->clientIp(),
                'user_agent' => $this->request->getHeader('User-Agent')[0] ?? 'Unknown'
            ];
        }

        // Log as info for creates, notice for updates
        $level = $action === 'create' ? 'info' : 'notice';
        Log::write($level, json_encode($logData), $this->getConfig('logScopes'));
    }

    /**
     * Before delete callback - logs deletion attempt
     *
     * @param EventInterface $event Event
     * @param EntityInterface $entity Entity being deleted
     * @param ArrayObject $options Options
     * @return void
     */
    public function beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $table = $event->getSubject();
        $tableName = $table->getTable();
        
        $logData = [
            'trace_id' => $this->traceId,
            'action' => "{$tableName}.delete.attempt",
            'table' => $tableName,
            'entity_type' => $table->getAlias(),
            'entity_id' => $entity->id,
            'deleted_data' => $this->extractEntityData($entity),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Add request data
        if ($this->getConfig('includeRequestData') && $this->request) {
            $logData['request'] = [
                'ip' => $this->request->clientIp(),
                'user_agent' => $this->request->getHeader('User-Agent')[0] ?? 'Unknown',
                'method' => $this->request->getMethod()
            ];
        }

        Log::warning(json_encode($logData), $this->getConfig('logScopes'));
    }

    /**
     * After delete callback - logs successful deletion
     *
     * @param EventInterface $event Event
     * @param EntityInterface $entity Entity that was deleted
     * @param ArrayObject $options Options
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $table = $event->getSubject();
        $tableName = $table->getTable();
        
        $logData = [
            'trace_id' => $this->traceId,
            'action' => "{$tableName}.delete.success",
            'table' => $tableName,
            'entity_type' => $table->getAlias(),
            'entity_id' => $entity->id,
            'message' => "{$table->getAlias()} {$entity->id} deleted successfully",
            'deleted_entity' => $this->extractEntityData($entity),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Add request data
        if ($this->getConfig('includeRequestData') && $this->request) {
            $logData['request'] = [
                'ip' => $this->request->clientIp(),
                'user_agent' => $this->request->getHeader('User-Agent')[0] ?? 'Unknown'
            ];
        }

        Log::warning(json_encode($logData), $this->getConfig('logScopes'));
    }

    /**
     * Extract data from entity based on configuration
     *
     * @param EntityInterface $entity Entity to extract data from
     * @return array
     */
    protected function extractEntityData(EntityInterface $entity): array
    {
        $data = [];
        $fields = $this->getConfig('fields');
        $ignoreFields = $this->getConfig('ignoreFields');

        if (empty($fields)) {
            // If no specific fields configured, get all visible properties
            $fields = $entity->getVisible();
        }

        foreach ($fields as $field) {
            if (!in_array($field, $ignoreFields) && $entity->has($field)) {
                $value = $entity->get($field);
                // Convert objects to string representation
                if (is_object($value) && method_exists($value, '__toString')) {
                    $value = (string)$value;
                } elseif ($value instanceof \DateTime) {
                    $value = $value->format('Y-m-d H:i:s');
                }
                $data[$field] = $value;
            }
        }

        return $data;
    }

    /**
     * Calculate changes between two data sets
     *
     * @param array $original Original data
     * @param array $new New data
     * @return array
     */
    protected function calculateChanges(array $original, array $new): array
    {
        $changes = [];
        
        foreach ($new as $field => $newValue) {
            $originalValue = $original[$field] ?? null;
            
            // Type juggling for comparison
            if (is_numeric($originalValue) && is_numeric($newValue)) {
                $originalValue = (string)$originalValue;
                $newValue = (string)$newValue;
            }
            
            if ($originalValue !== $newValue) {
                $changes[$field] = [
                    'before' => $originalValue,
                    'after' => $newValue
                ];
            }
        }
        
        return $changes;
    }

    /**
     * Log custom action
     *
     * @param string $action Action name
     * @param EntityInterface $entity Entity
     * @param array $additionalData Additional data to log
     * @return void
     */
    public function logAction(string $action, EntityInterface $entity, array $additionalData = []): void
    {
        $table = $this->_table;
        $tableName = $table->getTable();
        
        $logData = [
            'trace_id' => $this->traceId,
            'action' => "{$tableName}.{$action}",
            'table' => $tableName,
            'entity_type' => $table->getAlias(),
            'entity_id' => $entity->id ?? null,
            'entity_data' => $this->extractEntityData($entity),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Merge additional data
        $logData = array_merge($logData, $additionalData);

        // Add request data
        if ($this->getConfig('includeRequestData') && $this->request) {
            $logData['request'] = [
                'ip' => $this->request->clientIp(),
                'user_agent' => $this->request->getHeader('User-Agent')[0] ?? 'Unknown'
            ];
        }

        Log::write($this->getConfig('logLevel'), json_encode($logData), $this->getConfig('logScopes'));
    }
}