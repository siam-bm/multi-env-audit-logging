<?php
declare(strict_types=1);

namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\Log\Log;

/**
 * AuditLog component for controller-level logging
 */
class AuditLogComponent extends Component
{
    /**
     * Default configuration
     *
     * @var array
     */
    protected $_defaultConfig = [
        'logScopes' => ['audit', 'controller'],
        'logLevel' => 'info',
        'includeQueryParams' => true,
        'includePostData' => true
    ];

    /**
     * @var string
     */
    private $traceId;

    /**
     * Initialize component
     *
     * @param array $config Configuration
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->traceId = uniqid('ctrl_', true);
    }

    /**
     * Log controller action
     *
     * @param string $action Action name
     * @param array $data Additional data to log
     * @return void
     */
    public function logAction(string $action, array $data = []): void
    {
        $controller = $this->getController();
        $request = $controller->getRequest();
        
        $logData = [
            'trace_id' => $this->traceId,
            'action' => $action,
            'controller' => $controller->getName(),
            'method' => $request->getMethod(),
            'url' => $request->getRequestTarget(),
            'ip' => $request->clientIp(),
            'user_agent' => $request->getHeader('User-Agent')[0] ?? 'Unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Include query parameters if configured
        if ($this->getConfig('includeQueryParams')) {
            $queryParams = $request->getQueryParams();
            if (!empty($queryParams)) {
                $logData['query_params'] = $queryParams;
            }
        }

        // Include POST data if configured (excluding sensitive fields)
        if ($this->getConfig('includePostData') && $request->is(['post', 'put', 'patch'])) {
            $postData = $request->getData();
            // Remove sensitive fields
            unset($postData['password'], $postData['confirm_password'], $postData['token']);
            if (!empty($postData)) {
                $logData['post_data'] = $postData;
            }
        }

        // Merge additional data
        $logData = array_merge($logData, $data);

        Log::write($this->getConfig('logLevel'), json_encode($logData), $this->getConfig('logScopes'));
    }

    /**
     * Log access attempt
     *
     * @param string $resource Resource being accessed
     * @param bool $granted Whether access was granted
     * @param array $additionalData Additional data
     * @return void
     */
    public function logAccess(string $resource, bool $granted, array $additionalData = []): void
    {
        $controller = $this->getController();
        $request = $controller->getRequest();
        
        $logData = [
            'trace_id' => $this->traceId,
            'action' => 'access.' . ($granted ? 'granted' : 'denied'),
            'resource' => $resource,
            'controller' => $controller->getName(),
            'ip' => $request->clientIp(),
            'user_agent' => $request->getHeader('User-Agent')[0] ?? 'Unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $logData = array_merge($logData, $additionalData);

        $level = $granted ? 'info' : 'warning';
        Log::write($level, json_encode($logData), $this->getConfig('logScopes'));
    }

    /**
     * Log error
     *
     * @param string $message Error message
     * @param \Exception|null $exception Exception object
     * @param array $additionalData Additional data
     * @return void
     */
    public function logError(string $message, ?\Exception $exception = null, array $additionalData = []): void
    {
        $controller = $this->getController();
        $request = $controller->getRequest();
        
        $logData = [
            'trace_id' => $this->traceId,
            'action' => 'error',
            'message' => $message,
            'controller' => $controller->getName(),
            'url' => $request->getRequestTarget(),
            'ip' => $request->clientIp(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if ($exception) {
            $logData['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        $logData = array_merge($logData, $additionalData);

        Log::error(json_encode($logData), $this->getConfig('logScopes'));
    }

    /**
     * Log performance metrics
     *
     * @param string $operation Operation name
     * @param float $duration Duration in seconds
     * @param array $additionalData Additional data
     * @return void
     */
    public function logPerformance(string $operation, float $duration, array $additionalData = []): void
    {
        $controller = $this->getController();
        
        $logData = [
            'trace_id' => $this->traceId,
            'action' => 'performance',
            'operation' => $operation,
            'controller' => $controller->getName(),
            'duration_seconds' => $duration,
            'duration_ms' => round($duration * 1000, 2),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $logData = array_merge($logData, $additionalData);

        // Log as warning if operation took more than 1 second
        $level = $duration > 1.0 ? 'warning' : 'info';
        Log::write($level, json_encode($logData), $this->getConfig('logScopes'));
    }

    /**
     * Get trace ID
     *
     * @return string
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }
}