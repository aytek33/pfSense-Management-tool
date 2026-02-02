#!/bin/bash
#
# pfSense MAC Binding Deployment Script
# Copies all files to a pfSense firewall and runs installation
#
# Usage:
#   ./deploy_to_pfsense.sh <PFSENSE_IP> [SSH_PORT]
#
# Examples:
#   ./deploy_to_pfsense.sh 192.168.1.1
#   ./deploy_to_pfsense.sh 10.0.0.1 2222
#

# Don't use set -e - we handle errors manually

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Parameters
PFSENSE_IP="${1:-}"
SSH_PORT="${2:-22}"
SSH_USER="${PFSENSE_SSH_USER:-admin}"  # Default: admin, change with: export PFSENSE_SSH_USER=root
NO_ROLLBACK="${NO_ROLLBACK:-false}"    # Set to true to skip rollback on failure (for debugging)

# SSH ControlMaster - connection reuse (password asked only once)
SSH_CONTROL_PATH="/tmp/ssh-macbind-$$-%r@%h:%p"
SSH_OPTS="-o ControlMaster=auto -o ControlPath=$SSH_CONTROL_PATH -o ControlPersist=300 -o StrictHostKeyChecking=accept-new"

# Cleanup function
cleanup() {
    # Close SSH master connection
    ssh -O exit -o ControlPath="$SSH_CONTROL_PATH" "$SSH_USER@$PFSENSE_IP" 2>/dev/null || true
}
trap cleanup EXIT

# Functions
print_step() {
    echo -e "${BLUE}==>${NC} $1"
}

print_success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

show_usage() {
    echo "Usage: $0 <PFSENSE_IP> [SSH_PORT]"
    echo ""
    echo "Parameters:"
    echo "  PFSENSE_IP   pfSense IP address (required)"
    echo "  SSH_PORT     SSH port (default: 22)"
    echo ""
    echo "Environment Variables:"
    echo "  PFSENSE_SSH_USER  SSH username (default: admin)"
    echo "  NO_ROLLBACK       Set to 'true' to skip rollback on failure (for debugging)"
    echo ""
    echo "Examples:"
    echo "  $0 192.168.1.1"
    echo "  $0 10.0.0.1 2222"
    echo "  PFSENSE_SSH_USER=root $0 192.168.1.1"
    echo "  NO_ROLLBACK=true $0 192.168.1.1    # Debug mode - no cleanup on failure"
    echo ""
    echo "Prerequisite: SSH must be enabled on pfSense"
    echo "  System > Advanced > Admin Access > Enable Secure Shell"
}

# Parameter check
if [ -z "$PFSENSE_IP" ]; then
    print_error "pfSense IP address not specified!"
    echo ""
    show_usage
    exit 1
fi

# File check
check_files() {
    local missing=0
    
    local required_files=(
        "usr/local/sbin/macbind_sync.php"
        "usr/local/sbin/macbind_sync.sh"
        "usr/local/sbin/macbind_install.sh"
        "usr/local/sbin/macbind_import.php"
        "usr/local/sbin/macbind_manage.php"
        "usr/local/sbin/macbind_diagnose.sh"
        "usr/local/www/macbind_api.php"
        "usr/local/etc/macbind_api.conf.sample"
    )
    
    for file in "${required_files[@]}"; do
        if [ ! -f "$SCRIPT_DIR/$file" ]; then
            print_error "File not found: $file"
            missing=1
        fi
    done
    
    return $missing
}

# SSH connection test (and establish master connection)
test_ssh() {
    print_step "Testing SSH connection: $SSH_USER@$PFSENSE_IP:$SSH_PORT"
    print_step "Password will be asked (only once)..."
    
    if ssh -p "$SSH_PORT" $SSH_OPTS -o ConnectTimeout=10 "$SSH_USER@$PFSENSE_IP" "echo 'SSH OK'"; then
        print_success "SSH connection successful"
        return 0
    else
        print_error "SSH connection failed!"
        echo ""
        echo "Please check:"
        echo "  1. Is SSH enabled on pfSense? (System > Advanced > Admin Access)"
        echo "  2. Is the IP address correct? ($PFSENSE_IP)"
        echo "  3. Is the port correct? ($SSH_PORT)"
        echo "  4. Are username and password correct?"
        echo "  5. Do firewall rules allow SSH?"
        echo ""
        echo "Manual test:"
        echo "  ssh -p $SSH_PORT $SSH_USER@$PFSENSE_IP"
        return 1
    fi
}

