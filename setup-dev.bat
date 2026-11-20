@echo off
rem Install or update the local PHP and MySQL in C:\tvtakip-dev: the versions
rem live runs, set like live's. See dev\setup.ps1.
rem   setup-dev                                          the versions live runs
rem   setup-dev -PhpVersion 8.3.37 -MysqlVersion 8.0.43  these, without asking live
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0dev\setup.ps1" %*
