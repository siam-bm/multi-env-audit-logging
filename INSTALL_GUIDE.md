# Audit Logging Docker Installation Guide

## 📦 Package Contents

This package contains a complete Docker-based audit logging system that connects to your existing MySQL and OpenSearch infrastructure.

## 🚀 Quick Installation

### Prerequisites
- Docker and Docker Compose installed
- Network access to MySQL server (10.0.2.30)
- Network access to OpenSearch server (10.0.2.30)
- Ports 8081-8083 available on local machine

### Installation Steps

1. **Transfer the package to target machine**
   ```bash
   # On this machine
   scp audit-logging-docker.tar.gz user@target-pc:/home/user/
   ```

2. **Extract on target machine**
   ```bash
   # On target machine
   tar -xzf audit-logging-docker.tar.gz
   cd audit-logging-docker
   ```

3. **Run installation script**
   ```bash
   sudo ./install.sh
   ```

4. **Follow the prompts**
   - Enter MySQL host (default: 10.0.2.30)
   - Enter MySQL credentials
   - Enter OpenSearch host (default: 10.0.2.30)
   - Select which environments to start

## 🔧 Manual Installation

If you prefer manual setup:

1. **Extract the package**
   ```bash
   tar -xzf audit-logging-docker.tar.gz
   cd audit-logging-docker
   ```

2. **Create .env file**
   ```bash
   cat > .env <<EOF
   DB_HOST=10.0.2.30
   DB_PORT=3306
   DB_DATABASE=sample_logging_db
   DB_USERNAME=cakeuser
   DB_PASSWORD=cakepass
   OPENSEARCH_HOST=10.0.2.30
   OPENSEARCH_PORT=9200
   EOF
   ```

3. **Create directories**
   ```bash
   mkdir -p logs/{dev,staging,prod}
   mkdir -p config/{dev,staging,prod}
   chmod 777 logs/{dev,staging,prod}
   ```

4. **Start services**
   ```bash
   # All environments
   docker-compose up -d

   # Or specific environment
   docker-compose up -d app-dev fluentbit-dev
   ```

## 📍 Access Points

After installation, access the applications at:

- **Development**: http://[YOUR-IP]:8081
- **Staging**: http://[YOUR-IP]:8082
- **Production**: http://[YOUR-IP]:8083

View logs in OpenSearch Dashboards:
- http://10.0.2.30:5601

## 🔍 Verification

### Check containers are running
```bash
docker-compose ps
```

Expected output:
```
NAME                COMMAND                  SERVICE          STATUS
cakephp-dev         "apache2-foreground"     app-dev          running
fluentbit-dev       "/fluent-bit/bin/..."    fluentbit-dev    running
cakephp-staging     "apache2-foreground"     app-staging      running
fluentbit-staging   "/fluent-bit/bin/..."    fluentbit-staging running
cakephp-prod        "apache2-foreground"     app-prod         running
fluentbit-prod      "/fluent-bit/bin/..."    fluentbit-prod   running
```

### Test log forwarding
```bash
# Generate test log
docker-compose exec app-dev bash -c "echo '{\"action\":\"test\",\"@timestamp\":\"'$(date -Iseconds)'\"}' >> /var/www/html/logs/audit.json"

# Check Fluent Bit picked it up
docker-compose logs fluentbit-dev | tail -10
```

### Verify in OpenSearch
```bash
# Check indices created
curl http://10.0.2.30:9200/_cat/indices/audit-*

# Search for test log
curl http://10.0.2.30:9200/audit-dev-*/_search?q=action:test
```

## 🛠️ Management Commands

### Start/Stop
```bash
# Start all
docker-compose up -d

# Stop all
docker-compose down

# Restart specific environment
docker-compose restart app-dev fluentbit-dev
```

### View Logs
```bash
# All logs
docker-compose logs -f

# Specific environment
docker-compose logs -f app-staging fluentbit-staging
```

### Access Container
```bash
# Access development container
docker-compose exec app-dev bash

# Check audit logs
docker-compose exec app-dev tail -f /var/www/html/logs/audit.json
```

## 🔧 Troubleshooting

### Container won't start
```bash
# Check port conflicts
netstat -tulpn | grep -E "8081|8082|8083"

# Check Docker logs
docker-compose logs app-dev
```

### Logs not appearing in OpenSearch
```bash
# Check Fluent Bit is running
docker-compose ps fluentbit-dev

# Check Fluent Bit logs
docker-compose logs fluentbit-dev

# Test OpenSearch connectivity from container
docker-compose exec fluentbit-dev ping 10.0.2.30
```

### Permission issues
```bash
# Fix log directory permissions
sudo chmod 777 logs/{dev,staging,prod}
sudo chmod 777 app/logs app/tmp
```

## 📋 Configuration Files

### Main Files
- `docker-compose.yml` - Service definitions
- `.env` - Environment variables
- `Dockerfile` - PHP/Apache container

### Fluent Bit
- `fluent-bit/fluent-bit.conf` - Log forwarding configuration
- `fluent-bit/parsers.conf` - Log parsing rules

### Application
- `app/` - CakePHP application code
- `config/{env}/` - Environment-specific configs
- `logs/{env}/` - Log output directories

## 🔐 Security Notes

1. Change default MySQL password in production
2. Enable OpenSearch security features
3. Use firewall rules to restrict access
4. Implement TLS for Fluent Bit → OpenSearch

## 📞 Network Requirements

Ensure the Docker host can reach:
- MySQL: `10.0.2.30:3306`
- OpenSearch: `10.0.2.30:9200`
- OpenSearch Dashboards: `10.0.2.30:5601`

Test connectivity:
```bash
telnet 10.0.2.30 3306
curl http://10.0.2.30:9200
```

## 🎯 Next Steps

After installation:

1. Create OpenSearch index patterns:
   - Go to http://10.0.2.30:5601
   - Stack Management → Index Patterns
   - Create: `audit-dev-*`, `audit-staging-*`, `audit-prod-*`

2. Import dashboard visualizations:
   - Use configurations in `opensearch/dashboards/`

3. Configure your CakePHP app:
   - Place your app code in `app/` directory
   - Update database connections
   - Enable audit logging behavior

## 📝 Notes

- Each environment runs independently
- Logs are forwarded in real-time to OpenSearch
- Data persists in Docker volumes
- Containers auto-restart unless stopped

For additional help, check the main README.md file.