# Copy files
copy_files() {
    print_step "Copying files to pfSense..."
    
    local scp_opts="-P $SSH_PORT -o ControlPath=$SSH_CONTROL_PATH"
    local target="$SSH_USER@$PFSENSE_IP"
    
    # sbin files
    print_step "  /usr/local/sbin/ files..."
    scp $scp_opts "$SCRIPT_DIR/usr/local/sbin/macbind_sync.php" "$target:/usr/local/sbin/" || return 1
    scp $scp_opts "$SCRIPT_DIR/usr/local/sbin/macbind_sync.sh" "$target:/usr/local/sbin/" || return 1
    scp $scp_opts "$SCRIPT_DIR/usr/local/sbin/macbind_install.sh" "$target:/usr/local/sbin/" || return 1
    scp $scp_opts "$SCRIPT_DIR/usr/local/sbin/macbind_import.php" "$target:/usr/local/sbin/" || return 1
    scp $scp_opts "$SCRIPT_DIR/usr/local/sbin/macbind_manage.php" "$target:/usr/local/sbin/" || return 1
    scp $scp_opts "$SCRIPT_DIR/usr/local/sbin/macbind_diagnose.sh" "$target:/usr/local/sbin/" || return 1
    print_success "  sbin files copied"
    
    # www files
    print_step "  /usr/local/www/ files..."
    scp $scp_opts "$SCRIPT_DIR/usr/local/www/macbind_api.php" "$target:/usr/local/www/" || return 1
    print_success "  www files copied"
    
    # etc files
    print_step "  /usr/local/etc/ files..."
    scp $scp_opts "$SCRIPT_DIR/usr/local/etc/macbind_api.conf.sample" "$target:/usr/local/etc/" || return 1
    print_success "  etc files copied"
    
    print_success "All files copied"
    return 0
}

# Run installation
run_install() {
    print_step "Running installation script..."
    
    # Set permissions and run install (install.sh already runs self-test)
    if ssh -p "$SSH_PORT" $SSH_OPTS "$SSH_USER@$PFSENSE_IP" "chmod +x /usr/local/sbin/macbind_*.sh /usr/local/sbin/macbind_*.php && /usr/local/sbin/macbind_install.sh"; then
        print_success "Installation completed"
        return 0
    else
        print_error "Installation failed"
        return 1
    fi
}

