#!/bin/bash
#
# Google Apps Script Deployment
# Uses clasp to push changes to GAS
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GAS_DIR="$SCRIPT_DIR/gas"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     Google Apps Script Deployment                          ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if clasp is installed
if ! command -v clasp &> /dev/null; then
    echo -e "${YELLOW}clasp is not installed.${NC}"
    echo ""
    echo "To install clasp:"
    echo "  npm install -g @google/clasp"
    echo ""
    echo "Then login:"
    echo "  clasp login"
    echo ""
    echo "Then clone your project (one-time setup):"
    echo "  cd $GAS_DIR"
    echo "  clasp clone <YOUR_SCRIPT_ID>"
    echo ""
    echo "Find your Script ID in:"
    echo "  Google Apps Script Editor → Project Settings → Script ID"
    echo ""
    exit 1
fi

# Check if .clasp.json exists (project is cloned)
if [ ! -f "$GAS_DIR/.clasp.json" ]; then
    echo -e "${YELLOW}Project not cloned yet.${NC}"
    echo ""
    echo "Run this once to link your GAS project:"
    echo "  cd $GAS_DIR"
    echo "  clasp clone <YOUR_SCRIPT_ID>"
    echo ""
    echo "Or create a new project:"
    echo "  cd $GAS_DIR"
    echo "  clasp create --title 'pfSense Management'"
    echo ""
    exit 1
fi

# Push changes
echo -e "${BLUE}Pushing changes to Google Apps Script...${NC}"
echo ""

cd "$GAS_DIR"

if clasp push; then
    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║     GAS DEPLOYMENT SUCCESSFUL!                             ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo "Files pushed:"
    ls -1 *.gs *.html 2>/dev/null | sed 's/^/  - /'
    echo ""
else
    echo ""
    echo -e "${RED}Deployment failed!${NC}"
    exit 1
fi
