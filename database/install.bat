@echo off
REM Interactive DB import helper for MySQL 8
set MYSQL="C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe"
if not exist %MYSQL% set MYSQL="c:\xampp\mysql\bin\mysql.exe"
echo Using %MYSQL%
set /p DBPASS=MySQL root password: 
%MYSQL% -u root -p%DBPASS% < "%~dp0schema.sql"
if errorlevel 1 goto fail
%MYSQL% -u root -p%DBPASS% < "%~dp0seed.sql"
if errorlevel 1 goto fail
echo DB imported. Now create web\includes\config.local.php with the same password,
echo then run: php database\set_admin_password.php YourPassword
pause
exit /b 0
:fail
echo Import failed. Check password / MySQL service.
pause
exit /b 1
