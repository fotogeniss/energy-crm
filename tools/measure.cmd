@echo off
setlocal enabledelayedexpansion

rem Energy CRM -- composer check:all + dashboard query-count measurement,
rem in one command. Why: docs/CHANGELOG.md (169).
rem Usage, from the Local Site Shell:
rem   tools\measure.cmd        composer check:all + dashboard measurement
rem   tools\measure.cmd all    the above, plus wizard-smoke

for /f "tokens=2 delims=:" %%i in ('chcp') do set ECRM_OLD_CP=%%i
chcp 65001 >nul

cd /d "%~dp0.."

if not exist docs\_measure mkdir docs\_measure

set OUT=docs\_measure\last-check.tmp.txt
set DASHOUT=docs\_measure\dashboard.tmp.txt

where wp >nul 2>&1
if errorlevel 1 (
  echo.
  echo   wp-cli not found in PATH. Run this from the Local Site Shell.
  echo.
  set ECRM_EXIT=1
  goto :restore_and_exit
)

echo Energy CRM -- composer check:all> "%OUT%"
echo %DATE% %TIME%>> "%OUT%"
echo.>> "%OUT%"

echo Running composer check:all ...
call composer check:all >> "%OUT%" 2>&1
set ECRM_CHECK_RESULT=%errorlevel%

if %ECRM_CHECK_RESULT% neq 0 (
  echo   FAILED. See %OUT%
) else (
  echo   OK.
)

echo.
echo Running dashboard measurement ...
echo Energy CRM -- dashboard measurement> "%DASHOUT%"
echo %DATE% %TIME%>> "%DASHOUT%"
echo.>> "%DASHOUT%"
call wp eval-file tools\measure-dashboard.php >> "%DASHOUT%" 2>&1
set ECRM_DASH_RESULT=%errorlevel%

if %ECRM_DASH_RESULT% neq 0 (
  echo   Measurement failed or produced no output. See %DASHOUT%
) else (
  echo   OK. See %DASHOUT%
)

if /i "%1"=="all" (
  echo.
  echo Running wizard-smoke ...
  echo Energy CRM -- wizard-smoke> docs\_measure\wizard-smoke.tmp.txt
  echo %DATE% %TIME%>> docs\_measure\wizard-smoke.tmp.txt
  echo.>> docs\_measure\wizard-smoke.tmp.txt
  pushd tools\wizard-smoke
  call npm test >> ..\..\docs\_measure\wizard-smoke.tmp.txt 2>&1
  set ECRM_SMOKE_RESULT=!errorlevel!
  popd
  if !ECRM_SMOKE_RESULT! neq 0 (
    echo   FAILED. See docs\_measure\wizard-smoke.tmp.txt
  ) else (
    echo   OK.
  )
) else (
  set ECRM_SMOKE_RESULT=0
)

echo.
echo Full output written to:
echo   %OUT%
echo   %DASHOUT%
if /i "%1"=="all" echo   docs\_measure\wizard-smoke.tmp.txt
echo.

set /a ECRM_TOTAL=%ECRM_CHECK_RESULT%+%ECRM_SMOKE_RESULT%
if %ECRM_TOTAL% neq 0 (
  set ECRM_EXIT=1
) else (
  set ECRM_EXIT=0
)

:restore_and_exit
chcp %ECRM_OLD_CP% >nul 2>&1
endlocal & exit /b %ECRM_EXIT%
