@echo off
rem Start local dev environment: MySQL (XAMPP) + PHP built-in server.
rem Site: http://localhost:8000

tasklist /FI "IMAGENAME eq mysqld.exe" | find /I "mysqld.exe" >nul
if errorlevel 1 (
    echo Starting MySQL...
    start "" /B C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone
    timeout /t 3 /nobreak >nul
) else (
    echo MySQL already running.
)

echo Starting PHP dev server at http://localhost:8000 ...
start http://localhost:8000
C:\xampp\php\php.exe -S localhost:8000 -t "%~dp0"
