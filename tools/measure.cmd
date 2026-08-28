@echo off
setlocal enabledelayedexpansion
rem Χωρίς αυτό, τα ελληνικά μηνύματα βγαίνουν σκουπίδια -- ίδιος λόγος με το
rem tools\test-db.cmd: το cmd.exe ξεκινά σε codepage 437/1253 και τα αρχεία
rem είναι UTF-8. Η παλιά επαναφέρεται στο τέλος.
for /f "tokens=2 delims=:" %%i in ('chcp') do set ECRM_OLD_CP=%%i
chcp 65001 >nul
rem ---------------------------------------------------------------------------
rem  Energy CRM -- ένα κουμπί για τις τρεις-τέσσερις εντολές που τρέχουμε σε
rem  κάθε συνεδρία δουλειάς: composer check:all, wizard-smoke, μέτρηση βάσης.
rem
rem  ΓΙΑΤΙ ΥΠΑΡΧΕΙ: η πλήρης έξοδος αυτών των εντολών περνούσε μέχρι τώρα με
rem  αντιγραφή-επικόλληση στη συνομιλία -- και το τερματικό την κόβει σε
rem  μεγάλες σουίτες, ή το cmd.exe/PowerShell περικόπτει το scrollback. Αυτό
rem  εδώ γράφει την ΠΛΗΡΗ έξοδο σε αρχείο κάτω από docs\_measure\, που
rem  διαβάζεται ολόκληρο και ακριβές -- ίδιο σκεπτικό με το γιατί το
rem  tools\check-suite.php γράφει σε *.tmp.txt αντί να βασίζεται σε pipe.
rem
rem  Το docs\_measure\ είναι gitignored (*.tmp.txt, ήδη καλυμμένο) -- δεν
rem  μπαίνει ποτέ σε commit, είναι μόνο για να ΜΕΤΡΗΘΕΙ.
rem
rem  Χρήση, μέσα από το Site Shell:
rem      tools\measure.cmd            σουίτα (composer check:all) + μέτρηση dashboard
rem      tools\measure.cmd all        το παραπάνω + wizard-smoke
rem ---------------------------------------------------------------------------

cd /d "%~dp0.."

if not exist docs\_measure mkdir docs\_measure

set OUT=docs\_measure\last-check.tmp.txt
set DASHOUT=docs\_measure\dashboard.tmp.txt

where wp >nul 2>&1
if errorlevel 1 (
  echo.
  echo   Το WP-CLI ^(wp^) δεν βρεθηκε στο PATH.
  echo   Τρεξε αυτο μεσα απο το Site Shell του Local, οχι απο σκετο cmd.
  echo.
  goto :restore_and_exit_error
)

echo   Energy CRM -- composer check:all > "%OUT%"
echo   %DATE% %TIME% >> "%OUT%"
echo. >> "%OUT%"

echo   Τρεχει composer check:all ...
call composer check:all >> "%OUT%" 2>&1
set ECRM_CHECK_RESULT=%errorlevel%

if %ECRM_CHECK_RESULT% neq 0 (
  echo   ---^> ΑΠΕΤΥΧΕ. Δες %OUT%
) else (
  echo   ---^> OK.
)

echo.
echo   Τρεχει η μετρηση dashboard ^(tools\measure-dashboard.php^) ...
echo   Energy CRM -- μετρηση dashboard > "%DASHOUT%"
echo   %DATE% %TIME% >> "%DASHOUT%"
echo. >> "%DASHOUT%"
call wp eval-file tools\measure-dashboard.php >> "%DASHOUT%" 2>&1
set ECRM_DASH_RESULT=%errorlevel%

if %ECRM_DASH_RESULT% neq 0 (
  echo   ---^> Η μετρηση απεστυχε ή δεν εβγαλε αποτελεσμα. Δες %DASHOUT%
) else (
  echo   ---^> OK. Δες %DASHOUT%
)

rem --- Το wizard-smoke τρεχει μονο με ρητο "all": χρειαζεται node_modules  ---
rem     και δεν αγγιζεται σε καθε αλλαγη -- μονο οταν αλλαξε το ecrm-form.js.
if /i "%1"=="all" (
  echo.
  echo   Τρεχει wizard-smoke ...
  echo   Energy CRM -- wizard-smoke > docs\_measure\wizard-smoke.tmp.txt
  echo   %DATE% %TIME% >> docs\_measure\wizard-smoke.tmp.txt
  echo. >> docs\_measure\wizard-smoke.tmp.txt
  pushd tools\wizard-smoke
  call npm test >> ..\..\docs\_measure\wizard-smoke.tmp.txt 2>&1
  set ECRM_SMOKE_RESULT=!errorlevel!
  popd
  if !ECRM_SMOKE_RESULT! neq 0 (
    echo   ---^> ΑΠΕΤΥΧΕ. Δες docs\_measure\wizard-smoke.tmp.txt
  ) else (
    echo   ---^> OK.
  )
) else (
  set ECRM_SMOKE_RESULT=0
)

echo.
echo   Πληρης εξοδος:
echo     %OUT%
echo     %DASHOUT%
if /i "%1"=="all" echo     docs\_measure\wizard-smoke.tmp.txt
echo.

set /a ECRM_TOTAL=%ECRM_CHECK_RESULT%+%ECRM_SMOKE_RESULT%
if %ECRM_TOTAL% neq 0 (
  set ECRM_EXIT=1
) else (
  set ECRM_EXIT=0
)
goto :restore_and_exit

:restore_and_exit_error
set ECRM_EXIT=1

:restore_and_exit
chcp %ECRM_OLD_CP% >nul 2>&1
endlocal & exit /b %ECRM_EXIT%
