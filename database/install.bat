@echo off
REM Import the single install.sql into MySQL / MariaDB
set MYSQL="C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe"
if not exist %MYSQL% set MYSQL="c:\xampp\mysql\bin\mysql.exe"
echo Using %MYSQL%
set /p DBNAME=Database name [whatsapp_bot]: 
if "%DBNAME%"=="" set DBNAME=whatsapp_bot
set /p DBPASS=MySQL root password: 
%MYSQL% -u root -p%DBPASS% -e "CREATE DATABASE IF NOT EXISTS `%DBNAME%` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 goto fail
%MYSQL% -u root -p%DBPASS% %DBNAME% < "%~dp0install.sql"
if errorlevel 1 goto fail
echo Imported database\install.sql into %DBNAME%.
echo Create web\includes\database.php (copy from database.php.example), then run:
echo   php database\set_admin_password.php YourPassword
pause
exit /b 0
:fail
echo Import failed. Check password / MySQL service / database name.
pause
exit /b 1
