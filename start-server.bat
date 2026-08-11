@echo off
title XAMPP Server Start - Stock Register
color 0A

echo =====================================
echo   STOCK-ARB - Server Start
echo =====================================
echo.

echo [1/3] Starting Apache...
start "" "C:\xamppp\apache_start.bat"
timeout /t 3 /nobreak >nul

echo [2/3] Starting MySQL...
start "" "C:\xamppp\mysql_start.bat"
timeout /t 3 /nobreak >nul

echo [3/3] Opening browser...
start "" "http://localhost/stock-register"

echo.
echo =====================================
echo   Server Started Successfully!
echo   URL: http://localhost/stock-register
echo =====================================
echo.
pause
