#!/bin/bash

# Boje za output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

clear
echo -e "${BLUE}========================================"
echo "  Pokretanje ATVSSLibrary - Development"
echo "========================================${NC}"
echo ""

cd "$(dirname "$0")/backend" || exit

if [ ! -f "artisan" ]; then
    echo -e "${RED}GRESKA: artisan fajl nije pronadjen!${NC}"
    exit 1
fi

echo -e "${BLUE}[1/3] Ciscenje cache-a...${NC}"
php artisan config:clear
php artisan cache:clear

echo ""
echo -e "${BLUE}[2/3] Build-ovanje frontend assets...${NC}"
npm run build || echo -e "${YELLOW}UPOZORENJE: npm run build nije uspeo${NC}"

echo ""
echo -e "${BLUE}[3/3] Pokretanje PHP Backend servera...${NC}"
echo "Aplikacija ce biti dostupna na: http://localhost:8000"
echo ""

# Pokusaj da otvori browser (zavisi od OS-a)
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS
    sleep 2 && open http://localhost:8000 &
elif [[ "$OSTYPE" == "linux-gnu"* ]]; then
    # Linux
    sleep 2 && xdg-open http://localhost:8000 &
fi

echo -e "${GREEN}========================================"
echo "  SERVER USPESNO POKRENUT!"
echo "========================================${NC}"
echo "  Aplikacija: http://localhost:8000"
echo "========================================${NC}"
echo ""
echo "Za gasenje servera pritisnite Ctrl+C"
echo ""

# Pokreni server
php -S localhost:8000 -t public
