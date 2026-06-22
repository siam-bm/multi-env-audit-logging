#!/bin/bash

#############################################
# CakePHP Sample Logging App Installer
# Connects to Central Logging at 10.0.2.30
#############################################

set -e

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
CENTRAL_SERVER="10.0.2.30"
APP_DIR="/opt/codes/sample-logging-app"
APACHE_USER="apache"
APACHE_GROUP="apache"

echo -e "${BLUE}============================================${NC}"
echo -e "${BLUE}  CakePHP Sample Logging App Installer${NC}"
echo -e "${BLUE}  Central Logging Server: ${CENTRAL_SERVER}${NC}"
echo -e "${BLUE}============================================${NC}\n"

# Function to check command status
check_status() {
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ $1 successful${NC}"
    else
        echo -e "${RED}✗ $1 failed${NC}"
        exit 1
    fi
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
   echo -e "${RED}Please run as root (use sudo)${NC}"
   exit 1
fi

# Step 1: Check prerequisites
echo -e "${YELLOW}Step 1: Checking prerequisites...${NC}"

# Check PHP
if ! command -v php &> /dev/null; then
    echo -e "${RED}PHP is not installed. Installing PHP...${NC}"
    dnf install -y php php-mysqlnd php-json php-mbstring php-intl php-xml
fi
check_status "PHP check"

# Check Apache
if ! command -v httpd &> /dev/null; then
    echo -e "${RED}Apache is not installed. Installing Apache...${NC}"
    dnf install -y httpd
fi
check_status "Apache check"

# Check Composer
if ! command -v composer &> /dev/null; then
    echo -e "${YELLOW}Installing Composer...${NC}"
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
    chmod +x /usr/local/bin/composer
fi
check_status "Composer check"

# Step 2: Clone or update repository
echo -e "\n${YELLOW}Step 2: Setting up application directory...${NC}"

if [ -d "$APP_DIR" ]; then
    echo "Application directory exists at $APP_DIR"
else
    echo "Creating application directory at $APP_DIR"
    mkdir -p $APP_DIR
    # Here you would clone from git repository
    # git clone https://github.com/yourrepo/sample-logging-app.git $APP_DIR
fi

cd $APP_DIR

# Step 3: Install dependencies
echo -e "\n${YELLOW}Step 3: Installing dependencies...${NC}"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction 2>/dev/null || echo "Dependencies already installed"
check_status "Composer dependencies"

# Step 4: Database configuration
echo -e "\n${YELLOW}Step 4: Configuring database connection...${NC}"

# Prompt for database details
read -p "Enter MySQL host [localhost]: " DB_HOST
DB_HOST=${DB_HOST:-localhost}

read -p "Enter MySQL database name [sample_logging_db]: " DB_NAME
DB_NAME=${DB_NAME:-sample_logging_db}

read -p "Enter MySQL username [root]: " DB_USER
DB_USER=${DB_USER:-root}

read -sp "Enter MySQL password: " DB_PASS
echo ""

# Create .env file
cat > $APP_DIR/.env << EOF
#!/usr/bin/env bash
# Local environment configuration

export APP_NAME="SampleLoggingApp"
export DEBUG="true"
export APP_ENCODING="UTF-8"
export APP_DEFAULT_LOCALE="en_US"
export APP_DEFAULT_TIMEZONE="UTC"
export SECURITY_SALT="$(openssl rand -hex 32)"

# Database Configuration
export DATABASE_URL="mysql://${DB_USER}:${DB_PASS}@${DB_HOST}/${DB_NAME}?encoding=utf8&timezone=UTC&cacheMetadata=true&quoteIdentifiers=false&persistent=false"

# Logging Configuration
export LOG_DEBUG_URL="file://logs?levels[]=notice&levels[]=info&levels[]=debug&file=debug"
export LOG_ERROR_URL="file://logs?levels[]=warning&levels[]=error&levels[]=critical&levels[]=alert&levels[]=emergency&file=error"

# Central Logging Server
export CENTRAL_LOG_SERVER="${CENTRAL_SERVER}"
export FLUENT_BIT_HTTP_PORT="8888"
export FLUENT_BIT_FORWARD_PORT="24224"
EOF

chmod 600 $APP_DIR/.env
check_status "Database configuration"

# Step 5: Create database if not exists
echo -e "\n${YELLOW}Step 5: Setting up database...${NC}"
mysql -h$DB_HOST -u$DB_USER -p$DB_PASS -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};" 2>/dev/null || echo "Database may already exist"

