# Sample Logging App - Quick Setup Guide

## Overview
This CakePHP application demonstrates centralized logging with automatic forwarding to a central Fluent Bit server at **10.0.2.30**. All CRUD operations are automatically logged with detailed audit trails.

## Architecture
```
┌──────────────────┐         ┌─────────────────────┐         ┌──────────────────┐
│   Your App       │  ──►    │  Fluent Bit @ 10.0.2.30   │  ──►  │   OpenSearch    │
│  (This Server)   │         │  (Central Collector)       │       │  (Dashboard)     │
└──────────────────┘         └─────────────────────┘         └──────────────────┘
```

## Quick Install (Automated)

### Prerequisites
- RHEL/CentOS/Rocky Linux 8+ or Ubuntu 20.04+
- Root or sudo access
- Network connectivity to 10.0.2.30

### One-Line Installation
```bash
curl -sSL https://raw.githubusercontent.com/yourrepo/sample-logging-app/main/installer.sh | sudo bash
```

### Or Manual Installation
```bash
# 1. Make installer executable
chmod +x installer.sh

# 2. Run installer as root
sudo ./installer.sh
```

The installer will:
- ✅ Check and install prerequisites (PHP, Apache, Composer)
- ✅ Configure database connection
- ✅ Set up log forwarding to 10.0.2.30
- ✅ Configure Apache virtual host
- ✅ Set proper permissions
- ✅ Test connectivity to central server

## Manual Setup

### Step 1: Clone Repository
```bash
cd /opt/codes
git clone https://github.com/yourrepo/sample-logging-app.git
cd sample-logging-app
```

### Step 2: Install Dependencies
```bash
composer install
```

### Step 3: Database Configuration
```bash
# Create database
mysql -u root -p -e "CREATE DATABASE sample_logging_db;"

# Configure connection in config/app_local.php
cp config/app_local.example.php config/app_local.php
# Edit database settings in app_local.php
```

### Step 4: Run Migrations
```bash
bin/cake migrations migrate
```

### Step 5: Configure Log Forwarding

Create `/opt/codes/sample-logging-app/config/fluent-bit-client.conf`:
```ini
[SERVICE]
    Flush        5
    Log_Level    info

[INPUT]
    Name              tail
    Path              /opt/codes/sample-logging-app/logs/app.json
    Parser            json
    Tag               remote.app
    Refresh_Interval  5

[OUTPUT]
    Name    forward
    Match   remote.app
    Host    10.0.2.30
    Port    24224

[OUTPUT]
    Name    http
    Match   remote.app
    Host    10.0.2.30
    Port    8888
    URI     /
    Format  json
```

### Step 6: Set Permissions
```bash
sudo chown -R apache:apache logs tmp
sudo chmod -R 777 logs tmp
sudo chcon -R -t httpd_sys_rw_content_t logs tmp
```

### Step 7: Configure Apache
```apache
<VirtualHost *:80>
    ServerName sample-app.local
    DocumentRoot /opt/codes/sample-logging-app/webroot
    
    <Directory /opt/codes/sample-logging-app/webroot>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Step 8: Start Services
```bash
sudo systemctl restart httpd
sudo systemctl enable httpd
```

## Testing the Setup

### 1. Test Log Forwarding
```bash
# Send test log
echo '{"level":"info","message":"Test from $(hostname)","timestamp":"'$(date -u +%Y-%m-%dT%H:%M:%SZ)'"}' >> logs/app.json

# Check if received at central server
curl -X GET "http://10.0.2.30:9200/logs-remote/_search?q=message:Test"
```

### 2. Test CRUD Operations
```bash
# Access the application
curl http://your-server/products

# Create a product (logs will be automatically sent)
curl -X POST http://your-server/products/add \
  -d "name=Test Product&price=99.99&quantity=10&status=active"
