<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\Log\Log;

/**
 * Improved Audit Log Behavior with environment awareness
 */
class ImprovedAuditLogBehavior extends Behavior
{
    protected $_defaultConfig = [
        'fields' => [],
        'excludeFields' => ['modified', 'created'],
        'includeEnvironment' => true,
        'includeSystem' => true,
        'includeTrace' => true
    ];

    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $isNew = $entity->isNew();
        $options['_audit_is_new'] = $isNew;
        
        if (!$isNew) {
            // Store original values for comparison
            $dirtyFields = $entity->getDirty();
            $originalValues = [];
            
            foreach ($dirtyFields as $field) {
                if (!in_array($field, $this->getConfig('excludeFields'))) {
                    $originalValues[$field] = $entity->getOriginal($field);
                }
            }
            
            $options['_audit_original_values'] = $originalValues;
        }
    }

    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $table = $event->getSubject();
        $tableName = $table->getTable();
        $isNew = $options['_audit_is_new'] ?? true;
        
        // Build audit log payload with environment
        $auditData = [
            '@timestamp' => date('c'),
            'environment' => env('APP_ENV', 'unknown'),
            'system' => env('SYSTEM_NAME', 'cakephp-audit'),
            'action' => $isNew ? "{$tableName}.create" : "{$tableName}.update",
            'entity_type' => $tableName,
            'entity_id' => $entity->id,
            'actor_id' => $this->getActorId(),
            'actor_type' => $this->getActorType(),
            'ip_address' => $this->getIpAddress(),
            'user_agent' => $this->getUserAgent(),
            'trace_id' => $this->generateTraceId(),
            'changes' => []
        ];

        // Add changes with before/after values
        if (!$isNew && isset($options['_audit_original_values'])) {
            $changes = [];
            foreach ($options['_audit_original_values'] as $field => $originalValue) {
                $changes[$field] = [
                    'before' => $this->sanitizeValue($originalValue),
                    'after' => $this->sanitizeValue($entity->get($field))
                ];
            }
            $auditData['changes'] = $changes;
        } elseif ($isNew) {
            // For new records, just record the values
            $auditData['changes'] = $this->extractEntityData($entity);
        }

        // Log to JSON file for Fluent Bit
        $this->writeAuditLog($auditData);
    }

    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $table = $event->getSubject();
        $tableName = $table->getTable();
        
        $auditData = [
            '@timestamp' => date('c'),
            'environment' => env('APP_ENV', 'unknown'),
            'system' => env('SYSTEM_NAME', 'cakephp-audit'),
            'action' => "{$tableName}.delete",
            'entity_type' => $tableName,
            'entity_id' => $entity->id,
            'actor_id' => $this->getActorId(),
            'actor_type' => $this->getActorType(),
            'ip_address' => $this->getIpAddress(),
            'user_agent' => $this->getUserAgent(),
            'trace_id' => $this->generateTraceId(),
            'deleted_data' => $this->extractEntityData($entity)
        ];

        $this->writeAuditLog($auditData);
    }

    /**
     * Write audit log to JSON file
     */
    protected function writeAuditLog(array $data): void
    {
        $logFile = LOGS . 'audit.json';
        $json = json_encode($data) . "\n";
        
        // Write to file for Fluent Bit to pick up
        file_put_contents($logFile, $json, FILE_APPEND | LOCK_EX);
        
        // Also log via CakePHP logger
        Log::write('info', json_encode($data), ['scope' => 'audit']);
    }

    /**
     * Extract entity data for logging
     */
    protected function extractEntityData(EntityInterface $entity): array
    {
        $data = [];
        $fields = $this->getConfig('fields');
        
        if (empty($fields)) {
            $fields = $entity->getVisible();
        }
        
        foreach ($fields as $field) {
            if (!in_array($field, $this->getConfig('excludeFields'))) {
                $data[$field] = $this->sanitizeValue($entity->get($field));
            }
        }
        
        return $data;
    }

    /**
     * Sanitize sensitive values
     */
    protected function sanitizeValue($value)
    {
        // Don't log passwords or tokens
        if (is_string($value) && (
            stripos($value, 'password') !== false ||
            stripos($value, 'token') !== false ||
            stripos($value, 'secret') !== false
        )) {
            return '***REDACTED***';
        }
        
        return $value;
    }

    /**
     * Get current actor ID (user)
     */
    protected function getActorId()
    {
        if (isset($_SESSION['Auth']['User']['id'])) {
            return $_SESSION['Auth']['User']['id'];
        }
        return null;
    }

    /**
     * Get actor type
     */
    protected function getActorType(): string
    {
        if (isset($_SESSION['Auth']['User']['role'])) {
            return $_SESSION['Auth']['User']['role'];
        }
        return 'system';
    }

    /**
     * Get client IP address
     */
    protected function getIpAddress(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    }

    /**
     * Get user agent
     */
    protected function getUserAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Generate unique trace ID for request tracking
     */
    protected function generateTraceId(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}