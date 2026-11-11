@echo off
echo.
echo ========================================
echo   Zaustavljanje servera...
echo ========================================
echo.

echo Zaustavaljanje PHP servera (port 8000)...
for /f "tokens=5" %%a in ('netstat -ano ^| findstr :8000') do (
    taskkill /PID %%a /F 2>nul
)

echo Zaustavaljanje Node servera (port 5173)...
for /f "tokens=5" %%a in ('netstat -ano ^| findstr :5173') do (
    taskkill /PID %%a /F 2>nul
)

echo.
echo ========================================
echo   Serveri zaustavljeni!
echo ========================================
echo.
pause
