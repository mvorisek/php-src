@echo off
setlocal enableextensions enabledelayedexpansion

REM run in a new console without affecting the current env
if not "%PHP_MAHA_REALPATH_TURBO_JTUAMUC4AM2FZMR7%" == "GEDKVQPMABBRZFFV" (
    cmd /c "set "PHP_MAHA_REALPATH_TURBO_JTUAMUC4AM2FZMR7=GEDKVQPMABBRZFFV" & %~0 vc15 x64 ts & exit ^!errorlevel^!"
    cmd /c "set "PHP_MAHA_REALPATH_TURBO_JTUAMUC4AM2FZMR7=GEDKVQPMABBRZFFV" & %~0 vc15 x64 nts & exit ^!errorlevel^!"
    exit /b %errorlevel%
)

set "vc_ver=%1"  REM like vc15
set "vc_arch=%2" REM x86 or x64
set "php_zts=%3" REM ts or nts

REM configure VC env
@call "C:\Program Files (x86)\Microsoft Visual Studio\2017\Community\VC\Auxiliary\Build\vcvars%vc_arch:~1%.bat"

REM configure PHP SDK env
if not exist "%~dp0\build" (
    mkdir "%~dp0\build"
)
if not exist "%~dp0\build\php-sdk-binary-tools" (
    git clone https://github.com/microsoft/php-sdk-binary-tools "%~dp0\build\php-sdk-binary-tools"
)

@call "%~dp0\build\php-sdk-binary-tools\phpsdk-starter.bat" -c %vc_ver% -a %vc_arch% -t "REM"

set PHP_SDK_ROOT_PATH=%~dp0\build\php-sdk-binary-tools
set __VSCMD_ARG_NO_LOGO=
set VSCMD_START_DIR=
@call "%PHP_SDK_ROOT_PATH%\bin\phpsdk_setvars.bat"
REM download deps
@call phpsdk_buildtree.bat php-dev

REM initialize build
@call buildconf.bat  --force

REM run configure and compile
if "%php_zts%"=="ts" (
    @call configure.bat --disable-debug-pack --without-analyzer --enable-zlib=shared --enable-phar
) else (
    @call configure.bat --disable-debug-pack --without-analyzer --enable-zlib=shared --enable-phar --disable-zts
)

findstr "PHP_COMPILER_SHORT=VC%vc_ver:~2%" Makefile
if not "%errorlevel%" == "0" (
   echo VC version mismatch, update the VC path
   exit /b 1
)


REM nmake clean > nul
nmake

if "%php_zts%"=="ts" (
    set "PHP_REL=%~dp0x64\Release_TS"
) else (
    set "PHP_REL=%~dp0x64\Release"
)
set "PHP_EXE=%PHP_REL%\php.exe"

"%PHP_EXE%" --version
