#!/bin/bash

# Boje za output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

clear
echo -e "${BLUE}========================================"
echo "  ATVSSLibrary - Azuriranje Projekta"
echo "========================================${NC}"
echo ""
echo "Ova skripta se koristi NAKON git pull"
echo "za azuriranje zavisnosti i baze podataka."
echo ""
read -p "Pritisnite Enter za nastavak..."

cd "$(dirname "$0")/backend" || exit

# Provera da li postoji artisan
if [ ! -f "artisan" ]; then
    echo -e "${RED}GRESKA: artisan fajl nije pronadjen!${NC}"
    exit 1
fi

echo ""
echo -e "${BLUE}[1/5] Azuriranje PHP zavisnosti${NC}"
composer install || { echo -e "${RED}GRESKA: composer install${NC}"; exit 1; }

echo ""
echo -e "${BLUE}[2/5] Azuriranje Node.js zavisnosti${NC}"
npm install || { echo -e "${RED}GRESKA: npm install${NC}"; exit 1; }

echo ""
echo -e "${BLUE}[3/5] Pokretanje novih migracija${NC}"
php artisan migrate || { echo -e "${RED}GRESKA: migrate${NC}"; exit 1; }

echo ""
echo -e "${BLUE}[4/5] Build frontend assets${NC}"
npm run build || echo -e "${YELLOW}UPOZORENJE: npm run build nije uspeo${NC}"

echo ""
echo -e "${BLUE}[5/5] Ciscenje cache-a${NC}"
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

echo ""
echo -e "${GREEN}========================================"
echo "  UPDATE USPESNO ZAVRSEN!"
echo "========================================${NC}"
echo ""
echo "Projekat je azuriran i spreman za rad!"
echo ""
echo "Za pokretanje servera koristite: ./start-dev.sh"
echo ""
read -p "Pritisnite Enter za kraj..."