# Run migrations
cd $APP_DIR
bin/cake migrations migrate -q 2>/dev/null || echo "Migrations already run"
check_status "Database migrations"

# Step 6: Configure Fluent Bit forwarder
echo -e "\n${YELLOW}Step 6: Configuring Fluent Bit log forwarding...${NC}"

cat > $APP_DIR/config/fluent-bit-forwarder.conf << EOF
[SERVICE]
    Flush        5
    Daemon       off
    Log_Level    info

[INPUT]
    Name              tail
    Path              ${APP_DIR}/logs/app.json
    Parser            json
    Tag               remote.app.\${HOSTNAME}
    Refresh_Interval  5
    Skip_Empty_Lines  On
    DB                /var/lib/fluent-bit/sample-app-forward.db

[FILTER]
    Name record_modifier
    Match remote.app.*
    Record hostname \${HOSTNAME}
    Record application sample_logging_app
    Record environment production
    Record server_group remote

[OUTPUT]
    Name          forward
    Match         remote.app.*
    Host          ${CENTRAL_SERVER}
    Port          24224
    Shared_Key    sample_app_key

[OUTPUT]
    Name          http
    Match         remote.app.*
    Host          ${CENTRAL_SERVER}
    Port          8888
    URI           /
    Format        json
EOF

check_status "Fluent Bit configuration"

# Step 7: Set up permissions
echo -e "\n${YELLOW}Step 7: Setting up permissions...${NC}"

# Create directories if they don't exist
mkdir -p $APP_DIR/logs
mkdir -p $APP_DIR/tmp/cache/{models,persistent,views}
mkdir -p $APP_DIR/tmp/sessions
mkdir -p $APP_DIR/tmp/tests

# Set ownership
chown -R ${APACHE_USER}:${APACHE_GROUP} $APP_DIR/logs
chown -R ${APACHE_USER}:${APACHE_GROUP} $APP_DIR/tmp
chmod -R 777 $APP_DIR/logs
chmod -R 777 $APP_DIR/tmp

# Set SELinux contexts if SELinux is enabled
if command -v getenforce &> /dev/null && [ "$(getenforce)" != "Disabled" ]; then
    chcon -R -t httpd_sys_rw_content_t $APP_DIR/logs
    chcon -R -t httpd_sys_rw_content_t $APP_DIR/tmp
    chcon -R -t httpd_sys_content_t $APP_DIR/webroot
fi
check_status "Permissions setup"

# Step 8: Configure Apache virtual host
echo -e "\n${YELLOW}Step 8: Configuring Apache virtual host...${NC}"

read -p "Enter domain name for the app [sample-app.local]: " DOMAIN_NAME
DOMAIN_NAME=${DOMAIN_NAME:-sample-app.local}

cat > /etc/httpd/conf.d/${DOMAIN_NAME}.conf << EOF
<VirtualHost *:80>
    ServerName ${DOMAIN_NAME}
    DocumentRoot ${APP_DIR}/webroot
    
    <Directory ${APP_DIR}/webroot>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    # Logging
    ErrorLog /var/log/httpd/${DOMAIN_NAME}-error.log
    CustomLog /var/log/httpd/${DOMAIN_NAME}-access.log combined
</VirtualHost>
EOF

# Add to hosts file if local domain
if [[ "$DOMAIN_NAME" == *.local ]]; then
    if ! grep -q "$DOMAIN_NAME" /etc/hosts; then
        echo "127.0.0.1 $DOMAIN_NAME" >> /etc/hosts
    fi
fi

check_status "Apache configuration"

# Step 9: Install and configure Fluent Bit service (optional)
echo -e "\n${YELLOW}Step 9: Setting up Fluent Bit service for forwarding...${NC}"

cat > /etc/systemd/system/fluent-bit-forwarder.service << EOF
[Unit]
Description=Fluent Bit Log Forwarder for Sample App
After=network.target

