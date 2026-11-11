@echo off
REM ============================================================================
REM  Bib RAG mikroservis — POKRETANJE (Windows)
REM  Pokreni svaki put kad testiras chatbota:  start.bat
REM  Servis slusa na http://127.0.0.1:8001  (health: /health)
REM  Prekid: Ctrl+C
REM ============================================================================
setlocal
cd /d "%~dp0"

if not exist "venv" (
    echo GRESKA: venv ne postoji. Pokreni prvo setup.bat
    pause
    exit /b 1
)

call venv\Scripts\activate.bat

REM Host/port se citaju iz .env (RAG_HOST / RAG_PORT) preko main.py
python main.py

endlocal
