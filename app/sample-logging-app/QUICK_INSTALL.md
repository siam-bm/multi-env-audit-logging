# 🚀 Quick Installation Guide

## Web-Based Installer for CakePHP Logging App

This application includes a **web-based installer** that automatically configures everything to connect with the central logging server at **10.0.2.30**.

---

## 📦 Installation Steps

### 1. Extract ZIP File
```bash
unzip sample-logging-app-1.0.0.zip
cd sample-logging-app-1.0.0
```

### 2. Set Permissions
```bash
chmod -R 755 .
chmod -R 777 logs tmp
```

### 3. Open Web Installer
Navigate to:
```
http://your-domain/installer.php
```

### 4. Follow Installation Wizard

The web installer will guide you through:

#### Step 1: Requirements Check
- PHP version verification
- Extension checks (PDO, JSON, cURL)
- Directory permissions

#### Step 2: Database Configuration
- Enter your MySQL credentials
- Database will be created automatically
- Connection will be tested

#### Step 3: Application Settings
- Application name
- Domain configuration
- Enable/disable central logging

#### Step 4: Automatic Installation
The installer will:
- ✅ Create database tables
- ✅ Configure Fluent Bit forwarding to **10.0.2.30**
- ✅ Set up log directories
- ✅ Test connection to central server
- ✅ Send test log entry

#### Step 5: Complete!
- Access your application
- View logs at **http://10.0.2.30:5601**

---

## 🖥️ System Requirements

- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher
- **Apache**: with mod_rewrite enabled
- **Extensions**: PDO, JSON, cURL

---

## 📡 Central Logging Server

All logs are automatically forwarded to:

| Service | Address |
|---------|---------|
| **Fluent Bit HTTP** | 10.0.2.30:8888 |
| **Fluent Bit Forward** | 10.0.2.30:24224 |
| **OpenSearch Dashboard** | http://10.0.2.30:5601 |
| **Index Pattern** | logs-remote |

---

## 🔧 Manual Installation (Alternative)

If the web installer doesn't work:

### 1. Database Setup
```sql
CREATE DATABASE sample_logging_db;
```

### 2. Copy Configuration
```bash
cp .env.example .env
# Edit .env with your database credentials
```

### 3. Run Migrations
```bash
php bin/cake.php migrations migrate
```

### 4. Configure Apache
```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/app/webroot
    
    <Directory /path/to/app/webroot>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

---

## ✅ Post-Installation

### Security
**Important:** Delete the installer after setup:
```bash
rm webroot/installer.php
```

### Test Logging
```bash
# Send test log
echo '{"level":"info","message":"Test"}' >> logs/app.json

# Check in OpenSearch
curl http://10.0.2.30:9200/logs-remote/_search
```

### Access Points
- **Your App**: http://your-domain/
- **Products CRUD**: http://your-domain/products
- **Users CRUD**: http://your-domain/users
- **View Logs**: http://10.0.2.30:5601

---

## 🐳 Docker Installation (Optional)

```bash
docker-compose up -d
```

Access at: http://localhost:8080

---

## 📊 Features

- ✅ **Automatic Audit Logging**: All CRUD operations logged
- ✅ **Centralized Collection**: Forwards to 10.0.2.30
- ✅ **Real-time Dashboard**: OpenSearch visualization
- ✅ **Field-level Tracking**: Before/after values
- ✅ **Zero Configuration**: Everything pre-configured

---

## 🆘 Troubleshooting

### Installer Issues
- Check PHP version: `php -v`
- Verify extensions: `php -m`
- Check permissions: `ls -la logs/`

### Connection Issues
```bash
# Test Fluent Bit connection
telnet 10.0.2.30 8888

# Test OpenSearch
curl http://10.0.2.30:9200
```

### View Logs
```bash
tail -f logs/app.json | jq '.'
```

---

## 📚 Documentation

- [Full Setup Guide](SETUP_GUIDE.md)
- [Environment Configuration](.env.example)
- [Docker Setup](docker-compose.yml)

---

**Made for centralized logging with Fluent Bit @ 10.0.2.30**