@echo off
title ATVSSLibrary - Prvi Setup
color 0B
echo.
echo ========================================
echo   ATVSSLibrary - Inicijalno Podesavanje
echo ========================================
echo.
echo Ova skripta ce instalirati projekat od nule.
echo Koristi se samo PRVI PUT nakon kloniranja.
echo.
pause

cd /d "%~dp0backend"

REM Provera da li postoji backend direktorijum
if not exist "artisan" (
    echo GRESKA: backend/artisan fajl nije pronadjen!
    echo Da li ste u pravom direktorijumu?
    pause
    exit /b 1
)

echo.
echo ========================================
echo   [1/7] Instalacija PHP zavisnosti
echo ========================================
echo.
call composer install --ignore-platform-req=ext-gd
if errorlevel 1 (
    echo GRESKA: composer install nije uspeo!
    pause
    exit /b 1
)

echo.
echo ========================================
echo   [2/7] Instalacija Node.js zavisnosti
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
echo   [3/7] Kreiranje .env fajla
echo ========================================
echo.
if exist ".env" (
    echo .env vec postoji, preskacemo kopiranje.
) else (
    if exist ".env.example" (
        copy .env.example .env
        echo .env fajl kreiran!
    ) else (
        echo UPOZORENJE: .env.example ne postoji!
    )
)

echo.
echo ========================================
echo   [4/7] Generisanje aplikacionog kljuca
echo ========================================
echo.
call php artisan key:generate
if errorlevel 1 (
    echo GRESKA: key:generate nije uspeo!
    pause
    exit /b 1
)

echo.
echo ========================================
echo   [5/7] Kreiranje baze i pokretanje migracija
echo ========================================
echo.
call php artisan migrate:fresh --seed
if errorlevel 1 (
    echo GRESKA: migrate:fresh --seed nije uspeo!
    pause
    exit /b 1
)

echo.
echo ========================================
echo   [6/7] Build frontend assets
echo ========================================
echo.
call npm run build
if errorlevel 1 (
    echo UPOZORENJE: npm run build nije uspeo, ali nastavljam...
)

echo.
echo ========================================
echo   [7/7] Ciscenje cache-a
echo ========================================
echo.
call php artisan config:clear
call php artisan cache:clear

echo.
echo ========================================
echo   SETUP USPESNO ZAVRSEN!
echo ========================================
echo.
echo Projekat je spreman za rad!
echo.
echo SLEDECI KORACI:
echo   1. Za pokretanje: start-dev.bat
echo   2. Za gasenje:    stop-dev.bat
echo   3. Za update:     update.bat (posle git pull)
echo.
echo Default korisnici:
echo   Admin i bibliotekar: nalozi iz backend\database\seeders\UserSeeder.php
echo   Clan:   jovana@example.com / clan123
echo   Clan:   stefan@example.com / clan123
echo.
pause
