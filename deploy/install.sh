#!/bin/bash
###############################################################################
# CallMe v2 - Installation Script for CentOS 9 with PHP 8.2.29
# 
# This script installs and configures CallMe service
#
# Usage: sudo ./install.sh
###############################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Functions
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "ℹ $1"
}

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   print_error "This script must be run as root (use sudo)"
   exit 1
fi

print_info "CallMe v2 Installation Script"
echo "================================"
echo ""

# Get current directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
CALLME_DIR="$(dirname "$SCRIPT_DIR")"

print_info "CallMe directory: $CALLME_DIR"
echo ""

# Step 1: Check PHP version
print_info "Step 1: Checking PHP version..."
PHP_VERSION=$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo "not found")

if [[ $PHP_VERSION == "not found" ]]; then
    print_error "PHP is not installed"
    print_info "Please install PHP 8.2 first:"
    echo "  sudo dnf install php82 php82-cli php82-json php82-mbstring"
    exit 1
fi

PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if [[ $PHP_MAJOR -lt 8 ]] || [[ $PHP_MAJOR -eq 8 && $PHP_MINOR -lt 2 ]]; then
    print_warning "PHP version $PHP_VERSION is older than 8.2"
    print_info "Recommended: PHP 8.2.29 or higher"
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
else
    print_success "PHP version $PHP_VERSION is compatible"
fi

# Step 2: Check Composer
print_info "Step 2: Checking Composer..."
if [ ! -f "$CALLME_DIR/composer.phar" ]; then
    print_warning "Composer not found in $CALLME_DIR"
    print_info "Downloading Composer..."
    cd "$CALLME_DIR"
    curl -sS https://getcomposer.org/installer | php
    print_success "Composer installed"
else
    print_success "Composer found"
fi

# Step 3: Install dependencies
print_info "Step 3: Installing dependencies..."
cd "$CALLME_DIR"
php composer.phar install --no-dev --optimize-autoloader
print_success "Dependencies installed"

# Step 4: Check configuration
print_info "Step 4: Checking configuration..."
if [ ! -f "$CALLME_DIR/config.php" ]; then
    print_warning "config.php not found"
    if [ -f "$CALLME_DIR/config.example.php" ]; then
        print_info "Creating config.php from example..."
        cp "$CALLME_DIR/config.example.php" "$CALLME_DIR/config.php"
        print_success "config.php created"
        print_warning "Please edit config.php with your settings:"
        echo "  nano $CALLME_DIR/config.php"
        read -p "Press Enter to continue after editing config.php..."
    else
        print_error "config.example.php not found"
        exit 1
    fi
else
    print_success "config.php found"
fi

# Step 5: Create logs directory
print_info "Step 5: Creating logs directory..."
mkdir -p "$CALLME_DIR/logs"
chown asterisk:asterisk "$CALLME_DIR/logs"
chmod 755 "$CALLME_DIR/logs"
print_success "Logs directory created"

# Step 6: Set permissions
print_info "Step 6: Setting permissions..."
chown -R asterisk:asterisk "$CALLME_DIR"
chmod +x "$CALLME_DIR/CallMeIn.php"
chmod +x "$CALLME_DIR/CallMeOut.php"
print_success "Permissions set"

# Step 7: Install supervisord configuration
print_info "Step 7: Installing supervisord configuration..."

# Update paths in supervisord config
SUPERVISOR_CONFIG="$SCRIPT_DIR/supervisord.conf"
SUPERVISOR_DEST="/etc/supervisord.d/callme.ini"
TEMP_SUPERVISOR="/tmp/callme.ini.tmp"

if [ -d "/etc/supervisord.d" ]; then
    sed "s|/path/to/callme_v2/callme|$CALLME_DIR|g" "$SUPERVISOR_CONFIG" > "$TEMP_SUPERVISOR"
    cp "$TEMP_SUPERVISOR" "$SUPERVISOR_DEST"
    rm "$TEMP_SUPERVISOR"
    print_success "Supervisord configuration installed to $SUPERVISOR_DEST"
else
    print_warning "/etc/supervisord.d directory not found"
    print_info "Creating supervisord config in current directory..."
    sed "s|/path/to/callme_v2/callme|$CALLME_DIR|g" "$SUPERVISOR_CONFIG" > "$CALLME_DIR/callme-supervisor.ini"
    print_success "Configuration saved to $CALLME_DIR/callme-supervisor.ini"
    print_warning "Please copy it to your supervisord configuration directory:"
    echo "  sudo cp $CALLME_DIR/callme-supervisor.ini /etc/supervisord.d/"
fi

# Step 8: Reload supervisord
print_info "Step 8: Do you want to reload supervisord now?"
read -p "Reload supervisord? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    if command -v supervisorctl &> /dev/null; then
        supervisorctl reread
        supervisorctl update
        sleep 2
        
        if supervisorctl status callme | grep -q RUNNING; then
            print_success "CallMe started successfully via supervisord"
        else
            print_warning "CallMe is not running yet"
            print_info "Start it with: supervisorctl start callme"
        fi
    else
        print_error "supervisorctl not found"
        print_info "Please install supervisor first:"
        echo "  sudo dnf install supervisor"
        echo "  sudo systemctl enable supervisord"
        echo "  sudo systemctl start supervisord"
    fi
fi

# Step 9: Configure firewall (if needed)
print_info "Step 9: Firewall configuration"
if command -v firewall-cmd &> /dev/null; then
    read -p "Open AMI port 5038 in firewall? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        firewall-cmd --permanent --add-port=5038/tcp
        firewall-cmd --reload
        print_success "Firewall configured"
    fi
else
    print_warning "firewalld not found, skip firewall configuration"
fi

# Summary
echo ""
echo "================================"
print_success "Installation completed!"
echo "================================"
echo ""
print_info "Supervisord commands:"
echo "  Start:   sudo supervisorctl start callme"
echo "  Stop:    sudo supervisorctl stop callme"
echo "  Restart: sudo supervisorctl restart callme"
echo "  Status:  sudo supervisorctl status callme"
echo "  Logs:    sudo supervisorctl tail -f callme"
echo ""
print_info "CallMe logs:"
echo "  tail -f $CALLME_DIR/logs/CallMe.log"
echo ""
print_info "Configuration file:"
echo "  $CALLME_DIR/config.php"
echo ""

if command -v supervisorctl &> /dev/null; then
    if supervisorctl status callme 2>/dev/null | grep -q RUNNING; then
        print_success "CallMe is running via supervisord!"
    else
        print_warning "CallMe is not running"
        print_info "Start it with: sudo supervisorctl start callme"
    fi
else
    print_warning "Supervisord not installed"
    print_info "Install it with: sudo dnf install supervisor"
fi

exit 0

