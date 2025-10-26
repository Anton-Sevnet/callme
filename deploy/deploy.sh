#!/bin/bash
###############################################################################
# Deployment script for CallMe project
# 
# Usage:
#   ./deploy.sh [branch]
#
# Example:
#   ./deploy.sh master
###############################################################################

set -e  # Exit on error

# Configuration
PROJECT_PATH="/path/to/your/project/callme"
BRANCH="${1:-master}"
LOG_FILE="$PROJECT_PATH/deploy/deploy.log"
BACKUP_DIR="$PROJECT_PATH/backups"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE"
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$LOG_FILE"
}

# Create backup
backup() {
    log "Creating backup..."
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    mkdir -p "$BACKUP_DIR"
    
    # Backup config and important files
    if [ -f "$PROJECT_PATH/config.php" ]; then
        cp "$PROJECT_PATH/config.php" "$BACKUP_DIR/config_${TIMESTAMP}.php"
        log "Config backed up to $BACKUP_DIR/config_${TIMESTAMP}.php"
    fi
}

# Main deployment
deploy() {
    log "=== STARTING DEPLOYMENT ==="
    log "Branch: $BRANCH"
    log "Project path: $PROJECT_PATH"
    
    # Check if project directory exists
    if [ ! -d "$PROJECT_PATH" ]; then
        error "Project directory not found: $PROJECT_PATH"
        exit 1
    fi
    
    cd "$PROJECT_PATH"
    
    # Create backup
    backup
    
    # Git operations
    log "Fetching latest changes from GitHub..."
    git fetch origin "$BRANCH"
    
    log "Checking out branch: $BRANCH"
    git checkout "$BRANCH"
    
    log "Pulling latest changes..."
    git pull origin "$BRANCH"
    
    # Show latest commits
    log "Latest commits:"
    git log -3 --oneline | tee -a "$LOG_FILE"
    
    # Composer install
    log "Installing dependencies..."
    if [ -f "composer.json" ]; then
        composer install --no-dev --optimize-autoloader
        log "Dependencies installed successfully"
    else
        warning "composer.json not found, skipping composer install"
    fi
    
    # Set proper permissions (adjust as needed)
    log "Setting permissions..."
    # chmod -R 755 "$PROJECT_PATH"
    # chown -R www-data:www-data "$PROJECT_PATH"  # Adjust user/group as needed
    
    # Optional: Restart services
    # log "Restarting services..."
    # systemctl restart asterisk || warning "Failed to restart asterisk"
    # systemctl restart apache2 || warning "Failed to restart apache2"
    
    log "=== DEPLOYMENT COMPLETED SUCCESSFULLY ==="
}

# Rollback function
rollback() {
    log "=== STARTING ROLLBACK ==="
    
    cd "$PROJECT_PATH"
    
    # Get previous commit
    PREV_COMMIT=$(git rev-parse HEAD~1)
    log "Rolling back to commit: $PREV_COMMIT"
    
    git reset --hard "$PREV_COMMIT"
    
    # Restore composer dependencies
    composer install --no-dev --optimize-autoloader
    
    log "=== ROLLBACK COMPLETED ==="
}

# Check if rollback is requested
if [ "$1" == "rollback" ]; then
    rollback
else
    deploy
fi

