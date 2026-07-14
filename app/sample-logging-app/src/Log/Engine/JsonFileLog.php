<?php
declare(strict_types=1);

namespace App\Log\Engine;

use Cake\Log\Engine\FileLog;

/**
 * JSON File Logger for Centralized Logging
 * Outputs logs in JSON format for Fluent Bit to process
 */
class JsonFileLog extends FileLog
{
    /**
     * Write log entry in JSON format
     *
     * @param mixed $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        $logData = [
            'timestamp' => date('Y-m-d\TH:i:s.000\Z'),
            'level' => $level,
            'message' => $message,
            'service' => 'sample-logging-app',
            'environment' => env('APP_ENV', 'development'),
            'host' => gethostname(),
        ];

        // Add request context if available
        if (!empty($_SERVER['REQUEST_URI'])) {
            $logData['request'] = [
                'url' => $_SERVER['REQUEST_URI'] ?? null,
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ];
        }

        // Add user context if available
        if (isset($context['user_id'])) {
            $logData['user_id'] = $context['user_id'];
            unset($context['user_id']);
        }

        // Add trace ID for distributed tracing
        if (isset($context['trace_id'])) {
            $logData['trace_id'] = $context['trace_id'];
            unset($context['trace_id']);
        } else {
            // Generate trace ID if not present
            $logData['trace_id'] = uniqid('trace_', true);
        }

        // Add any additional context
        if (!empty($context)) {
            $logData['context'] = $context;
        }

        // Encrypt the registered sensitive fields in THIS envelope too (the
        // audit behavior encrypts its own payload, but request.ip/user_agent
        // and controller context are added here and would otherwise leak).
        $sensitive = \App\Service\EncryptFieldsRegistry::list();
        if ($sensitive) {
            $logData = \App\Service\FieldCipher::encryptFields($logData, $sensitive);
        }

        // Write JSON to file
        $output = json_encode($logData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filename = $this->_getFilename($level);
        $pathname = $this->_path . $filename;
        
        $mask = $this->_config['mask'];
        if (!empty($mask)) {
            $exists = file_exists($pathname);
            file_put_contents($pathname, $output . "\n", FILE_APPEND);
            
            if (!$exists && !chmod($pathname, (int)$mask)) {
                // Failed to change permissions
            }
        } else {
            file_put_contents($pathname, $output . "\n", FILE_APPEND);
        }
    }

    /**
     * Get the filename for JSON logs
     *
     * @param string $level The log level
     * @return string
     */
    protected function _getFilename($level): string
    {
        return $this->_config['file'] ?? 'app.json';
    }
}