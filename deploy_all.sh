#!/bin/bash
#
# pfSense MAC Binding - Batch Deployment Script
# Deploys to multiple pfSense firewalls
#
# Usage:
#   1. Edit firewalls.txt file (IP list)
#   2. Run ./deploy_all.sh
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FIREWALLS_FILE="$SCRIPT_DIR/firewalls.txt"

# SSH Settings
# To change username: export PFSENSE_SSH_USER=root
SSH_USER="${PFSENSE_SSH_USER:-admin}"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Create example firewalls.txt if not exists
if [ ! -f "$FIREWALLS_FILE" ]; then
    cat > "$FIREWALLS_FILE" << 'EOF'
# pfSense Firewall List
# Add one firewall per line
# Format: IP_ADDRESS [SSH_PORT] [NAME]
#
# Examples:
# 192.168.1.1
# 10.0.0.1 22 Main-Office
# 172.16.0.1 2222 Branch-1
#
# Lines starting with # are comments
# Empty lines are ignored

# --- ADD YOUR FIREWALLS BELOW ---

EOF
    echo -e "${YELLOW}firewalls.txt file created.${NC}"
    echo ""
    echo "Please edit $FIREWALLS_FILE"
    echo "and add your pfSense IP addresses, then run again."
    echo ""
    exit 0
fi

# Read firewall list into array (compatible with Bash 3.x)
FIREWALL_LINES=()
while IFS= read -r line; do
    FIREWALL_LINES+=("$line")
done < <(grep -v "^#" "$FIREWALLS_FILE" | grep -v "^$")

fw_count=${#FIREWALL_LINES[@]}

echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     pfSense MAC Binding - Batch Deployment                 ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

if [ "$fw_count" -eq 0 ]; then
    echo -e "${YELLOW}Firewall list is empty!${NC}"
    echo ""
    echo "Please edit $FIREWALLS_FILE"
    echo "and add your pfSense IP addresses."
    echo ""
    exit 0
fi

echo "Found $fw_count firewall(s)."
echo ""
echo "Firewall list:"
echo "-------------------"
for line in "${FIREWALL_LINES[@]}"; do
    echo "  - $line"
done
echo "-------------------"
echo ""

read -p "Do you want to continue? (y/N) " -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 0
fi

# Deploy to each firewall
echo ""

# Ensure script doesn't exit on errors (so counters work correctly)
set +e

success_count=0
fail_count=0
api_keys_file="$SCRIPT_DIR/api_keys_$(date +%Y%m%d_%H%M%S).txt"

echo "# API Keys - $(date)" > "$api_keys_file"
echo "# Format: IP | API_KEY | NAME" >> "$api_keys_file"
echo "" >> "$api_keys_file"

# Process each firewall
for line in "${FIREWALL_LINES[@]}"; do
    # Parse: IP [PORT] [NAME]
    ip=$(echo "$line" | awk '{print $1}')
    port=$(echo "$line" | awk '{print $2}')
    name=$(echo "$line" | awk '{$1=""; $2=""; print $0}' | xargs)
    
    [ -z "$port" ] && port="22"
    [ -z "$name" ] && name="$ip"
    
    echo ""
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}Firewall: $name ($ip:$port)${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    
    # Run deployment and capture output
    deploy_output=$("$SCRIPT_DIR/deploy_to_pfsense.sh" "$ip" "$port" 2>&1)
    deploy_result=$?
    
    # Show the output
    echo "$deploy_output"
    
    # Extract API key from output
    api_key=$(echo "$deploy_output" | grep "^API_KEY_OUTPUT:" | cut -d: -f3)
    
    if [ $deploy_result -eq 0 ]; then
        echo -e "${GREEN}[SUCCESS] $name${NC}"
        ((success_count++))
        
        if [ -n "$api_key" ]; then
            echo "$ip | $api_key | $name" >> "$api_keys_file"
        else
            echo "$ip | KEY_NOT_CAPTURED | $name" >> "$api_keys_file"
        fi
    else
        echo -e "${RED}[FAILED] $name${NC}"
        ((fail_count++))
        
        # Still try to capture API key if it was generated before failure
        if [ -n "$api_key" ]; then
            echo "$ip | $api_key | $name (PARTIAL)" >> "$api_keys_file"
        else
            echo "$ip | FAILED | $name" >> "$api_keys_file"
        fi
    fi
done

# Summary
echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     DEPLOYMENT SUMMARY                                     ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "Total:      $fw_count"
echo -e "Successful: ${GREEN}$success_count${NC}"
echo -e "Failed:     ${RED}$fail_count${NC}"
echo ""
echo "API keys saved to: $api_keys_file"
echo ""
echo "Next step: Google Apps Script setup"
echo "See: INSTALL_GAS.md for details"
echo ""
