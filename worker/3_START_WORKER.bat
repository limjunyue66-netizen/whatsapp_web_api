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
echo Starting worker loop...
.venv\Scripts\python.exe main.py
set ERR=%ERRORLEVEL%
if not "%ERR%"=="0" (
  echo Worker exited with code %ERR%
)
pause
exit /b %ERR%