# Generate API key (or use existing)
generate_api_key() {
    print_step "Checking for existing API configuration..."
    
    local api_key
    local existing_key
    
    # Check if config already exists
    existing_key=$(ssh -p "$SSH_PORT" $SSH_OPTS "$SSH_USER@$PFSENSE_IP" sh << 'CHECK_CONFIG'
        if [ -f /usr/local/etc/macbind_api.conf ]; then
            grep "^api_key=" /usr/local/etc/macbind_api.conf | cut -d= -f2
        fi
CHECK_CONFIG
)
    
    if [ -n "$existing_key" ]; then
        # Config exists - use existing key
        print_success "Existing API configuration found - keeping current key"
        api_key="$existing_key"
        
        echo ""
        echo -e "${GREEN}========================================${NC}"
        echo -e "${GREEN}EXISTING API KEY (unchanged)${NC}"
        echo -e "${GREEN}========================================${NC}"
        echo ""
        echo -e "${YELLOW}$api_key${NC}"
        echo ""
        echo -e "${GREEN}========================================${NC}"
        echo ""
        
        # Output in parseable format for batch script
        echo "API_KEY_OUTPUT:$PFSENSE_IP:$api_key"
        
        # Store API key for parent script to capture
        GENERATED_API_KEY="$api_key"
        return 0
    fi
    
    # No existing config - generate new key
    print_step "Generating new API key..."
    
    api_key=$(ssh -p "$SSH_PORT" $SSH_OPTS "$SSH_USER@$PFSENSE_IP" "openssl rand -hex 32" 2>/dev/null)
    
    if [ -z "$api_key" ]; then
        print_error "Failed to generate API key"
        return 1
    fi
    
    # Store API key for parent script to capture
    GENERATED_API_KEY="$api_key"
    
    echo ""
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}NEW API KEY (for this firewall)${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo ""
    echo -e "${YELLOW}$api_key${NC}"
    echo ""
    echo -e "${GREEN}========================================${NC}"
    echo ""
    echo "SAVE THIS KEY! You will need it for Google Apps Script."
    echo ""
    
    # Output in parseable format for batch script
    echo "API_KEY_OUTPUT:$PFSENSE_IP:$api_key"
    
    # Create config file
    print_step "Creating API config file..."
    
    local created=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    
    if ssh -p "$SSH_PORT" $SSH_OPTS "$SSH_USER@$PFSENSE_IP" "cat > /usr/local/etc/macbind_api.conf << 'EOFCONFIG'
# pfSense MAC Binding API Configuration
# Created: $created

# API Key (64 character hex)
api_key=$api_key

# IP Restriction (empty = all IPs allowed, comma-separated IP list)
allowed_ips=

# Rate limiting
rate_limit_enabled=true
EOFCONFIG
chmod 600 /usr/local/etc/macbind_api.conf
chown root:wheel /usr/local/etc/macbind_api.conf"; then
        print_success "API config file created: /usr/local/etc/macbind_api.conf"
        return 0
    else
        print_error "Failed to create API config file"
        return 1
    fi
}

# API test
test_api() {
    print_step "Testing API endpoint..."
    
    local result=$(ssh -p "$SSH_PORT" $SSH_OPTS "$SSH_USER@$PFSENSE_IP" sh << 'REMOTE_TEST'
        API_KEY=$(grep "^api_key=" /usr/local/etc/macbind_api.conf | cut -d= -f2)
        curl -sk -H "X-API-Key: $API_KEY" "https://127.0.0.1/macbind_api.php?action=status"
REMOTE_TEST
)
    
    if echo "$result" | grep -q '"success":true'; then
        print_success "API endpoint is working"
        echo ""
        echo "API Response:"
        echo "$result" | python3 -m json.tool 2>/dev/null || echo "$result"
    else
        print_warning "Unexpected API response. Manual check may be required."
        echo "Response: $result"
    fi
}

# Setup cron job
setup_cron() {
    print_step "Setting up cron job..."
    
    if ssh -p "$SSH_PORT" $SSH_OPTS "$SSH_USER@$PFSENSE_IP" sh << 'CRON_SCRIPT'
        CRON_FILE="/etc/crontab.local"
        CRON_CMD="/usr/local/sbin/macbind_sync.sh"
        
        # Check if already exists
        if [ -f "$CRON_FILE" ] && grep -q "$CRON_CMD" "$CRON_FILE"; then
            echo "Cron job already configured"
        else
            # Add cron entry
            echo "* * * * * root $CRON_CMD" >> "$CRON_FILE"
            echo "Cron job added to $CRON_FILE"
        fi
CRON_SCRIPT
    then
        print_success "Cron job configured"
    else
        print_warning "Could not configure cron job - add manually"
    fi
    # Always return 0 - cron setup is non-critical (like test_api)
    return 0
}

