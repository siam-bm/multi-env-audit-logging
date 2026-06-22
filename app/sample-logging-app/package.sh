#!/bin/bash

#############################################
# Package Script for CakePHP Sample App
# Creates a distributable ZIP file
#############################################

set -e

# Color codes
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

APP_NAME="sample-logging-app"
VERSION="1.0.0"
PACKAGE_NAME="${APP_NAME}-${VERSION}"
BUILD_DIR="/tmp/${PACKAGE_NAME}"
OUTPUT_FILE="${PACKAGE_NAME}.zip"

echo -e "${BLUE}==========================================${NC}"
echo -e "${BLUE}  Packaging CakePHP Sample Logging App${NC}"
echo -e "${BLUE}  Version: ${VERSION}${NC}"
echo -e "${BLUE}==========================================${NC}\n"

# Clean up any existing build directory
echo -e "${YELLOW}Cleaning up old builds...${NC}"
rm -rf $BUILD_DIR
rm -f $OUTPUT_FILE

# Create build directory
echo -e "${YELLOW}Creating build directory...${NC}"
mkdir -p $BUILD_DIR

# Copy application files
echo -e "${YELLOW}Copying application files...${NC}"
cp -r bin $BUILD_DIR/
cp -r config $BUILD_DIR/
cp -r src $BUILD_DIR/
cp -r templates $BUILD_DIR/
cp -r webroot $BUILD_DIR/
cp -r tests $BUILD_DIR/ 2>/dev/null || true
cp composer.json $BUILD_DIR/
cp composer.lock $BUILD_DIR/ 2>/dev/null || true

# Copy documentation and setup files
echo -e "${YELLOW}Copying documentation...${NC}"
cp SETUP_GUIDE.md $BUILD_DIR/
cp README.md $BUILD_DIR/ 2>/dev/null || true
cp .env.example $BUILD_DIR/
cp docker-compose.yml $BUILD_DIR/ 2>/dev/null || true
cp Dockerfile $BUILD_DIR/ 2>/dev/null || true

# Create necessary directories
echo -e "${YELLOW}Creating required directories...${NC}"
mkdir -p $BUILD_DIR/logs
mkdir -p $BUILD_DIR/tmp/cache/models
mkdir -p $BUILD_DIR/tmp/cache/persistent
mkdir -p $BUILD_DIR/tmp/cache/views
mkdir -p $BUILD_DIR/tmp/sessions
mkdir -p $BUILD_DIR/tmp/tests

# Create placeholder files
echo '[]' > $BUILD_DIR/logs/.gitkeep
echo '[]' > $BUILD_DIR/tmp/.gitkeep

# Remove development files
echo -e "${YELLOW}Removing development files...${NC}"
find $BUILD_DIR -name ".git" -type d -exec rm -rf {} + 2>/dev/null || true
find $BUILD_DIR -name ".gitignore" -type f -delete 2>/dev/null || true
find $BUILD_DIR -name "*.log" -type f -delete 2>/dev/null || true
find $BUILD_DIR -name ".DS_Store" -type f -delete 2>/dev/null || true
rm -f $BUILD_DIR/config/app_local.php 2>/dev/null || true

# Create installation instructions
cat > $BUILD_DIR/INSTALL.txt << 'EOF'
========================================
CakePHP Sample Logging App Installation
========================================

QUICK INSTALL:
--------------
1. Extract this ZIP file to your web server directory
2. Navigate to: http://your-domain/installer.php
3. Follow the web-based installation wizard
4. Delete installer.php after installation

The installer will automatically:
- Configure database connection
- Set up log forwarding to 10.0.2.30
- Create necessary directories
- Run database migrations
- Test connectivity to central server

MANUAL INSTALL:
---------------
If the web installer doesn't work, see SETUP_GUIDE.md

CENTRAL LOGGING SERVER:
-----------------------
Server: 10.0.2.30
Fluent Bit HTTP: 10.0.2.30:8888
Fluent Bit Forward: 10.0.2.30:24224
OpenSearch Dashboard: http://10.0.2.30:5601

REQUIREMENTS:
-------------
- PHP 7.4 or higher
- MySQL/MariaDB
- Apache with mod_rewrite
- Composer (for dependencies)

SUPPORT:
--------
See SETUP_GUIDE.md for detailed instructions
EOF

# Set permissions
echo -e "${YELLOW}Setting permissions...${NC}"
chmod -R 755 $BUILD_DIR
chmod -R 777 $BUILD_DIR/logs
chmod -R 777 $BUILD_DIR/tmp
chmod 644 $BUILD_DIR/webroot/installer.php

# Create the ZIP file
echo -e "${YELLOW}Creating ZIP package...${NC}"
cd /tmp
zip -r $OUTPUT_FILE $PACKAGE_NAME -q

# Move to current directory
mv $OUTPUT_FILE $(dirname $0)/

# Clean up
echo -e "${YELLOW}Cleaning up...${NC}"
rm -rf $BUILD_DIR

# Calculate file size
FILE_SIZE=$(du -h $(dirname $0)/$OUTPUT_FILE | cut -f1)

# Done
echo -e "\n${GREEN}==========================================${NC}"
echo -e "${GREEN}  Package created successfully!${NC}"
echo -e "${GREEN}==========================================${NC}"
echo -e "\n${BLUE}Package Details:${NC}"
echo -e "  File: ${GREEN}$OUTPUT_FILE${NC}"
echo -e "  Size: ${GREEN}$FILE_SIZE${NC}"
echo -e "  Location: ${GREEN}$(dirname $0)/$OUTPUT_FILE${NC}"
echo -e "\n${BLUE}Installation:${NC}"
echo -e "  1. Upload ${OUTPUT_FILE} to your web server"
echo -e "  2. Extract: unzip $OUTPUT_FILE"
echo -e "  3. Navigate to: http://your-domain/installer.php"
echo -e "  4. Follow the installation wizard"
echo -e "\n${YELLOW}The installer will automatically connect to:${NC}"
echo -e "  Central Server: ${GREEN}10.0.2.30${NC}"
echo -e "  OpenSearch: ${GREEN}http://10.0.2.30:5601${NC}\n"