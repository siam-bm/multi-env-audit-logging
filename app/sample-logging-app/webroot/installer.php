<?php
/**
 * Web-based Installer for CakePHP Sample Logging App
 * Configures database and Fluent Bit connection to 10.0.2.30
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('ROOT', dirname(dirname(__FILE__)));
define('CONFIG_DIR', ROOT . '/config');
define('LOGS_DIR', ROOT . '/logs');
define('TMP_DIR', ROOT . '/tmp');

// Central server configuration (hardcoded for 10.0.2.30)
define('CENTRAL_SERVER', '10.0.2.30');
define('FLUENT_BIT_HTTP_PORT', '8888');
define('FLUENT_BIT_FORWARD_PORT', '24224');

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$message = '';
$error = '';
$success = '';

// Check if already installed
if (file_exists(CONFIG_DIR . '/app_local.php') && $step == 1) {
    $message = 'Application appears to be already installed. <a href="installer.php?step=1&force=1">Force reinstall</a> or <a href="/">Go to application</a>';
    if (!isset($_GET['force'])) {
        $step = 0;
    }
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 2:
            // Test database connection
            $db_host = $_POST['db_host'] ?? 'localhost';
            $db_name = $_POST['db_name'] ?? '';
            $db_user = $_POST['db_user'] ?? '';
            $db_pass = $_POST['db_pass'] ?? '';
            $db_port = $_POST['db_port'] ?? '3306';
            
            try {
                $dsn = "mysql:host=$db_host;port=$db_port";
                $pdo = new PDO($dsn, $db_user, $db_pass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Create database if not exists
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                
                // Store in session for next step
                $_SESSION['db_config'] = [
                    'host' => $db_host,
                    'name' => $db_name,
                    'user' => $db_user,
                    'pass' => $db_pass,
                    'port' => $db_port
                ];
                
                header('Location: installer.php?step=3');
                exit;
            } catch (Exception $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
            }
            break;
            
        case 3:
            // Configure application
            $app_name = $_POST['app_name'] ?? 'Sample Logging App';
            $app_domain = $_POST['app_domain'] ?? $_SERVER['HTTP_HOST'];
            $enable_logging = isset($_POST['enable_logging']);
            
            $_SESSION['app_config'] = [
                'name' => $app_name,
                'domain' => $app_domain,
                'enable_logging' => $enable_logging
            ];
            
            header('Location: installer.php?step=4');
            exit;
            break;
            
        case 4:
            // Write configuration files and finalize
            if (installApplication()) {
                header('Location: installer.php?step=5');
                exit;
            } else {
                $error = 'Installation failed. Please check permissions and try again.';
            }
            break;
    }
}

function installApplication() {
    $db = $_SESSION['db_config'];
    $app = $_SESSION['app_config'];
    
    // Generate security salt
    $salt = bin2hex(random_bytes(32));
    
    // Create app_local.php
    $config = <<<PHP
<?php
return [
    'debug' => false,
    'Security' => [
        'salt' => '$salt',
    ],
    'Datasources' => [
        'default' => [
            'host' => '{$db['host']}',
            'port' => '{$db['port']}',
            'username' => '{$db['user']}',
            'password' => '{$db['pass']}',
            'database' => '{$db['name']}',
            'url' => env('DATABASE_URL', null),
        ],
    ],
    'Log' => [
        'debug' => [
            'className' => 'Cake\Log\Engine\FileLog',
            'path' => LOGS,
            'file' => 'app.json',
            'scopes' => ['audit', 'application'],
            'levels' => ['notice', 'info', 'debug'],
        ],
        'error' => [
            'className' => 'Cake\Log\Engine\FileLog',
            'path' => LOGS,
            'file' => 'error.json',
            'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
        ],
    ],
    'CentralLogging' => [
        'server' => '" . CENTRAL_SERVER . "',
        'http_port' => " . FLUENT_BIT_HTTP_PORT . ",
        'forward_port' => " . FLUENT_BIT_FORWARD_PORT . ",
        'enabled' => " . ($app['enable_logging'] ? 'true' : 'false') . ",
    ],
];
PHP;
    
    // Write configuration
    if (!file_put_contents(CONFIG_DIR . '/app_local.php', $config)) {
        return false;
    }
    
    // Create Fluent Bit configuration
    $fluentbit_config = <<<CONF
[SERVICE]
    Flush        5
    Daemon       off
    Log_Level    info

[INPUT]
    Name              tail
    Path              " . LOGS_DIR . "/app.json
    Parser            json
    Tag               remote.{$app['domain']}
    Refresh_Interval  5
    Skip_Empty_Lines  On
    DB                /tmp/sample-app.db

[FILTER]
    Name record_modifier
    Match remote.*
    Record hostname {$app['domain']}
    Record application {$app['name']}
    Record environment production
    Record central_server " . CENTRAL_SERVER . "

[OUTPUT]
    Name    forward
    Match   remote.*
    Host    " . CENTRAL_SERVER . "
    Port    " . FLUENT_BIT_FORWARD_PORT . "

[OUTPUT]
    Name    http
    Match   remote.*
    Host    " . CENTRAL_SERVER . "
    Port    " . FLUENT_BIT_HTTP_PORT . "
    URI     /
    Format  json
CONF;
    
    file_put_contents(CONFIG_DIR . '/fluent-bit-app.conf', $fluentbit_config);
    
    // Create .env file
    $env_content = <<<ENV
#!/usr/bin/env bash
export APP_NAME="{$app['name']}"
export DEBUG="false"
export DATABASE_URL="mysql://{$db['user']}:{$db['pass']}@{$db['host']}:{$db['port']}/{$db['name']}"
export CENTRAL_LOG_SERVER="" . CENTRAL_SERVER . ""
export FLUENT_BIT_HTTP_PORT="" . FLUENT_BIT_HTTP_PORT . ""
export FLUENT_BIT_FORWARD_PORT="" . FLUENT_BIT_FORWARD_PORT . ""
ENV;
    
    file_put_contents(ROOT . '/.env', $env_content);
    
    // Create necessary directories
    $directories = [
        LOGS_DIR,
        TMP_DIR,
        TMP_DIR . '/cache',
        TMP_DIR . '/cache/models',
        TMP_DIR . '/cache/persistent',
        TMP_DIR . '/sessions',
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        chmod($dir, 0777);
    }
    
    // Run database migrations
    $output = [];
    $return_var = 0;
    chdir(ROOT);
    exec('php bin/cake.php migrations migrate 2>&1', $output, $return_var);
    
    // Create Apache .htaccess if not exists
    if (!file_exists(ROOT . '/webroot/.htaccess')) {
        $htaccess = <<<HTACCESS
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
HTACCESS;
        file_put_contents(ROOT . '/webroot/.htaccess', $htaccess);
    }
    
    // Send test log to central server
    $test_log = json_encode([
        'timestamp' => date('c'),
        'level' => 'info',
        'message' => 'Application installed successfully',
        'hostname' => $app['domain'],
        'action' => 'installer.complete'
    ]);
    
    file_put_contents(LOGS_DIR . '/app.json', $test_log . "\n", FILE_APPEND);
    
    // Test connection to central server
    $ch = curl_init('http://' . CENTRAL_SERVER . ':' . FLUENT_BIT_HTTP_PORT . '/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $test_log);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
    
    return true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CakePHP App Installer - Centralized Logging Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .progress {
            display: flex;
            padding: 20px 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .progress-step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .progress-step::after {
            content: '';
            position: absolute;
            top: 15px;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #dee2e6;
            z-index: 1;
        }
        .progress-step:last-child::after { display: none; }
        .progress-step.active .step-number,
        .progress-step.completed .step-number {
            background: #667eea;
            color: white;
        }
        .progress-step.completed .step-number {
            background: #28a745;
        }
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: white;
            border: 2px solid #dee2e6;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 2;
            margin-bottom: 5px;
        }
        .step-label {
            font-size: 12px;
            color: #6c757d;
        }
        .content {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #495057;
        }
        input[type="text"],
        input[type="password"],
        input[type="number"],
        select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ced4da;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-check {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        .form-check input[type="checkbox"] {
            width: auto;
            margin-right: 10px;
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .central-server {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        .central-server h3 {
            color: #667eea;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .central-server p {
            margin: 5px 0;
            font-size: 14px;
            color: #495057;
        }
        .central-server code {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
        .requirements {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .requirements h3 {
            color: #856404;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .requirements ul {
            margin-left: 20px;
            color: #856404;
        }
        .success-icon {
            font-size: 60px;
            color: #28a745;
            text-align: center;
            margin-bottom: 20px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            font-size: 12px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 CakePHP App Installer</h1>
            <p>Centralized Logging with Fluent Bit @ <?php echo CENTRAL_SERVER; ?></p>
        </div>

        <div class="progress">
            <div class="progress-step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">
                <div class="step-number">1</div>
                <div class="step-label">Check</div>
            </div>
            <div class="progress-step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">
                <div class="step-number">2</div>
                <div class="step-label">Database</div>
            </div>
            <div class="progress-step <?php echo $step >= 3 ? 'active' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?>">
                <div class="step-number">3</div>
                <div class="step-label">Configure</div>
            </div>
            <div class="progress-step <?php echo $step >= 4 ? 'active' : ''; ?> <?php echo $step > 4 ? 'completed' : ''; ?>">
                <div class="step-number">4</div>
                <div class="step-label">Install</div>
            </div>
            <div class="progress-step <?php echo $step >= 5 ? 'active' : ''; ?>">
                <div class="step-number">5</div>
                <div class="step-label">Complete</div>
            </div>
        </div>

        <div class="content">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="alert alert-info"><?php echo $message; ?></div>
            <?php endif; ?>

            <?php if ($step == 0): ?>
                <!-- Already installed -->
            <?php elseif ($step == 1): ?>
                <h2>Welcome to the Installer</h2>
                <p style="margin-bottom: 20px;">This installer will help you set up the CakePHP application with centralized logging.</p>
                
                <div class="central-server">
                    <h3>📡 Central Logging Server</h3>
                    <p>Server: <code><?php echo CENTRAL_SERVER; ?></code></p>
                    <p>Fluent Bit HTTP: <code><?php echo CENTRAL_SERVER . ':' . FLUENT_BIT_HTTP_PORT; ?></code></p>
                    <p>Fluent Bit Forward: <code><?php echo CENTRAL_SERVER . ':' . FLUENT_BIT_FORWARD_PORT; ?></code></p>
                    <p>OpenSearch Dashboard: <code>http://<?php echo CENTRAL_SERVER; ?>:5601</code></p>
                </div>
                
                <div class="requirements">
                    <h3>⚠️ Requirements Check</h3>
                    <ul>
                        <li>PHP <?php echo phpversion(); ?> <?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? '✓' : '✗'; ?></li>
                        <li>PDO MySQL <?php echo extension_loaded('pdo_mysql') ? '✓' : '✗'; ?></li>
                        <li>JSON <?php echo extension_loaded('json') ? '✓' : '✗'; ?></li>
                        <li>cURL <?php echo extension_loaded('curl') ? '✓' : '✗'; ?></li>
                        <li>Writable directories <?php echo is_writable(ROOT) ? '✓' : '✗'; ?></li>
                    </ul>
                </div>
                
                <a href="installer.php?step=2" class="btn">Continue to Database Setup →</a>
                
            <?php elseif ($step == 2): ?>
                <h2>Database Configuration</h2>
                <p style="margin-bottom: 20px;">Configure your MySQL database connection.</p>
                
                <form method="POST">
                    <div class="alert alert-info">
                        <strong>Note:</strong> For central database at 10.0.2.30, contact your administrator for credentials.
                    </div>
                    
                    <div class="form-group">
                        <label for="db_host">Database Host</label>
                        <input type="text" id="db_host" name="db_host" value="10.0.2.30" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_port">Database Port</label>
                        <input type="number" id="db_port" name="db_port" value="3306" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_name">Database Name</label>
                        <input type="text" id="db_name" name="db_name" value="sample_logging_db" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_user">Database Username</label>
                        <input type="text" id="db_user" name="db_user" value="root" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_pass">Database Password</label>
                        <input type="password" id="db_pass" name="db_pass" placeholder="Enter password">
                    </div>
                    
                    <button type="submit" class="btn">Test Connection & Continue →</button>
                </form>
                
            <?php elseif ($step == 3): ?>
                <h2>Application Configuration</h2>
                <p style="margin-bottom: 20px;">Configure your application settings.</p>
                
                <form method="POST">
                    <div class="form-group">
                        <label for="app_name">Application Name</label>
                        <input type="text" id="app_name" name="app_name" value="Sample Logging App" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="app_domain">Application Domain</label>
                        <input type="text" id="app_domain" name="app_domain" value="<?php echo $_SERVER['HTTP_HOST']; ?>" required>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" id="enable_logging" name="enable_logging" checked>
                        <label for="enable_logging">Enable centralized logging to <?php echo CENTRAL_SERVER; ?></label>
                    </div>
                    
                    <div class="alert alert-info">
                        <strong>Note:</strong> Logs will be automatically forwarded to the central server at <?php echo CENTRAL_SERVER; ?> and will be viewable in OpenSearch Dashboard.
                    </div>
                    
                    <button type="submit" class="btn">Continue to Installation →</button>
                </form>
                
            <?php elseif ($step == 4): ?>
                <h2>Installing Application...</h2>
                <p style="margin-bottom: 20px;">Please wait while we set up your application.</p>
                
                <div class="alert alert-info">
                    <strong>Installing...</strong> This may take a few moments.
                </div>
                
                <form method="POST" id="install-form">
                    <button type="submit" class="btn">Install Now</button>
                </form>
                
                <script>
                    // Auto-submit form
                    setTimeout(function() {
                        document.getElementById('install-form').submit();
                    }, 1000);
                </script>
                
            <?php elseif ($step == 5): ?>
                <div class="success-icon">✅</div>
                <h2 style="text-align: center; margin-bottom: 20px;">Installation Complete!</h2>
                
                <div class="alert alert-success">
                    Your application has been successfully installed and configured.
                </div>
                
                <div class="central-server">
                    <h3>📊 View Your Logs</h3>
                    <p>OpenSearch Dashboard: <a href="http://<?php echo CENTRAL_SERVER; ?>:5601" target="_blank">http://<?php echo CENTRAL_SERVER; ?>:5601</a></p>
                    <p>Index Pattern: <code>logs-remote</code></p>
                    <p>Fluent Bit Metrics: <a href="http://<?php echo CENTRAL_SERVER; ?>:2020" target="_blank">http://<?php echo CENTRAL_SERVER; ?>:2020</a></p>
                </div>
                
                <div style="text-align: center; margin-top: 30px;">
                    <a href="/" class="btn">Go to Application →</a>
                </div>
                
                <div class="alert alert-info" style="margin-top: 20px;">
                    <strong>Important:</strong> Please delete this installer file (webroot/installer.php) for security reasons.
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>CakePHP Sample Logging App Installer | Centralized Logging @ <?php echo CENTRAL_SERVER; ?></p>
        </div>
    </div>
</body>
</html>