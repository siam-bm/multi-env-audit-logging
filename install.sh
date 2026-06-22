#!/bin/bash

echo "========================================="
echo "   Audit Logging Docker Installation    "
echo "========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Default values
DEFAULT_MYSQL_HOST="10.0.2.30"
DEFAULT_OPENSEARCH_HOST="10.0.2.30"

# Check if running as root
if [ "$EUID" -eq 0 ]; then 
   echo -e "${YELLOW}Note: Running as root${NC}"
fi

# Check Docker installation
echo "Checking prerequisites..."
if ! command -v docker &> /dev/null; then
    echo -e "${RED}❌ Docker is not installed${NC}"
    echo ""
    echo "Please install Docker first:"
    echo "  Ubuntu/Debian: sudo apt-get install docker.io docker-compose"
    echo "  CentOS/RHEL:   sudo yum install docker docker-compose"
    echo "  Or visit: https://docs.docker.com/get-docker/"
    exit 1
else
    echo -e "${GREEN}✓ Docker found${NC}"
fi

# Check Docker Compose
if ! command -v docker-compose &> /dev/null; then
    if ! docker compose version &> /dev/null; then
        echo -e "${RED}❌ Docker Compose is not installed${NC}"
        echo "Please install Docker Compose:"
        echo "  sudo curl -L https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m) -o /usr/local/bin/docker-compose"
        echo "  sudo chmod +x /usr/local/bin/docker-compose"
        exit 1
    fi
    COMPOSE_CMD="docker compose"
else
    COMPOSE_CMD="docker-compose"
fi
echo -e "${GREEN}✓ Docker Compose found${NC}"

# Configuration
echo ""
echo "========================================="
echo "         Configuration Setup             "
echo "========================================="
echo ""

# Ask for MySQL host
read -p "Enter MySQL Host IP [default: $DEFAULT_MYSQL_HOST]: " MYSQL_HOST
MYSQL_HOST=${MYSQL_HOST:-$DEFAULT_MYSQL_HOST}

# Ask for MySQL credentials
read -p "Enter MySQL Database [default: sample_logging_db]: " DB_NAME
DB_NAME=${DB_NAME:-sample_logging_db}

read -p "Enter MySQL Username [default: cakeuser]: " DB_USER
DB_USER=${DB_USER:-cakeuser}

read -sp "Enter MySQL Password [default: cakepass]: " DB_PASS
echo ""
DB_PASS=${DB_PASS:-cakepass}

# Ask for OpenSearch host
read -p "Enter OpenSearch Host IP [default: $DEFAULT_OPENSEARCH_HOST]: " OPENSEARCH_HOST
OPENSEARCH_HOST=${OPENSEARCH_HOST:-$DEFAULT_OPENSEARCH_HOST}

read -p "Enter OpenSearch Port [default: 9200]: " OPENSEARCH_PORT
OPENSEARCH_PORT=${OPENSEARCH_PORT:-9200}

# Test MySQL connection
echo ""
echo "Testing MySQL connection..."
if command -v mysql &> /dev/null; then
    if mysql -h "$MYSQL_HOST" -u "$DB_USER" -p"$DB_PASS" -e "SELECT 1" &> /dev/null; then
        echo -e "${GREEN}✓ MySQL connection successful${NC}"
    else
        echo -e "${YELLOW}⚠ Could not connect to MySQL (will continue anyway)${NC}"
    fi
else
    echo -e "${YELLOW}⚠ MySQL client not installed, skipping connection test${NC}"
fi

# Test OpenSearch connection
echo "Testing OpenSearch connection..."
if curl -s -o /dev/null -w "%{http_code}" "http://$OPENSEARCH_HOST:$OPENSEARCH_PORT" | grep -q "200\|401"; then
    echo -e "${GREEN}✓ OpenSearch is reachable${NC}"
else
    echo -e "${YELLOW}⚠ Could not reach OpenSearch (will continue anyway)${NC}"
fi

# Create .env file
echo ""
echo "Creating environment configuration..."
cat > .env <<EOF
# MySQL Configuration
DB_HOST=$MYSQL_HOST
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS

# OpenSearch Configuration
OPENSEARCH_HOST=$OPENSEARCH_HOST
OPENSEARCH_PORT=$OPENSEARCH_PORT

# Application Settings
APP_NAME=AuditLoggingPOC
SYSTEM_NAME=cakephp-audit
EOF

echo -e "${GREEN}✓ Configuration saved to .env${NC}"

# Create required directories
echo ""
echo "Creating directory structure..."
mkdir -p logs/dev logs/staging logs/prod
mkdir -p config/dev config/staging config/prod
mkdir -p app/tmp app/logs
chmod 777 logs/dev logs/staging logs/prod
chmod 777 app/tmp app/logs

