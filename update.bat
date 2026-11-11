@echo off
title ATVSSLibrary - Update
color 0E
echo.
echo ========================================
echo   ATVSSLibrary - Azuriranje Projekta
echo ========================================
echo.
echo Ova skripta se koristi NAKON git pull
echo za azuriranje zavisnosti i baze podataka.
echo.
pause

cd /d "%~dp0backend"

REM Provera da li postoji backend direktorijum
if not exist "artisan" (
    echo GRESKA: backend/artisan fajl nije pronadjen!
    pause
    exit /b 1
)

echo.
echo ========================================
echo   [1/5] Azuriranje PHP zavisnosti
echo ========================================
echo.
call composer install
if errorlevel 1 (
    echo GRESKA: composer install nije uspeo!
    pause
    exit /b 1
)

echo.
echo ========================================
echo   [2/5] Azuriranje Node.js zavisnosti
echo ========================================
echo.
call npm install
if errorlevel 1 (
    echo GRESKA: npm install nije uspeo!
    pause
    exit /b 1
)

echo.
echo ========================================
echo   [3/5] Pokretanje novih migracija
echo ========================================
echo.
call php artisan migrate
if errorlevel 1 (
    echo GRESKA: migrate nije uspeo!
    pause
    exit /b 1
)

echo.
echo ========================================
echo   [4/5] Build frontend assets
echo ========================================
echo.
call npm run build
if errorlevel 1 (
    echo UPOZORENJE: npm run build nije uspeo, ali nastavljam...
)

echo.
echo ========================================
echo   [5/5] Ciscenje cache-a
echo ========================================
echo.
call php artisan config:clear
call php artisan cache:clear
call php artisan view:clear
call php artisan route:clear

echo.
echo ========================================
echo   UPDATE USPESNO ZAVRSEN!
echo ========================================
echo.
echo Projekat je azuriran i spreman za rad!
echo.
echo Za pokretanje servera koristite: start-dev.bat
echo.
pause
