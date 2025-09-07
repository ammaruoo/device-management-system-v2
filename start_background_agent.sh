#!/bin/bash

# Background Agent Startup Script for Linux/Unix
# تشغيل Background Agent لنظام إدارة الأجهزة

# ألوان للنصوص
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# دالة لطباعة الرسائل
print_message() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# دالة لعرض القائمة الرئيسية
show_menu() {
    clear
    echo "==========================================="
    echo "      Background Agent Menu"
    echo "==========================================="
    echo
    echo "1. تشغيل الـ Agent في الخلفية"
    echo "2. إيقاف الـ Agent"
    echo "3. عرض حالة الـ Agent"
    echo "4. تشغيل للاختبار (واجهة أمامية)"
    echo "5. عرض السجل"
    echo "6. تنظيف السجلات القديمة"
    echo "7. إعداد Cron Job"
    echo "8. خروج"
    echo
}

# دالة للتحقق من وجود PHP
check_php() {
    if ! command -v php &> /dev/null; then
        print_error "PHP غير مثبت أو غير موجود في PATH"
        print_error "يرجى تثبيت PHP وإضافته للـ PATH"
        exit 1
    fi
}

# دالة للتحقق من وجود ملف background_agent.php
check_agent_file() {
    if [ ! -f "background_agent.php" ]; then
        print_error "ملف background_agent.php غير موجود"
        print_error "يرجى التأكد من وجود الملف في نفس المجلد"
        exit 1
    fi
}

# دالة لتشغيل الـ Agent
start_agent() {
    print_message "بدء تشغيل Background Agent..."
    php background_agent.php start

    if [ $? -eq 0 ]; then
        print_success "تم تشغيل Background Agent بنجاح"
    else
        print_error "فشل في تشغيل Background Agent"
    fi
}

# دالة لإيقاف الـ Agent
stop_agent() {
    print_message "إيقاف Background Agent..."
    php background_agent.php stop
}

# دالة لعرض حالة الـ Agent
status_agent() {
    print_message "حالة Background Agent:"
    php background_agent.php status
}

# دالة لتشغيل الـ Agent في الواجهة الأمامية
run_agent() {
    print_warning "تشغيل Background Agent للاختبار..."
    print_warning "للإيقاف اضغط Ctrl+C"
    echo
    php background_agent.php run
}

# دالة لعرض السجل
show_logs() {
    echo "==========================================="
    echo "آخر 20 سطر من السجل:"
    echo "==========================================="

    if [ -f "logs/background_agent.log" ]; then
        tail -20 logs/background_agent.log
    else
        echo "لا يوجد ملف سجل بعد"
    fi

    echo "==========================================="
}

# دالة لتنظيف السجلات القديمة
cleanup_logs() {
    print_message "تنظيف السجلات القديمة..."

    if [ -d "logs" ]; then
        # حذف ملفات السجل الأقدم من 7 أيام
        find logs -name "*.log" -mtime +7 -delete 2>/dev/null

        # حذف تقارير يومية الأقدم من 30 يوم
        find logs -name "daily_report_*" -mtime +30 -delete 2>/dev/null

        # حذف تقارير أسبوعية الأقدم من 90 يوم
        find logs -name "weekly_report_*" -mtime +90 -delete 2>/dev/null

        print_success "تم تنظيف السجلات القديمة"
    else
        print_warning "مجلد السجلات غير موجود"
    fi
}

# دالة لإعداد Cron Job
setup_cron() {
    print_message "إعداد Cron Job للتشغيل التلقائي..."

    # الحصول على المسار الكامل للمشروع
    PROJECT_PATH=$(pwd)
    SCRIPT_PATH="$PROJECT_PATH/background_agent.php"

    # إنشاء cron job
    CRON_JOB="@reboot cd $PROJECT_PATH && php $SCRIPT_PATH start > /dev/null 2>&1"
    CRON_JOB_DAILY="0 9 * * * cd $PROJECT_PATH && php $SCRIPT_PATH run > /dev/null 2>&1"

    # إضافة للـ crontab
    (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
    (crontab -l 2>/dev/null; echo "$CRON_JOB_DAILY") | crontab -

    if [ $? -eq 0 ]; then
        print_success "تم إعداد Cron Job بنجاح"
        print_message "المهام المجدولة:"
        print_message "  - تشغيل عند بدء النظام: $CRON_JOB"
        print_message "  - تشغيل يومي في الساعة 9 صباحاً: $CRON_JOB_DAILY"
        print_message ""
        print_message "لعرض المهام الحالية: crontab -l"
        print_message "لتحرير المهام: crontab -e"
    else
        print_error "فشل في إعداد Cron Job"
        print_message "يمكنك إعداد المهام يدوياً:"
        print_message "  crontab -e"
        print_message "  ثم إضافة السطور التالية:"
        echo "  $CRON_JOB"
        echo "  $CRON_JOB_DAILY"
    fi
}

# التحقق من المتطلبات
check_php
check_agent_file

# الحلقة الرئيسية
while true; do
    show_menu
    read -p "اختر رقم من القائمة: " choice

    case $choice in
        1)
            start_agent
            ;;
        2)
            stop_agent
            ;;
        3)
            status_agent
            ;;
        4)
            run_agent
            ;;
        5)
            show_logs
            ;;
        6)
            cleanup_logs
            ;;
        7)
            setup_cron
            ;;
        8)
            print_message "شكراً لاستخدام Background Agent"
            echo "==========================================="
            exit 0
            ;;
        *)
            print_error "خيار غير صحيح، يرجى المحاولة مرة أخرى"
            sleep 2
            ;;
    esac

    if [ "$choice" != "4" ]; then
        echo
        read -p "اضغط Enter للمتابعة..."
    fi
done