```

### 3. View Logs in OpenSearch
1. Open http://10.0.2.30:5601 (OpenSearch Dashboards)
2. Go to "Discover"
3. Select `logs-remote` index pattern
4. View real-time logs from your application

## Features

### Automatic Logging
The application automatically logs:
- **CRUD Operations**: Create, Read, Update, Delete
- **Audit Trail**: Before/after values for all changes
- **User Actions**: IP addresses, user agents, timestamps
- **Field-Level Changes**: Exactly what fields were modified
- **Performance Metrics**: Operation durations
- **Errors**: Exceptions with stack traces

### Log Format
```json
{
  "trace_id": "audit_xyz123",
  "action": "products.update.success",
  "table": "products",
  "entity_id": 1,
  "changes": {
    "price": {
      "before": 99.99,
      "after": 149.99
    }
  },
  "request": {
    "ip": "192.168.1.100",
    "user_agent": "Mozilla/5.0"
  },
  "timestamp": "2024-01-15T10:30:00Z"
}
```

## Configuration Files

### Environment Variables (.env)
```bash
# Central Logging Server
export CENTRAL_LOG_SERVER="10.0.2.30"
export FLUENT_BIT_HTTP_PORT="8888"
export FLUENT_BIT_FORWARD_PORT="24224"
```

### Log Scopes (config/app.php)
```php
'Log' => [
    'debug' => [
        'className' => JsonFileLog::class,
        'path' => LOGS,
        'file' => 'app.json',
        'scopes' => ['audit', 'application'],
        'levels' => ['info', 'notice', 'warning', 'error'],
    ],
],
```

## Sending Logs Programmatically

### From PHP Application
```php
use Cake\Log\Log;

// Simple log
Log::info('User logged in', ['user_id' => 123]);

// Audit log with changes
Log::info('Product updated', [
    'trace_id' => uniqid(),
    'action' => 'products.update',
    'changes' => [
        'price' => ['before' => 100, 'after' => 150]
    ]
]);
```

### Via HTTP API
```bash
curl -X POST http://10.0.2.30:8888/ \
  -H "Content-Type: application/json" \
  -d '{
    "level": "info",
    "message": "Custom log from remote app",
    "hostname": "'$(hostname)'",
    "timestamp": "'$(date -u +%Y-%m-%dT%H:%M:%SZ)'"
  }'
```

### Via Fluent Bit Forward Protocol
```bash
# Install Fluent Bit locally and use config
fluent-bit -c /opt/codes/sample-logging-app/config/fluent-bit-client.conf
```

## Troubleshooting

### Logs Not Appearing in OpenSearch
1. Check connectivity: `telnet 10.0.2.30 8888`
2. Check Fluent Bit status: `systemctl status fluent-bit-forwarder`
3. Check local logs: `tail -f logs/app.json`
4. Verify firewall: `sudo firewall-cmd --list-ports`

### Permission Errors
```bash
sudo chown -R apache:apache logs tmp
sudo chmod -R 777 logs tmp
sudo setenforce 0  # Temporarily disable SELinux for testing
```

### Database Connection Issues
1. Verify credentials in `.env` or `config/app_local.php`
2. Test connection: `mysql -h localhost -u youruser -p`
3. Check if database exists: `SHOW DATABASES;`

## Monitoring

### View Real-Time Logs
```bash
# Local logs
tail -f logs/app.json | jq '.'

# Central server logs
curl -X GET "http://10.0.2.30:9200/logs-remote/_search?size=10&sort=@timestamp:desc" | jq '.hits.hits[]._source'
```

### Check Fluent Bit Metrics
- Local metrics: http://localhost:2020/api/v1/metrics
- Central metrics: http://10.0.2.30:2020

### OpenSearch Dashboards
- URL: http://10.0.2.30:5601
- Index Pattern: `logs-remote`
- Fields to filter:
  - `action`: Type of operation
  - `table`: Database table
  - `trace_id`: Track related operations
  - `changes`: View what changed

## Security Considerations

1. **Network Security**
   - Use VPN or private network for log transmission
   - Consider TLS encryption for production

2. **Data Privacy**
   - Passwords are automatically excluded from logs
   - Configure `ignoreFields` in AuditLogBehavior for sensitive data

3. **Access Control**
   - Restrict access to OpenSearch Dashboards
   - Use authentication for Fluent Bit endpoints

## Support

### Documentation
- CakePHP: https://book.cakephp.org/4
- Fluent Bit: https://docs.fluentbit.io/
- OpenSearch: https://opensearch.org/docs/

### Common Issues
- **Q**: Logs not forwarding?
  **A**: Check firewall on 10.0.2.30 allows ports 8888 and 24224

- **Q**: OpenSearch index not created?
  **A**: Ensure Fluent Bit output is configured for `logs-remote` index

- **Q**: High memory usage?
  **A**: Adjust `buffer_max_size` in Fluent Bit configuration

## Uninstall

To remove the application:
```bash
sudo ./uninstall.sh
```

This will:
- Stop and remove Fluent Bit forwarder service
- Remove Apache configuration
- Preserve database and application files