echo -e "${GREEN}✓ Directories created${NC}"

# Copy Dockerfile if missing
if [ ! -f "Dockerfile" ]; then
    echo "Creating Dockerfile..."
    cat > Dockerfile <<'DOCKERFILE'
FROM php:8.1-apache

RUN apt-get update && apt-get install -y \
    git curl libicu-dev libpng-dev libjpeg-dev libfreetype6-dev \
    libzip-dev zip unzip default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql intl zip gd opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN a2enmod rewrite headers

WORKDIR /var/www/html

RUN echo '<Directory /var/www/html/webroot>' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    AllowOverride All' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    Require all granted' >> /etc/apache2/sites-available/000-default.conf && \
    echo '</Directory>' >> /etc/apache2/sites-available/000-default.conf && \
    sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/webroot|' /etc/apache2/sites-available/000-default.conf

RUN mkdir -p /var/www/html/logs && chmod 777 /var/www/html/logs
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
DOCKERFILE
    echo -e "${GREEN}✓ Dockerfile created${NC}"
fi

# Build images
echo ""
echo "Building Docker images..."
$COMPOSE_CMD build

# Ask which environments to start
echo ""
echo "========================================="
echo "        Environment Selection            "
echo "========================================="
echo ""
echo "Which environments would you like to start?"
echo "  1) Development only (port 8081)"
echo "  2) Staging only (port 8082)"
echo "  3) Production only (port 8083)"
echo "  4) All environments"
echo "  5) None (manual start later)"
echo ""
read -p "Enter choice [1-5]: " ENV_CHOICE

case $ENV_CHOICE in
    1)
        echo "Starting Development environment..."
        $COMPOSE_CMD up -d app-dev fluentbit-dev
        STARTED="Development (http://$(hostname -I | awk '{print $1}'):8081)"
        ;;
    2)
        echo "Starting Staging environment..."
        $COMPOSE_CMD up -d app-staging fluentbit-staging
        STARTED="Staging (http://$(hostname -I | awk '{print $1}'):8082)"
        ;;
    3)
        echo "Starting Production environment..."
        $COMPOSE_CMD up -d app-prod fluentbit-prod
        STARTED="Production (http://$(hostname -I | awk '{print $1}'):8083)"
        ;;
    4)
        echo "Starting all environments..."
        $COMPOSE_CMD up -d
        STARTED="All environments"
        ;;
    5)
        echo "Skipping startup. You can start manually with:"
        echo "  $COMPOSE_CMD up -d"
        STARTED="None"
        ;;
    *)
        echo "Invalid choice. Skipping startup."
        STARTED="None"
        ;;
esac

# Setup OpenSearch aliases
echo ""
echo "========================================="
echo "     OpenSearch Configuration           "
echo "========================================="
echo ""
read -p "Do you want to create OpenSearch aliases now? (y/n): " CREATE_ALIASES

if [ "$CREATE_ALIASES" = "y" ] || [ "$CREATE_ALIASES" = "Y" ]; then
    if [ -f "opensearch/create-aliases.sh" ]; then
        echo "Creating OpenSearch aliases..."
        bash opensearch/create-aliases.sh "$OPENSEARCH_HOST" "$OPENSEARCH_PORT"
    else
        echo -e "${YELLOW}⚠ Alias script not found, skipping${NC}"
    fi
fi

# Get local IP
LOCAL_IP=$(hostname -I | awk '{print $1}')

# Final summary
echo ""
echo "========================================="
echo -e "${GREEN}    Installation Complete!${NC}"
echo "========================================="
echo ""
echo "Configuration Summary:"
echo "  MySQL Host:      $MYSQL_HOST"
echo "  OpenSearch Host: $OPENSEARCH_HOST:$OPENSEARCH_PORT"
echo "  Started:         $STARTED"
echo ""
echo "Access URLs:"
echo "  Development:  http://$LOCAL_IP:8081"
echo "  Staging:      http://$LOCAL_IP:8082"
echo "  Production:   http://$LOCAL_IP:8083"
echo ""
echo "OpenSearch Dashboards:"
echo "  http://$OPENSEARCH_HOST:5601"
echo ""
echo "Useful Commands:"
echo "  Start all:    $COMPOSE_CMD up -d"
echo "  Stop all:     $COMPOSE_CMD down"
echo "  View logs:    $COMPOSE_CMD logs -f"
echo "  Status:       $COMPOSE_CMD ps"
echo ""
echo "Log Indices in OpenSearch:"
echo "  audit-dev-*"
echo "  audit-staging-*"
echo "  audit-prod-*"
echo ""
echo "========================================="