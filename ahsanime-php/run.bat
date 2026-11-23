@echo off
REM ============================================================================
REM   AHSANIME (PHP) - Run script for Windows
REM ============================================================================
REM   Usage:
REM     run.bat            -> start on http://localhost:8080
REM     run.bat 9000       -> start on a custom port
REM
REM   Requirements: PHP 8.1+ on PATH, OR XAMPP.
REM   How to get PHP on Windows:
REM     - Easiest: install XAMPP (https://www.apachefriends.org) and enable Apache.
REM     - Or: download PHP from https://windows.php.net/download, unzip, and add
REM           the folder to PATH (so "php" works in cmd).
REM   Data/images: AniList.co . Streaming: public providers . Subs: Gemini
REM ============================================================================
setlocal enabledelayedexpansion

set "PORT=%~1"
if "%PORT%"=="" set "PORT=8080"

cd /d "%~dp0"

REM --- locate php.exe ---------------------------------------------------------
set "PHP_BIN="
where php >nul 2>nul
if %errorlevel%==0 (
  set "PHP_BIN=php"
) else (
  REM Fall back to common XAMPP install path
  if exist "C:\xampp\php\php.exe" set "PHP_BIN=C:\xampp\php\php.exe"
)

if "%PHP_BIN%"=="" (
  echo ERROR: php.exe not found.
  echo   Install XAMPP:  https://www.apachefriends.org
  echo   Or add PHP to PATH so "php" works in cmd.
  pause
  exit /b 1
)

REM --- show version -----------------------------------------------------------
"%PHP_BIN%" -v

REM --- ensure cache dir exists ------------------------------------------------
if not exist "cache" mkdir "cache"
if not exist "cache\stream" mkdir "cache\stream"

REM --- .env hint (Gemini subtitle translation) -------------------------------
if not exist ".env" if not exist "..\.env" (
  echo NOTE: no .env found. Subtitle translation ^(Gemini^) will be disabled;
  echo        original English captions still work.
  echo        Create .env with:  GOOGLE_TRANSLATE_API_KEY=your_key_here
)

echo.
echo   ============================================================
echo    AHSANIME is starting...
echo    Open in browser:  http://localhost:%PORT%
echo    Press Ctrl+C to stop the server.
echo   ============================================================
echo.

REM --- start the PHP built-in dev server (with the router) -------------------
"%PHP_BIN%" -S "localhost:%PORT%" router.php

endlocal