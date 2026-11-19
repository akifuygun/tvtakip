@echo off
rem Deploy main to the live site and check it: see scripts\deploy.php.
rem   deploy           deploy, then check the live site
rem   deploy --check   only check the live site as it is
C:\xampp\php\php.exe "%~dp0scripts\deploy.php" %*
