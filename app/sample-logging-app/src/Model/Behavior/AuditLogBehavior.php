<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Event\EventInterface;
use Cake\Http\ServerRequest;
use Cake\Log\Log;
use Cake\ORM\Behavior;

/**
 * AuditLog behavior - centralized logging for all CRUD operations.
 *
 * Writes a human/JSON record to the 'audit' log scope (file -> Fluent Bit ->
 * OpenSearch) AND persists a structured row to the audit_logs table so the
 * in-app log viewer can reconstruct per-user and per-entity flows.
 */
class AuditLogBehavior extends Behavior
{
    /**
     * @var array
     */
    protected $_defaultConfig = [
        'logScopes' => ['audit', 'application'],
        'fields' => [],
        'ignoreFields' => ['created', 'modified', 'id', 'password'],
        // Sensitive fields encrypted BEFORE the line is written — OpenSearch
        // only ever stores ciphertext; the app decrypts on read (FieldCipher).
        // Managed at runtime via EncryptFieldsRegistry (config/encrypt_fields.json,
        // editable on the /encryption-fields page); this is only the fallback.
        'encryptFields' => ['email', 'description'],
        'logLevel' => 'info',
        'includeRequestData' => true,
        'includeSchema' => false,
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
     * Initialize behavior.
     *
     * @param array $config Configuration
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->traceId = uniqid('audit_', true);

        // The encrypted-field list is runtime-managed (config/encrypt_fields.json
        // via the /encryption-fields page) unless a table passed its own list.
        if (!isset($config['encryptFields'])) {
            $this->setConfig('encryptFields', \App\Service\EncryptFieldsRegistry::list(), false);
        }

        if (class_exists('\Cake\Http\ServerRequestFactory')) {
            $this->request = \Cake\Http\ServerRequestFactory::fromGlobals();
        }
    }

    /**
     * Resolve the acting user from Configure (set by AppController::beforeFilter).
     *
     * @return array{id: int|null, name: string}
     */
    private function actor(): array
    {
        $user = Configure::read('Audit.actor');

        return [
            'id' => $user['id'] ?? null,
            'name' => $user['name'] ?? 'system',
        ];
    }

    /**
     * The current login session id (published by AppController::beforeFilter).
     * This is the "maintained id" that stitches a whole session's actions
     * together across requests/servers. Null for CLI/console context.
     *
     * @return string|null
     */
    private function sessionId(): ?string
    {
        return Configure::read('Audit.session_id');
    }

    /**
     * Encrypt the configured sensitive fields, then write the audit line.
     * OpenSearch receives ciphertext for those fields; everything else
     * (ids, action, timestamps) stays plaintext so flows remain searchable.
     *
     * @param string $level Log level.
     * @param array $logData Payload.
     * @return void
     */
    private function writeLog(string $level, array $logData): void
    {
        $sensitive = (array)$this->getConfig('encryptFields');
        if ($sensitive) {
            $logData = \App\Service\FieldCipher::encryptFields($logData, $sensitive);
        }

        Log::write($level, json_encode($logData), $this->getConfig('logScopes'));
    }

    /**
     * Build the request metadata block.
     *
     * @return array
     */
    private function requestMeta(): array
    {
        if (!$this->getConfig('includeRequestData') || !$this->request) {
            return [];
        }

        return [
            'ip' => $this->request->clientIp(),
            'user_agent' => $this->request->getHeader('User-Agent')[0] ?? 'Unknown',
            'method' => $this->request->getMethod(),
            'url' => $this->request->getRequestTarget(),
        ];
    }

    /**
     * Before save - logs the attempt and stashes original/changed data for afterSave.
     *
     * @param \Cake\Event\EventInterface $event Event
     * @param \Cake\Datasource\EntityInterface $entity Entity being saved
     * @param \ArrayObject $options Options
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $table = $event->getSubject();
        $isNew = $entity->isNew();
        $action = $isNew ? 'create' : 'update';
        $tableName = $table->getTable();
        $actor = $this->actor();

        $options['_isNew'] = $isNew;

        $logData = [
            'trace_id' => $this->traceId,
            'session_id' => $this->sessionId(),
            'action' => "{$tableName}.{$action}.attempt",
            'table' => $tableName,
            'entity_type' => $table->getAlias(),
            'user_id' => $actor['id'],
            'user_name' => $actor['name'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if (!$isNew) {
            $originalData = $this->extractEntityData($table->get($entity->id));
            $newData = $this->extractEntityData($entity);
            $changes = $this->calculateChanges($originalData, $newData);

            $logData['entity_id'] = $entity->id;
            $logData['original_values'] = $originalData;
            $logData['new_values'] = $newData;
            $logData['changed_fields'] = array_keys($changes);
            $logData['changes'] = $changes;

            // Stash for afterSave (DB persistence).
            $options['_auditOriginal'] = $originalData;
            $options['_auditChanges'] = array_keys($changes);
        } else {
            $logData['new_values'] = $this->extractEntityData($entity);
        }

        $request = $this->requestMeta();
        if ($request) {
            $logData['request'] = $request;
        }

        $this->writeLog($this->getConfig('logLevel'), $logData);
    }

    /**
     * After save - logs success and persists a structured audit_logs row.
     *
     * @param \Cake\Event\EventInterface $event Event
     * @param \Cake\Datasource\EntityInterface $entity Entity that was saved
     * @param \ArrayObject $options Options
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $table = $event->getSubject();
        $wasNew = $options['_isNew'] ?? false;
        $action = $wasNew ? 'create' : 'update';
        $tableName = $table->getTable();
        $actor = $this->actor();
        $newData = $this->extractEntityData($entity);

        $logData = [
            'trace_id' => $this->traceId,
            'session_id' => $this->sessionId(),
            'action' => "{$tableName}.{$action}.success",
            'table' => $tableName,
            'entity_type' => $table->getAlias(),
            'entity_id' => $entity->id,
            'user_id' => $actor['id'],
            'user_name' => $actor['name'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if ($wasNew) {
            $logData['new_values'] = $newData;
            $logData['message'] = "New {$table->getAlias()} created with ID: {$entity->id}";
        } else {
            $logData['original_values'] = $options['_auditOriginal'] ?? [];
            $logData['new_values'] = $newData;
            $logData['changed_fields'] = $options['_auditChanges'] ?? [];
            $logData['message'] = "{$table->getAlias()} {$entity->id} updated successfully";
        }

        $request = $this->requestMeta();
        if ($request) {
            $logData['request'] = $request;
        }

        $level = $wasNew ? 'info' : 'notice';
        $this->writeLog($level, $logData);

        $this->recordToDatabase([
            'action' => "{$tableName}.{$action}",
            'table_name' => $tableName,
            'entity_id' => $entity->id,
            'user_id' => $actor['id'],
            'original_values' => $wasNew ? null : ($options['_auditOriginal'] ?? []),
            'new_values' => $newData,
            'changed_fields' => $wasNew ? null : ($options['_auditChanges'] ?? []),
        ]);
    }

    /**
     * Before delete - logs the deletion attempt.
     *
     * @param \Cake\Event\EventInterface $event Event
     * @param \Cake\Datasource\EntityInterface $entity Entity being deleted
     * @param \ArrayObject $options Options
     * @return void
     */
    public function beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $table = $event->getSubject();
        $tableName = $table->getTable();
        $actor = $this->actor();