[Service]
Type=simple
ExecStart=/opt/fluent-bit/bin/fluent-bit -c ${APP_DIR}/config/fluent-bit-forwarder.conf
Restart=always
User=root
Group=root

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
check_status "Fluent Bit service setup"

# Step 10: Start services
echo -e "\n${YELLOW}Step 10: Starting services...${NC}"

systemctl restart httpd
systemctl enable httpd
check_status "Apache service"

# Start Fluent Bit forwarder if Fluent Bit is installed
if [ -f "/opt/fluent-bit/bin/fluent-bit" ]; then
    systemctl enable fluent-bit-forwarder
    systemctl start fluent-bit-forwarder
    check_status "Fluent Bit forwarder"
else
    echo -e "${YELLOW}Note: Fluent Bit not installed locally. Logs will be forwarded via HTTP API${NC}"
fi

# Step 11: Test connectivity
echo -e "\n${YELLOW}Step 11: Testing connectivity to central server...${NC}"

# Test connection to Fluent Bit HTTP endpoint
if curl -s -o /dev/null -w "%{http_code}" http://${CENTRAL_SERVER}:8888/ | grep -q "200\|201\|404"; then
    echo -e "${GREEN}✓ Connection to Fluent Bit HTTP endpoint successful${NC}"
else
    echo -e "${YELLOW}⚠ Could not connect to Fluent Bit HTTP endpoint${NC}"
fi

# Test connection to OpenSearch
if curl -s -o /dev/null -w "%{http_code}" http://${CENTRAL_SERVER}:9200/ | grep -q "200"; then
    echo -e "${GREEN}✓ Connection to OpenSearch successful${NC}"
else
    echo -e "${YELLOW}⚠ Could not connect to OpenSearch${NC}"
fi

# Step 12: Generate sample data
echo -e "\n${YELLOW}Step 12: Generating sample log entry...${NC}"

# Create a test log entry
echo "{\"timestamp\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",\"level\":\"info\",\"message\":\"Installation completed\",\"host\":\"$(hostname)\",\"action\":\"install.complete\"}" >> $APP_DIR/logs/app.json

check_status "Sample log generation"

# Final summary
echo -e "\n${GREEN}============================================${NC}"
echo -e "${GREEN}  Installation Complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo -e "\n${BLUE}Access your application at:${NC}"
echo -e "  Web UI: ${GREEN}http://${DOMAIN_NAME}${NC}"
echo -e "  Products CRUD: ${GREEN}http://${DOMAIN_NAME}/products${NC}"
echo -e "  Users CRUD: ${GREEN}http://${DOMAIN_NAME}/users${NC}"
echo -e "\n${BLUE}Central Logging Dashboard:${NC}"
echo -e "  OpenSearch: ${GREEN}http://${CENTRAL_SERVER}:5601${NC}"
echo -e "  Fluent Bit Metrics: ${GREEN}http://${CENTRAL_SERVER}:2020${NC}"
echo -e "\n${BLUE}Log Files:${NC}"
echo -e "  Application logs: ${GREEN}${APP_DIR}/logs/app.json${NC}"
echo -e "  Apache logs: ${GREEN}/var/log/httpd/${DOMAIN_NAME}-*.log${NC}"
echo -e "\n${YELLOW}Note: Logs are automatically forwarded to ${CENTRAL_SERVER}${NC}"
echo -e "${YELLOW}Check OpenSearch Dashboards for 'logs-remote' index${NC}\n"

# Create uninstaller
cat > $APP_DIR/uninstall.sh << 'UNINSTALL'
#!/bin/bash
echo "Uninstalling Sample Logging App..."
systemctl stop fluent-bit-forwarder 2>/dev/null
systemctl disable fluent-bit-forwarder 2>/dev/null
rm -f /etc/systemd/system/fluent-bit-forwarder.service
rm -f /etc/httpd/conf.d/*.local.conf
systemctl restart httpd
echo "Uninstall complete. Database and files preserved in $APP_DIR"
UNINSTALL
chmod +x $APP_DIR/uninstall.sh

echo -e "${GREEN}Uninstaller created at: ${APP_DIR}/uninstall.sh${NC}"