@echo off
setlocal
cd /d "%~dp0"

if not exist config.ini (
  echo Missing config.ini — create worker\config.ini and set worker_token.
  pause
  exit /b 1
)

if not exist .venv\Scripts\python.exe (
  echo Missing .venv — run 1_INSTALL.bat first after installing real Python.
  pause
  exit /b 1
)

call .venv\Scripts\activate.bat
echo Opening WhatsApp Web for QR linking. Scan with your phone.
echo Do NOT close until it says connected.
.venv\Scripts\python.exe main.py --link
set ERR=%ERRORLEVEL%
if not "%ERR%"=="0" (
  echo Link script exited with code %ERR%
)
pause
exit /b %ERR%
