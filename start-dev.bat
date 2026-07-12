@echo off
rem Start local dev environment: MySQL + Apache (phpMyAdmin) + PHP built-in server.
rem Site: http://localhost:8000  |  phpMyAdmin: http://localhost/phpmyadmin

tasklist /FI "IMAGENAME eq mysqld.exe" | find /I "mysqld.exe" >nul
if errorlevel 1 (
    echo Starting MySQL...
    start "" /B C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone
    timeout /t 3 /nobreak >nul
) else (
    echo MySQL already running.
)

tasklist /FI "IMAGENAME eq httpd.exe" | find /I "httpd.exe" >nul
if errorlevel 1 (
    echo Starting Apache for phpMyAdmin at http://localhost/phpmyadmin ...
    start "" /B C:\xampp\apache\bin\httpd.exe
) else (
    echo Apache already running.
)

echo Starting PHP dev server at http://localhost:8000 ...
start http://localhost:8000
rem cd first: %~dp0 ends with a backslash which would escape a closing quote
rem when passed as "-t" argument, mangling PHP's command line.
cd /d "%~dp0"
C:\xampp\php\php.exe -S localhost:8000 router.php