        $logData = [
            'trace_id' => $this->traceId,
            'session_id' => $this->sessionId(),
            'action' => "{$tableName}.delete.attempt",
            'table' => $tableName,
            'entity_type' => $table->getAlias(),
            'entity_id' => $entity->id,
            'user_id' => $actor['id'],
            'user_name' => $actor['name'],
            'deleted_data' => $this->extractEntityData($entity),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $request = $this->requestMeta();
        if ($request) {
            $logData['request'] = $request;
        }

        $this->writeLog('warning', $logData);
    }

    /**
     * After delete - logs success and persists a structured audit_logs row.
     *
     * @param \Cake\Event\EventInterface $event Event
     * @param \Cake\Datasource\EntityInterface $entity Entity that was deleted
     * @param \ArrayObject $options Options
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $table = $event->getSubject();
        $tableName = $table->getTable();
        $actor = $this->actor();
        $deleted = $this->extractEntityData($entity);

        $logData = [
            'trace_id' => $this->traceId,
            'session_id' => $this->sessionId(),
            'action' => "{$tableName}.delete.success",
            'table' => $tableName,
            'entity_type' => $table->getAlias(),
            'entity_id' => $entity->id,
            'user_id' => $actor['id'],
            'user_name' => $actor['name'],
            'message' => "{$table->getAlias()} {$entity->id} deleted successfully",
            'deleted_entity' => $deleted,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $request = $this->requestMeta();
        if ($request) {
            $logData['request'] = $request;
        }

        $this->writeLog('warning', $logData);

        $this->recordToDatabase([
            'action' => "{$tableName}.delete",
            'table_name' => $tableName,
            'entity_id' => $entity->id,
            'user_id' => $actor['id'],
            'original_values' => $deleted,
            'new_values' => null,
            'changed_fields' => null,
        ]);
    }

    /**
     * Persist a structured row to the audit_logs table. Failures are swallowed
     * so auditing never breaks the underlying operation.
     *
     * @param array $row Row data (action, table_name, entity_id, user_id, ...).
     * @return void
     */
    private function recordToDatabase(array $row): void
    {
        try {
            $auditLogs = FactoryLocator::get('Table')->get('AuditLogs');

            $entity = $auditLogs->newEntity([
                'trace_id' => $this->traceId,
            'session_id' => $this->sessionId(),
                'action' => $row['action'],
                'table_name' => $row['table_name'],
                'entity_id' => $row['entity_id'] ?? null,
                'user_id' => $row['user_id'] ?? null,
                'ip_address' => $this->request ? $this->request->clientIp() : null,
                'user_agent' => $this->request ? ($this->request->getHeader('User-Agent')[0] ?? null) : null,
                'original_values' => isset($row['original_values']) ? json_encode($row['original_values']) : null,
                'new_values' => isset($row['new_values']) ? json_encode($row['new_values']) : null,
                'changed_fields' => isset($row['changed_fields']) ? json_encode($row['changed_fields']) : null,
            ]);

            // checkRules=false so a null user_id (anonymous) never blocks the audit row.
            $auditLogs->save($entity, ['checkRules' => false, 'atomic' => false]);
        } catch (\Throwable $e) {
            Log::error('Audit DB write failed: ' . $e->getMessage(), ['audit']);
        }
    }

    /**
     * Extract data from entity based on configuration.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity to extract data from
     * @return array
     */
    protected function extractEntityData(EntityInterface $entity): array
    {
        $data = [];
        $fields = $this->getConfig('fields');
        $ignoreFields = $this->getConfig('ignoreFields');

        if (empty($fields)) {
            $fields = $entity->getVisible();
        }

        foreach ($fields as $field) {
            if (!in_array($field, $ignoreFields) && $entity->has($field)) {
                $value = $entity->get($field);
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
     * Calculate changes between two data sets.
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

            if (is_numeric($originalValue) && is_numeric($newValue)) {
                $originalValue = (string)$originalValue;
                $newValue = (string)$newValue;
            }

            if ($originalValue !== $newValue) {
                $changes[$field] = [
                    'before' => $originalValue,
                    'after' => $newValue,
                ];
            }
        }

        return $changes;
    }
}
