@echo off
rem Deploy main to the live site and check it: see scripts\deploy.php.
rem   deploy           deploy, then check the live site
rem   deploy --check   only check the live site as it is
rem It runs on the PHP setup-dev.bat installs, the version live runs.
if not exist C:\tvtakip-dev\php\php.exe (
    echo There is no C:\tvtakip-dev: run setup-dev.bat first.
    exit /b 1
)
C:\tvtakip-dev\php\php.exe "%~dp0scripts\deploy.php" %*
