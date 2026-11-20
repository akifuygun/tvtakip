@echo off
rem The static analysis and the tests: see scripts\check.php. deploy.bat runs
rem them as well, and deploys only when both pass. The tests need the local
rem MySQL running (start-dev.bat starts it).
if not exist C:\tvtakip-dev\php\php.exe (
    echo There is no C:\tvtakip-dev: run setup-dev.bat first.
    exit /b 1
)
C:\tvtakip-dev\php\php.exe "%~dp0scripts\check.php" %*
