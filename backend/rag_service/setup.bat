@echo off
REM ============================================================================
REM  Bib RAG mikroservis — JEDNOKRATNI setup (Windows)
REM  Pravi virtuelno okruzenje i instalira zavisnosti.
REM  Pokreni dvoklikom ili iz terminala:  setup.bat
REM ============================================================================
setlocal
cd /d "%~dp0"

echo.
echo === [1/4] Provera Python-a ===
python --version >nul 2>&1
if errorlevel 1 (
    echo GRESKA: Python nije pronadjen u PATH-u. Instaliraj Python 3.10+ sa python.org
    echo i tokom instalacije cekiraj "Add Python to PATH".
    pause
    exit /b 1
)
python --version

echo.
echo === [2/4] Kreiram virtuelno okruzenje (venv) ===
if not exist "venv" (
    python -m venv venv
) else (
    echo venv vec postoji, preskacem.
)

echo.
echo === [3/4] Aktiviram venv i azuriram pip ===
call venv\Scripts\activate.bat
python -m pip install --upgrade pip

echo.
echo === [4/4] Instaliram zavisnosti ===
pip install -r requirements.txt

if not exist ".env" (
    copy ".env.example" ".env" >nul
    echo Kreiran .env iz .env.example
)

echo.
echo ============================================================================
echo  GOTOVO. Pokreni servis sa:  start.bat
echo  Preduslov za embedding (jednom):  ollama pull nomic-embed-text
echo ============================================================================
pause
endlocal