# Rollback - remove all installed files on failure
rollback() {
    print_warning "Rolling back installation..."
    
    ssh -p "$SSH_PORT" $SSH_OPTS "$SSH_USER@$PFSENSE_IP" sh << 'ROLLBACK_SCRIPT'
        # Remove scripts
        rm -f /usr/local/sbin/macbind_sync.php
        rm -f /usr/local/sbin/macbind_sync.sh
        rm -f /usr/local/sbin/macbind_install.sh
        rm -f /usr/local/sbin/macbind_import.php
        rm -f /usr/local/sbin/macbind_manage.php
        rm -f /usr/local/sbin/macbind_diagnose.sh
        
        # Remove web API
        rm -f /usr/local/www/macbind_api.php
        
        # Remove config files
        rm -f /usr/local/etc/macbind_api.conf
        rm -f /usr/local/etc/macbind_api.conf.sample
        
        # Remove data files (only if empty/new)
        [ -f /var/db/macbind_queue.csv ] && [ ! -s /var/db/macbind_queue.csv ] && rm -f /var/db/macbind_queue.csv
        [ -f /var/db/macbind_active.json ] && rm -f /var/db/macbind_active.json
        rm -f /var/log/macbind_sync.log
        rm -f /var/log/macbind_api.log
        rm -f /var/run/macbind_sync.lock
        
        # Remove backup directory if empty
        rmdir /conf/macbind_backups 2>/dev/null || true
        
        echo "Rollback completed"
ROLLBACK_SCRIPT
    
    print_warning "Rollback completed - system restored to previous state"
}

# Next steps
show_next_steps() {
    echo ""
    echo -e "${BLUE}============================================${NC}"
    echo -e "${BLUE}NEXT STEPS${NC}"
    echo -e "${BLUE}============================================${NC}"
    echo ""
    echo "1. ADD CAPTIVE PORTAL HOOK:"
    echo "   - Services > Captive Portal > [Zone]"
    echo "   - Add hook code to your portal page"
    echo "   - See captive_portal_hook.php for the code"
    echo ""
    echo "2. DISABLE BUILT-IN AUTO ENTRY:"
    echo "   - Services > Captive Portal > [Zone]"
    echo "   - 'Pass-through MAC Auto Entry' -> Disabled"
    echo ""
    echo "3. ADD FIREWALL TO GOOGLE APPS SCRIPT:"
    echo "   - Open Dashboard"
    echo "   - Click '+ Add Firewall'"
    echo "   - URL: https://$PFSENSE_IP"
    echo "   - API Key: The key shown above"
    echo ""
    echo "NOTE: Cron job was automatically configured in /etc/crontab.local"
    echo ""
}

# Main function
main() {
    echo ""
    echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║     pfSense MAC Binding Deployment Script                  ║${NC}"
    echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "Target: $SSH_USER@$PFSENSE_IP:$SSH_PORT"
    echo ""
    
    # Track if files were copied (for rollback decision)
    local files_copied=false
    
    # 1. Check files (no rollback needed - nothing deployed yet)
    print_step "Checking required files..."
    if ! check_files; then
        print_error "Missing files. Check script directory."
        return 1
    fi
    print_success "All files present"
    
    # 2. Test SSH connection (no rollback needed - nothing deployed yet)
    if ! test_ssh; then
        return 1
    fi
    
    # 3. Copy files
    if ! copy_files; then
        print_error "Failed to copy files"
        if [ "$NO_ROLLBACK" != "true" ]; then
            rollback
        fi
        return 1
    fi
    files_copied=true
    
    # 4. Run installation
    if ! run_install; then
        print_error "Installation failed"
        if [ "$NO_ROLLBACK" != "true" ]; then
            rollback
        fi
        return 1
    fi
    
    # 5. Generate API key and create config
    if ! generate_api_key; then
        print_error "API key generation failed"
        if [ "$NO_ROLLBACK" != "true" ]; then
            rollback
        fi
        return 1
    fi
    
    # 6. Setup cron job (non-critical - no rollback on failure)
    setup_cron
    
    # 7. Test API (don't rollback on API test failure - deployment is complete)
    test_api
    
    # 8. Show next steps
    show_next_steps
    
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║     DEPLOYMENT COMPLETED!                                  ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    return 0
}

# Run script
main
