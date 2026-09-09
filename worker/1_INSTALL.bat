@echo off
setlocal EnableDelayedExpansion
cd /d "%~dp0"
echo === WhatsApp Worker Install ===

set "PYEXE="

REM 1) Prefer known real install locations
if exist "%LocalAppData%\Programs\Python\Python314\python.exe" set "PYEXE=%LocalAppData%\Programs\Python\Python314\python.exe"
if not defined PYEXE if exist "%LocalAppData%\Programs\Python\Python313\python.exe" set "PYEXE=%LocalAppData%\Programs\Python\Python313\python.exe"
if not defined PYEXE if exist "%LocalAppData%\Programs\Python\Python312\python.exe" set "PYEXE=%LocalAppData%\Programs\Python\Python312\python.exe"

REM 2) Or first non-WindowsApps python on PATH
if not defined PYEXE (
  for /f "delims=" %%i in ('where python 2^>nul') do (
    echo %%i | find /i "WindowsApps" >nul
    if errorlevel 1 (
      if not defined PYEXE set "PYEXE=%%i"
    )
  )
)

if not defined PYEXE (
  echo.
  echo ERROR: Real Python was not found.
  echo Installed Python may exist, but Windows Store alias is blocking it.
  echo.
  echo Fix A: Settings - Apps - Advanced app settings - App execution aliases
  echo         Turn OFF "python.exe" and "python3.exe"
  echo Fix B: Open a NEW Command Prompt after install and try again.
  echo.
  pause
  exit /b 1
)

echo Using: %PYEXE%
"%PYEXE%" --version
if errorlevel 1 (
  echo Python failed to run.
  pause
  exit /b 1
)

"%PYEXE%" -m venv .venv
if errorlevel 1 (
  echo Failed to create .venv
  pause
  exit /b 1
)

call .venv\Scripts\activate.bat
.venv\Scripts\python.exe -m pip install --upgrade pip
if errorlevel 1 (
  echo pip upgrade failed
  pause
  exit /b 1
)
.venv\Scripts\python.exe -m pip install -r requirements.txt
if errorlevel 1 (
  echo requirements install failed
  pause
  exit /b 1
)
.venv\Scripts\python.exe -m playwright install chromium
if errorlevel 1 (
  echo playwright browser install failed
  pause
  exit /b 1
)

echo.
echo Install OK.
echo Next:
echo   1. edit config.ini and set worker_token from Admin - Workers
echo   2. run 2_LINK_WHATSAPP.bat
echo   3. run 3_START_WORKER.bat
pause
