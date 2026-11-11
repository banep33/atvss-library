#!/bin/bash

# Boje za output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

clear
echo -e "${BLUE}========================================"
echo "  ATVSSLibrary - Inicijalno Podesavanje"
echo "========================================${NC}"
echo ""
echo "Ova skripta ce instalirati projekat od nule."
echo "Koristi se samo PRVI PUT nakon kloniranja."
echo ""
read -p "Pritisnite Enter za nastavak..."

cd "$(dirname "$0")/backend" || exit

# Provera da li postoji artisan
if [ ! -f "artisan" ]; then
    echo -e "${RED}GRESKA: artisan fajl nije pronadjen!${NC}"
    exit 1
fi

echo ""
echo -e "${BLUE}[1/7] Instalacija PHP zavisnosti${NC}"
composer install || { echo -e "${RED}GRESKA: composer install${NC}"; exit 1; }

echo ""
echo -e "${BLUE}[2/7] Instalacija Node.js zavisnosti${NC}"
npm install || { echo -e "${RED}GRESKA: npm install${NC}"; exit 1; }

echo ""
echo -e "${BLUE}[3/7] Kreiranje .env fajla${NC}"
if [ -f ".env" ]; then
    echo ".env vec postoji, preskacemo kopiranje."
else
    cp .env.example .env && echo ".env fajl kreiran!"
fi

echo ""
echo -e "${BLUE}[4/7] Generisanje aplikacionog kljuca${NC}"
php artisan key:generate || { echo -e "${RED}GRESKA: key:generate${NC}"; exit 1; }

echo ""
echo -e "${BLUE}[5/7] Kreiranje baze i pokretanje migracija${NC}"
php artisan migrate:fresh --seed || { echo -e "${RED}GRESKA: migrate:fresh --seed${NC}"; exit 1; }

echo ""
echo -e "${BLUE}[6/7] Build frontend assets${NC}"
npm run build || echo -e "${YELLOW}UPOZORENJE: npm run build nije uspeo${NC}"

echo ""
echo -e "${BLUE}[7/7] Ciscenje cache-a${NC}"
php artisan config:clear
php artisan cache:clear

echo ""
echo -e "${GREEN}========================================"
echo "  SETUP USPESNO ZAVRSEN!"
echo "========================================${NC}"
echo ""
echo "Projekat je spreman za rad!"
echo ""
echo "SLEDECI KORACI:"
echo "  1. Za pokretanje: ./start-dev.sh"
echo "  2. Za gasenje:    Ctrl+C"
echo "  3. Za update:     ./update.sh (posle git pull)"
echo ""
echo "Default korisnici:"
echo "  Admin i bibliotekar: nalozi iz backend/database/seeders/UserSeeder.php"
echo "  Clan:   jovana@example.com / clan123"
echo "  Clan:   stefan@example.com / clan123"
echo ""
read -p "Pritisnite Enter za kraj..."
