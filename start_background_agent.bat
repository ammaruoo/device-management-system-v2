@echo off
REM Background Agent Startup Script for Windows
REM تشغيل Background Agent لنظام إدارة الأجهزة

echo ===========================================
echo    Background Agent - وكيل الخلفية
echo    نظام إدارة الأجهزة
echo ===========================================
echo.

REM التحقق من وجود PHP
php --version >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] PHP غير مثبت أو غير موجود في PATH
    echo يرجى تثبيت PHP وإضافته للـ PATH
    pause
    exit /b 1
)

REM التحقق من وجود ملف background_agent.php
if not exist "background_agent.php" (
    echo [ERROR] ملف background_agent.php غير موجود
    echo يرجى التأكد من وجود الملف في نفس المجلد
    pause
    exit /b 1
)

REM عرض القائمة الرئيسية
:menu
cls
echo ===========================================
echo           Background Agent Menu
echo ===========================================
echo.
echo 1. تشغيل الـ Agent في الخلفية
echo 2. إيقاف الـ Agent
echo 3. عرض حالة الـ Agent
echo 4. تشغيل للاختبار (واجهة أمامية)
echo 5. عرض السجل
echo 6. تنظيف السجلات القديمة
echo 7. خروج
echo.
set /p choice="اختر رقم من القائمة: "

if "%choice%"=="1" goto start_agent
if "%choice%"=="2" goto stop_agent
if "%choice%"=="3" goto status_agent
if "%choice%"=="4" goto run_agent
if "%choice%"=="5" goto show_logs
if "%choice%"=="6" goto cleanup_logs
if "%choice%"=="7" goto exit_script

echo خيار غير صحيح، يرجى المحاولة مرة أخرى
timeout /t 2 >nul
goto menu

:start_agent
echo.
echo بدء تشغيل Background Agent...
php background_agent.php start
echo.
if %errorlevel% equ 0 (
    echo [SUCCESS] تم تشغيل Background Agent بنجاح
) else (
    echo [ERROR] فشل في تشغيل Background Agent
)
echo.
pause
goto menu

:stop_agent
echo.
echo إيقاف Background Agent...
php background_agent.php stop
echo.
pause
goto menu

:status_agent
echo.
echo حالة Background Agent:
php background_agent.php status
echo.
pause
goto menu

:run_agent
echo.
echo تشغيل Background Agent للاختبار...
echo للإيقاف اضغط Ctrl+C
php background_agent.php run
echo.
pause
goto menu

:show_logs
echo.
echo آخر 20 سطر من السجل:
echo ===========================================
if exist "logs\background_agent.log" (
    powershell -command "Get-Content -Path 'logs\background_agent.log' -Tail 20"
) else (
    echo لا يوجد ملف سجل بعد
)
echo ===========================================
echo.
pause
goto menu

:cleanup_logs
echo.
echo تنظيف السجلات القديمة...
if exist "logs" (
    forfiles /p "logs" /m "*.log" /d -7 /c "cmd /c del @path" 2>nul
    forfiles /p "logs" /m "daily_report_*" /d -30 /c "cmd /c del @path" 2>nul
    forfiles /p "logs" /m "weekly_report_*" /d -90 /c "cmd /c del @path" 2>nul
    echo [SUCCESS] تم تنظيف السجلات القديمة
) else (
    echo [INFO] مجلد السجلات غير موجود
)
echo.
pause
goto menu

:exit_script
echo.
echo شكراً لاستخدام Background Agent
echo ===========================================
timeout /t 2 >nul
exit /b 0
