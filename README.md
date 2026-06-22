# Improved Multi-Environment Audit Logging System

Enhanced POC with single Fluent Bit config, environment-aware logging, and OpenSearch aliases.

## 🎯 Key Improvements

### 1. Single Fluent Bit Configuration
- One `fluent-bit.conf` used by all environments
- Environment variables control behavior (`APP_ENV`, `SYSTEM_NAME`)
- Easier maintenance and consistency

### 2. Environment in Log Payload
```json
{
  "environment": "dev",
  "system": "cakephp-audit",
  "action": "student.import",
  "entity": "student",
  "actor_id": 123,
  "trace_id": "abc-123"
}
```

### 3. OpenSearch Aliases
- `audit-dev` → All dev logs
- `audit-staging` → All staging logs
- `audit-prod` → All production logs
- `audit-all` → Cross-environment queries

### 4. Separate Configuration Directories
```
config/
├── dev/        # Dev-specific settings
├── staging/    # Staging-specific settings
└── prod/       # Production-specific settings
```

## 🏗️ Architecture

```
┌─────────────────────────────────────────┐
│         10.0.2.30 Infrastructure        │
│  • OpenSearch                            │
│  • OpenSearch Dashboards                 │
│  • MySQL Database                        │
└─────────────────────────────────────────┘
                    ▲
                    │
    ┌───────────────┼───────────────┐
    │               │               │
┌─────────┐   ┌─────────┐   ┌─────────┐
│   DEV   │   │ STAGING │   │  PROD   │
│  :8081  │   │  :8082  │   │  :8083  │
├─────────┤   ├─────────┤   ├─────────┤
│ CakePHP │   │ CakePHP │   │ CakePHP │
│    +    │   │    +    │   │    +    │
│FluentBit│   │FluentBit│   │FluentBit│
└─────────┘   └─────────┘   └─────────┘
     │             │             │
audit-dev-*  audit-staging-* audit-prod-*
```

## 🚀 Quick Start

### 1. Setup OpenSearch Aliases
```bash
./opensearch/create-aliases.sh 10.0.2.30 9200
```

### 2. Start Environments
```bash
# Start all environments
docker-compose up -d

# Or start specific environment
docker-compose up -d app-dev fluentbit-dev
docker-compose up -d app-staging fluentbit-staging
docker-compose up -d app-prod fluentbit-prod
```

### 3. Access Applications
- **Dev**: http://localhost:8081
- **Staging**: http://localhost:8082
- **Production**: http://localhost:8083

## 📊 OpenSearch Dashboards

### Create Index Patterns
1. Go to http://10.0.2.30:5601
2. Stack Management → Index Patterns
3. Create patterns:
   - `audit-dev-*` for dev logs
   - `audit-staging-*` for staging logs
   - `audit-prod-*` for production logs
   - `audit-all` for cross-environment

### Sample Queries

#### Filter by Environment
```
environment: dev
environment: staging
environment: prod
```

#### Cross-Environment Analysis
```
# Compare action counts across environments
action: "order.created" | stats count by environment

# Find all student imports
action: "student.import" AND environment: *

# Track specific user across environments
actor_id: 123
```

#### Time-Based Queries
```
# Last hour of production activity
environment: prod AND @timestamp: [now-1h TO now]

# Today's staging errors
environment: staging AND level: error AND @timestamp: [now/d TO now]
```

## 🔧 Configuration

### Environment Variables (.env)
```bash
# External Services
DB_HOST=10.0.2.30
DB_PORT=3306
DB_DATABASE=sample_logging_db
DB_USERNAME=cakeuser
DB_PASSWORD=cakepass
OPENSEARCH_HOST=10.0.2.30
OPENSEARCH_PORT=9200
```

### Fluent Bit Environment Variables
Each Fluent Bit container receives:
- `APP_ENV`: dev/staging/prod
- `SYSTEM_NAME`: cakephp-audit
- `OPENSEARCH_HOST`: 10.0.2.30
- `OPENSEARCH_PORT`: 9200

## 📈 Visualizations

The POC includes dashboard configurations for:

1. **Audit by Environment** - Pie chart showing log distribution
2. **Actions Timeline** - Time series of audit events
3. **Top Actions by Environment** - Bar chart of common operations
4. **Active Users** - Table of most active users
5. **Entity Operations** - Heatmap of CRUD operations
6. **System Health Metrics** - Key performance indicators

## 🔮 Future Production Design

### Index Structure
```
audit-gradpak-dev-2026.06.22
audit-gradpak-staging-2026.06.22
audit-gradpak-prod-2026.06.22

audit-rgs-dev-2026.06.22
audit-rgs-staging-2026.06.22
audit-rgs-prod-2026.06.22
```

### Document Structure
```json
{
  "@timestamp": "2026-06-22T12:00:00Z",
  "system": "gradpak",
  "environment": "prod",
  "university": "unsw",
  "actor_id": 123,
  "actor_type": "admin",
  "action": "order.restore",
  "entity_type": "order",
  "entity_id": 555,
  "trace_id": "abc-123",
  "changes": {
    "status": {
      "before": "archived",
      "after": "active"
    }
  }
}
```

## 🛠️ Management

### View Logs
```bash
# All environments
docker-compose logs -f

# Specific environment
docker-compose logs -f app-dev fluentbit-dev
```

### Restart Environment
```bash
docker-compose restart app-staging fluentbit-staging
```

### Check Status
```bash
docker-compose ps
```

### Stop All
```bash
docker-compose down
```

## 📁 Directory Structure
```
docker-multi-env-improved/
├── docker-compose.yml          # Single compose file
├── Dockerfile                  # Apache + PHP
├── .env                       # External services config
├── fluent-bit/
│   ├── fluent-bit.conf       # Single config for all
│   ├── parsers.conf          # Log parsers
│   └── timestamp.lua         # Lua script for enrichment
├── config/
│   ├── dev/                  # Dev configuration
│   ├── staging/              # Staging configuration
│   └── prod/                 # Production configuration
├── app/
│   └── src/Model/Behavior/
│       └── ImprovedAuditLogBehavior.php
├── opensearch/
│   ├── index-templates.json  # Index mappings
│   ├── create-aliases.sh     # Alias setup script
│   └── dashboards/
│       └── audit-visualizations.json
└── logs/
    ├── dev/                  # Dev log files
    ├── staging/              # Staging log files
    └── prod/                 # Production log files
```

## ✅ Benefits

1. **Simplified Maintenance** - One Fluent Bit config
2. **Environment Awareness** - Logs contain environment field
3. **Easy Queries** - OpenSearch aliases simplify searching
4. **Scalable Design** - Ready for multiple systems/universities
5. **Clear Separation** - Config directories mirror deployment
6. **Production Ready** - Architecture scales to real use cases

This improved design demonstrates enterprise-ready audit logging with minimal complexity and maximum flexibility.