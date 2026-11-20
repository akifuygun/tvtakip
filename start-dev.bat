@echo off
rem Starts the local site at http://localhost:8000, with the PHP and MySQL that
rem setup-dev.bat installs in C:\tvtakip-dev (the versions live runs, set like
rem live's) - and XAMPP's Apache, when there is one, for phpMyAdmin at
rem http://localhost/phpmyadmin. Closing this window stops the site; MySQL runs
rem on in a window of its own (minimized) until that one is closed.
setlocal
set DEV=C:\tvtakip-dev
if not exist "%DEV%\php\php.exe" (
    echo There is no %DEV%: run setup-dev.bat first.
    exit /b 1
)

call :mysql_server
if defined SERVER goto :mysql_up
echo Starting MySQL ...
start "TVTakip MySQL" /MIN "%DEV%\mysql\bin\mysqld.exe" --console
for /L %%i in (1,1,60) do (
    call :mysql_server
    if defined SERVER goto :mysql_up
    ping -n 2 127.0.0.1 >nul
)
echo MySQL did not start: is port 3306 taken (XAMPP's MariaDB^)? Its log: %DEV%\data\*.err
exit /b 1
:mysql_up
rem Ours, not XAMPP's MariaDB, which also answers there when it runs.
echo %SERVER% | find /I "%DEV%\mysql" >nul
if errorlevel 1 (
    echo Another MySQL server is on port 3306: %SERVER%
    echo Stop it first ^(XAMPP's MariaDB: its Control Panel, or C:\xampp\mysql_stop.bat^).
    exit /b 1
)
echo MySQL %SERVER%

if exist C:\xampp\apache\bin\httpd.exe (
    tasklist /FI "IMAGENAME eq httpd.exe" | find /I "httpd.exe" >nul
    if errorlevel 1 (
        echo Starting XAMPP's Apache for phpMyAdmin at http://localhost/phpmyadmin ...
        start "" /B C:\xampp\apache\bin\httpd.exe
    )
)

echo Starting PHP's dev server at http://localhost:8000 ...
start http://localhost:8000
rem public\ is the web root; router.php, beside it, adds the pretty URLs.
rem cd first: %~dp0 ends with a backslash which would escape a closing quote
rem when passed as "-t" argument, mangling PHP's command line.
cd /d "%~dp0"
rem What live's web PHP (PHP-FPM) sets on top of its php.ini (dev\php.ini has
rem that one): these functions are disabled, files beyond the site's own are out
rem of reach, a request may take 300 s, and output is sent 4 KB at a time - so a
rem header() after the first bytes of a page works there, and so here. (The
rem \" put quotes around open_basedir's list: unquoted, PHP would take its
rem first ; for the start of a comment.)
"%DEV%\php\php.exe" -d "disable_functions=exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork" -d "open_basedir=\"%CD%;%DEV%\tmp;%DEV%\cacert.pem\"" -d max_execution_time=300 -d max_input_time=300 -d output_buffering=4096 -S localhost:8000 -t public router.php
exit /b

rem SERVER = "<version> <basedir>" of the MySQL server on port 3306 - empty
rem when none lets root in without a password.
:mysql_server
set SERVER=
"%DEV%\mysql\bin\mysql.exe" --no-defaults -h 127.0.0.1 -P 3306 -u root --connect-timeout=2 -N -r -e "SELECT @@version, @@basedir" > "%DEV%\tmp\port-3306.txt" 2>nul && set /p SERVER=<"%DEV%\tmp\port-3306.txt"
exit /b
