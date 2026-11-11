@echo off
title ATVSSLibrary Dev Server
color 0A
echo.
echo ========================================
echo   Pokretanje ATVSSLibrary - Development
echo ========================================
echo.
cd /d "%~dp0backend"
if not exist "artisan" (
    echo GRESKA: artisan fajl nije pronadjen!
    pause
    exit /b 1
)

echo [1/5] Kesiranje konfiguracije i ruta...
call php artisan config:cache
call php artisan route:cache
call php artisan view:cache
echo OK.
echo.

echo [2/5] Build-ovanje frontend assets...
call npm run build
if errorlevel 1 (
    echo UPOZORENJE: npm run build nije uspeo, ali nastavljam...
)
echo.

echo [3/5] Pokretanje Bib RAG mikroservisa (pretraga knjiga u chatbotu)...
netstat -ano | findstr /r ":8001.*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo RAG servis je VEC pokrenut na http://127.0.0.1:8001 - preskacem.
) else if exist "%~dp0backend\rag_service\venv" (
    start "ATVSSLibrary RAG servis" cmd /k "cd /d "%~dp0backend\rag_service" && call venv\Scripts\activate.bat && python main.py"
    echo RAG servis pokrenut na http://127.0.0.1:8001
) else (
    echo UPOZORENJE: rag_service\venv ne postoji - pokreni backend\rag_service\setup.bat
    echo             Bez RAG servisa chatbot ne moze semanticki da pretrazuje katalog.
)
echo.

echo [4/5] Pokretanje PHP Backend servera (4 workera)...
echo Aplikacija ce biti dostupna na: http://localhost:8000
echo.
set PHP_CLI_SERVER_WORKERS=4
start "ATVSSLibrary Backend" cmd /k "cd /d "%~dp0backend" && set PHP_CLI_SERVER_WORKERS=4 && php artisan serve --no-reload"
echo.

echo [5/5] Otvaranje browsera...
timeout /t 3 /nobreak >nul
start http://localhost:8000
echo.
echo ========================================
echo   SERVER USPESNO POKRENUT!
echo ========================================
echo   Aplikacija: http://localhost:8000
echo ========================================
echo.
echo Za gasenje servera pokrenite stop-dev.bat
echo ili zatvorite prozor servera.
echo.
pause