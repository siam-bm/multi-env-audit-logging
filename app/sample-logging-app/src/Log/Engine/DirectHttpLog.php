<?php
declare(strict_types=1);

namespace App\Log\Engine;

use Cake\Log\Engine\BaseLog;

/**
 * DirectHttpLog - Sends logs directly to Fluent Bit HTTP endpoint
 * Works on any platform (Windows, Linux, etc.)
 */
class DirectHttpLog extends BaseLog
{
    /**
     * Default configuration
     */
    protected $_defaultConfig = [
        'server' => '10.0.2.30',
        'port' => '8888',
        'timeout' => 2,
        'levels' => [],
        'scopes' => [],
        'fallback' => true  // Also write to local file if HTTP fails
    ];

    /**
     * Log method - sends logs to central server
     */
    public function log($level, $message, array $context = []): void
    {
        // Get server info
        $hostname = gethostname();
        $serverIp = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '10.0.2.28';
        
        // Build log data
        $logData = [
            '@timestamp' => date('c'),
            'timestamp' => date('Y-m-d H:i:s'),
            'hostname' => $hostname,
            'server_ip' => $serverIp,
            'level' => $level,
            'message' => $message,
            'application' => 'sample-logging-app',
            'environment' => 'production',
            'platform' => PHP_OS,
            'php_version' => PHP_VERSION
        ];
        
        // Merge context data
        if (!empty($context)) {
            $logData = array_merge($logData, $context);
        }
        
        // Try to send to remote server
        $sent = $this->sendToRemote($logData);
        
        // Fallback to local file if enabled and send failed
        if ($this->getConfig('fallback') && !$sent) {
            $this->writeToLocalFile($logData);
        }
    }

    /**
     * Send log data to remote Fluent Bit server
     */
    protected function sendToRemote(array $data): bool
    {
        $url = sprintf('http://%s:%s/', 
            $this->getConfig('server'), 
            $this->getConfig('port')
        );
        
        // Ensure we have required fields for Fluent Bit
        if (!isset($data['@timestamp'])) {
            $data['@timestamp'] = date('c');
        }
        
        $json = json_encode($data);
        
        // Use different method based on what's available
        if (function_exists('curl_init')) {
            return $this->sendViaCurl($url, $json);
        } elseif (ini_get('allow_url_fopen')) {
            return $this->sendViaFileGetContents($url, $json);
        } else {
            return $this->sendViaSocket($data);
        }
    }

    /**
     * Send via cURL (most reliable)
     */
    protected function sendViaCurl(string $url, string $json): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->getConfig('timeout'),
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode >= 200 && $httpCode < 300);
    }

    /**
     * Send via file_get_contents (fallback method)
     */
    protected function sendViaFileGetContents(string $url, string $json): bool
    {
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" .
                           "Content-Length: " . strlen($json) . "\r\n",
                'content' => $json,
                'timeout' => $this->getConfig('timeout')
            ]
        ];
        
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        return $result !== false;
    }

    /**
     * Send via raw socket (last resort)
     */
    protected function sendViaSocket(array $data): bool
    {
        $server = $this->getConfig('server');
        $port = $this->getConfig('port');
        
        $socket = @fsockopen($server, $port, $errno, $errstr, 1);
        if (!$socket) {
            return false;
        }
        
        $json = json_encode($data);
        $length = strlen($json);
        
        $request = "POST / HTTP/1.1\r\n";
        $request .= "Host: $server:$port\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Content-Length: $length\r\n";
        $request .= "Connection: close\r\n\r\n";
        $request .= $json;
        
        fwrite($socket, $request);
        fclose($socket);
        
        return true;
    }

    /**
     * Write to local file as fallback
     */
    protected function writeToLocalFile(array $data): void
    {
        $logFile = LOGS . 'app.json';
        $json = json_encode($data) . "\n";
        
        // Use file_put_contents with LOCK_EX for thread safety
        @file_put_contents($logFile, $json, FILE_APPEND | LOCK_EX);
    }
}