@echo off
setlocal enabledelayedexpansion
rem Χωρίς αυτό, τα ελληνικά μηνύματα βγαίνουν σκουπίδια: το cmd.exe ξεκινά
rem σε codepage 437/1253 και το αρχείο είναι UTF-8. Η παλιά επαναφέρεται στο τέλος.
for /f "tokens=2 delims=:" %%i in ('chcp') do set ECRM_OLD_CP=%%i
chcp 65001 >nul
rem ---------------------------------------------------------------------------
rem  Energy CRM - τα integration tests, με τη βάση τους.
rem
rem  ΓΙΑΤΙ ΥΠΑΡΧΕΙ: το `set` στο cmd.exe ζει μόνο σε ΕΚΕΙΝΟ το παράθυρο. Κάθε νέο
rem  Site Shell ξεκινά χωρίς ECRM_TEST_DB_NAME, και το tests/Integration/
rem  bootstrap.php σταματά επίτηδες - δεν έχει default, γιατί ο,τι βάλει κανείς
rem  εκεί ΔΙΑΓΡΑΦΕΤΑΙ. Ολόκληρο. Δες docs/TESTING.md.
rem
rem  Τα στοιχεία σύνδεσης ΔΕΝ γράφονται εδώ. Διαβάζονται από το wp-config.php του
rem  ίδιου του site μέσω WP-CLI, ώστε να μη σαπίσουν όταν αλλάξει η θύρα του
rem  Local - που αλλάζει.
rem
rem  Χρήση, μέσα από το Site Shell:
rem      tools\test-db.cmd            τρέχει τα integration
rem      tools\test-db.cmd all        τρέχει phpcs + phpstan + unit + integration
rem ---------------------------------------------------------------------------

cd /d "%~dp0.."

rem --- Η βάση δοκιμών. Ξεχωριστή. Ποτέ η βάση του site. ----------------------
if "%ECRM_TEST_DB_NAME%"=="" set ECRM_TEST_DB_NAME=energy_crm_tests

rem --- Ο,τι δεν δηλώθηκε, το ρωτάμε από το ίδιο το WordPress ------------------
where wp >nul 2>&1
if errorlevel 1 (
  echo.
  echo   Το WP-CLI ^(wp^) δεν βρεθηκε στο PATH.
  echo   Τρεξε αυτο μεσα απο το Site Shell του Local, οχι απο σκετο cmd.
  echo.
  exit /b 1
)

for /f "usebackq delims=" %%i in (`wp config get DB_USER 2^>nul`) do set SITE_DB_USER=%%i
for /f "usebackq delims=" %%i in (`wp config get DB_PASSWORD 2^>nul`) do set SITE_DB_PASS=%%i
for /f "usebackq delims=" %%i in (`wp config get DB_HOST 2^>nul`) do set SITE_DB_HOST=%%i
for /f "usebackq delims=" %%i in (`wp config get DB_NAME 2^>nul`) do set SITE_DB_NAME=%%i

if "%ECRM_TEST_DB_USER%"==""     set ECRM_TEST_DB_USER=%SITE_DB_USER%
if "%ECRM_TEST_DB_PASSWORD%"=="" set ECRM_TEST_DB_PASSWORD=%SITE_DB_PASS%
if "%ECRM_TEST_DB_HOST%"==""     set ECRM_TEST_DB_HOST=%SITE_DB_HOST%

rem --- Το δίχτυ, δευτερη φορα. Το bootstrap το ξανακανει, και καλα κανει. -----
if /i "%ECRM_TEST_DB_NAME%"=="%SITE_DB_NAME%" (
  echo.
  echo   ΣΤΑΜΑΤΗΜΑ: η βαση δοκιμων ειναι η ΙΔΙΑ με του site ^("%SITE_DB_NAME%"^).
  echo   Θα εσβηνε καθε πελατη, συμβαση και υπογραφη. Αλλαξε το ECRM_TEST_DB_NAME.
  echo.
  exit /b 1
)

rem --- Η PHP του Local δεν ειναι παντα στο PATH της δευτερης διεργασιας -------
if "%ECRM_TEST_PHP_BINARY%"=="" (
  for /f "usebackq delims=" %%i in (`where php 2^>nul`) do (
    if "!ECRM_TEST_PHP_BINARY!"=="" set ECRM_TEST_PHP_BINARY=%%i
  )
)

echo   βαση δοκιμων : %ECRM_TEST_DB_NAME%   ^(του site: %SITE_DB_NAME%^)
echo   host / user  : %ECRM_TEST_DB_HOST% / %ECRM_TEST_DB_USER%
echo   php          : %ECRM_TEST_PHP_BINARY%
echo.

rem --- Η βαση φτιαχνεται αν λειπει. Δεν αδειαζει εδω: το κανει το WordPress. --
wp db query "CREATE DATABASE IF NOT EXISTS %ECRM_TEST_DB_NAME% DEFAULT CHARACTER SET utf8mb4" >nul 2>&1
if errorlevel 1 (
  echo   Δεν μπορεσα να φτιαξω τη βαση. Δοκιμασε απο το Adminer:
  echo       CREATE DATABASE %ECRM_TEST_DB_NAME%;
  echo.
)

if /i "%1"=="all" (
  composer check:all
) else (
  composer test:integration
)

set ECRM_EXIT=%errorlevel%
chcp %ECRM_OLD_CP% >nul 2>&1
endlocal & exit /b %ECRM_EXIT%
