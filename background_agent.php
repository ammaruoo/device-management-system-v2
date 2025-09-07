<?php
/**
 * Background Agent - وكيل الخلفية لنظام إدارة الأجهزة
 * يعمل على معالجة المهام في الخلفية بشكل دوري
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require 'db.php';
require 'notifications_helper.php';

// ========================================================================
// إعدادات الـ Background Agent
// ========================================================================
define('AGENT_LOG_FILE', __DIR__ . '/logs/background_agent.log');
define('AGENT_PID_FILE', __DIR__ . '/logs/background_agent.pid');
define('AGENT_SLEEP_TIME', 60); // ثانية واحدة بين كل دورة
define('AGENT_MAX_RUNTIME', 3600); // ساعة واحدة كحد أقصى

// إنشاء مجلد السجلات إذا لم يكن موجوداً
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

/**
 * تسجيل الرسائل في ملف السجل
 */
function log_message($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;

    file_put_contents(AGENT_LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);

    // طباعة الرسائل المهمة على الشاشة
    if ($level === 'ERROR' || $level === 'WARNING') {
        echo $log_entry;
    }
}

/**
 * حفظ معرف العملية
 */
function save_pid() {
    file_put_contents(AGENT_PID_FILE, getmypid());
}

/**
 * حذف ملف معرف العملية
 */
function remove_pid() {
    if (file_exists(AGENT_PID_FILE)) {
        unlink(AGENT_PID_FILE);
    }
}

/**
 * التحقق من وجود عملية أخرى تعمل
 */
function is_agent_running() {
    if (!file_exists(AGENT_PID_FILE)) {
        return false;
    }

    $pid = file_get_contents(AGENT_PID_FILE);
    if (empty($pid)) {
        return false;
    }

    // التحقق من وجود العملية في النظام
    $command = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? "tasklist /FI \"PID eq $pid\"" : "ps -p $pid";
    exec($command, $output);

    foreach ($output as $line) {
        if (strpos($line, $pid) !== false && strpos($line, 'php') !== false) {
            return true;
        }
    }

    // إذا لم نجد العملية، نقوم بحذف الملف القديم
    unlink(AGENT_PID_FILE);
    return false;
}

/**
 * إيقاف الـ Agent
 */
function stop_agent() {
    if (file_exists(AGENT_PID_FILE)) {
        $pid = file_get_contents(AGENT_PID_FILE);
        if (!empty($pid)) {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                exec("taskkill /PID $pid /F");
            } else {
                exec("kill -9 $pid");
            }
            log_message("Agent stopped (PID: $pid)", 'WARNING');
        }
        remove_pid();
    } else {
        log_message("No running agent found", 'WARNING');
    }
}

/**
 * معالجة الإشعارات التلقائية
 */
function process_automatic_notifications($pdo) {
    try {
        log_message("بدء معالجة الإشعارات التلقائية");

        // 1. إشعارات المهام المتأخرة
        $overdue_tasks = $pdo->query("
            SELECT DISTINCT b.laptop_id, b.serial_number, b.assigned_user_id, u.username
            FROM broken_laptops b
            JOIN users u ON b.assigned_user_id = u.user_id
            WHERE b.status = 'in_progress'
            AND b.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND b.laptop_id NOT IN (
                SELECT laptop_id FROM notifications
                WHERE notification_type = 'overdue_task'
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            )
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($overdue_tasks as $task) {
            send_automatic_notifications($pdo, 'task_overdue', [
                'user_id' => $task['assigned_user_id'],
                'laptop_id' => $task['laptop_id']
            ]);
            log_message("تم إرسال إشعار مهمة متأخرة للجهاز: {$task['serial_number']}");
        }

        // 2. إشعارات المهام بدون نشاط لفترة طويلة
        $inactive_tasks = $pdo->query("
            SELECT DISTINCT b.laptop_id, b.serial_number, b.assigned_user_id
            FROM broken_laptops b
            WHERE b.status = 'in_progress'
            AND b.laptop_id NOT IN (
                SELECT laptop_id FROM operations
                WHERE operation_date > DATE_SUB(NOW(), INTERVAL 3 DAY)
            )
            AND b.laptop_id NOT IN (
                SELECT laptop_id FROM notifications
                WHERE notification_type = 'inactive_task'
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            )
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($inactive_tasks as $task) {
            send_automatic_notifications($pdo, 'task_overdue', [
                'user_id' => $task['assigned_user_id'],
                'laptop_id' => $task['laptop_id']
            ]);
            log_message("تم إرسال إشعار مهمة خاملة للجهاز: {$task['serial_number']}");
        }

        log_message("انتهت معالجة الإشعارات التلقائية - تم إرسال " . (count($overdue_tasks) + count($inactive_tasks)) . " إشعار");

    } catch (Exception $e) {
        log_message("خطأ في معالجة الإشعارات التلقائية: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * مراقبة العمليات والأوامر
 */
function monitor_operations($pdo) {
    try {
        log_message("بدء مراقبة العمليات");

        // فحص أوامر الشغل المعلقة
        $pending_work_orders = $pdo->query("
            SELECT o.operation_id, o.work_order_ref, b.serial_number, u.username
            FROM operations o
            JOIN broken_laptops b ON o.laptop_id = b.laptop_id
            JOIN users u ON o.user_id = u.user_id
            WHERE o.repair_result = 'امر شغل'
            AND o.operation_id NOT IN (SELECT operation_id FROM invoices)
            AND o.operation_date < DATE_SUB(NOW(), INTERVAL 2 DAY)
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (count($pending_work_orders) > 0) {
            log_message("تم العثور على " . count($pending_work_orders) . " أمر شغل معلق يحتاج إلى فواتير");

            // إرسال إشعار للمدراء
            $managers = $pdo->query("
                SELECT user_id FROM users WHERE permissions IN ('admin', 'manager')
            ")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($managers as $manager_id) {
                send_fcm_notification($pdo, $manager_id,
                    'أوامر شغل معلقة',
                    "يوجد " . count($pending_work_orders) . " أمر شغل بدون فواتير",
                    'admin_dashboard.php',
                    ['type' => 'pending_work_orders'],
                    'high'
                );
            }
        }

        log_message("انتهت مراقبة العمليات");

    } catch (Exception $e) {
        log_message("خطأ في مراقبة العمليات: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * تنظيف البيانات القديمة
 */
function cleanup_old_data($pdo) {
    try {
        log_message("بدء تنظيف البيانات القديمة");

        // تنظيف الإشعارات القديمة (أكبر من 30 يوم)
        $old_notifications = $pdo->exec("
            DELETE FROM notifications
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND is_read = 1
        ");
        log_message("تم حذف $old_notifications إشعار قديم");

        // تنظيف tokens FCM المنتهية الصلاحية
        cleanup_expired_tokens($pdo);

        // تنظيف الجلسات القديمة
        $old_sessions = $pdo->exec("
            DELETE FROM sessions
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        log_message("تم حذف $old_sessions جلسة قديمة");

        log_message("انتهى تنظيف البيانات القديمة");

    } catch (Exception $e) {
        log_message("خطأ في تنظيف البيانات: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * إرسال تقارير دورية
 */
function send_periodic_reports($pdo) {
    try {
        $current_hour = date('H');
        $current_day = date('N'); // 1 = Monday, 7 = Sunday

        // تقرير يومي في الساعة 9 صباحاً
        if ($current_hour == '09' && !file_exists(__DIR__ . '/logs/daily_report_' . date('Y-m-d'))) {

            log_message("إرسال التقرير اليومي");

            // إحصائيات اليوم
            $today_stats = $pdo->query("
                SELECT
                    COUNT(DISTINCT CASE WHEN created_at >= CURDATE() THEN laptop_id END) as new_laptops_today,
                    COUNT(DISTINCT CASE WHEN operation_date >= CURDATE() THEN operation_id END) as operations_today,
                    COUNT(DISTINCT CASE WHEN invoice_date >= CURDATE() THEN invoice_id END) as invoices_today
                FROM (
                    SELECT laptop_id, created_at, NULL as operation_date, NULL as invoice_date, NULL as operation_id, NULL as invoice_id
                    FROM broken_laptops WHERE DATE(created_at) = CURDATE()
                    UNION ALL
                    SELECT NULL, NULL, operation_date, NULL, operation_id, NULL
                    FROM operations WHERE DATE(operation_date) = CURDATE()
                    UNION ALL
                    SELECT NULL, NULL, NULL, invoice_date, NULL, invoice_id
                    FROM invoices WHERE DATE(invoice_date) = CURDATE()
                ) stats
            ")->fetch(PDO::FETCH_ASSOC);

            $report_message = "📊 التقرير اليومي:\n";
            $report_message .= "• أجهزة جديدة: {$today_stats['new_laptops_today']}\n";
            $report_message .= "• عمليات اليوم: {$today_stats['operations_today']}\n";
            $report_message .= "• فواتير اليوم: {$today_stats['invoices_today']}\n";

            // إرسال للمدراء
            $managers = $pdo->query("
                SELECT user_id FROM users WHERE permissions IN ('admin', 'manager')
            ")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($managers as $manager_id) {
                send_fcm_notification($pdo, $manager_id,
                    'التقرير اليومي',
                    $report_message,
                    'admin_dashboard.php',
                    ['type' => 'daily_report'],
                    'normal'
                );
            }

            // إنشاء ملف لتجنب إرسال التقرير مرة أخرى
            file_put_contents(__DIR__ . '/logs/daily_report_' . date('Y-m-d'), 'sent');
        }

        // تقرير أسبوعي كل يوم جمعة في الساعة 10 صباحاً
        if ($current_day == 5 && $current_hour == '10' && !file_exists(__DIR__ . '/logs/weekly_report_' . date('Y-W'))) {

            log_message("إرسال التقرير الأسبوعي");

            // إحصائيات الأسبوع
            $weekly_stats = $pdo->query("
                SELECT
                    COUNT(DISTINCT laptop_id) as total_laptops,
                    COUNT(DISTINCT CASE WHEN status = 'completed' THEN laptop_id END) as completed_laptops,
                    COUNT(DISTINCT CASE WHEN status = 'in_progress' THEN laptop_id END) as in_progress_laptops,
                    COUNT(*) as total_operations,
                    SUM(CASE WHEN i.total_amount IS NOT NULL THEN i.total_amount ELSE 0 END) as total_revenue
                FROM broken_laptops b
                LEFT JOIN operations o ON b.laptop_id = o.laptop_id
                LEFT JOIN invoices i ON o.operation_id = i.operation_id
                WHERE b.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ")->fetch(PDO::FETCH_ASSOC);

            $report_message = "📈 التقرير الأسبوعي:\n";
            $report_message .= "• إجمالي الأجهزة: {$weekly_stats['total_laptops']}\n";
            $report_message .= "• مكتملة: {$weekly_stats['completed_laptops']}\n";
            $report_message .= "• قيد التنفيذ: {$weekly_stats['in_progress_laptops']}\n";
            $report_message .= "• إجمالي العمليات: {$weekly_stats['total_operations']}\n";
            $report_message .= "• إجمالي الإيرادات: $" . number_format($weekly_stats['total_revenue'], 2) . "\n";

            // إرسال للمدراء والمحاسبين
            $recipients = $pdo->query("
                SELECT user_id FROM users WHERE permissions IN ('admin', 'manager', 'accountant')
            ")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($recipients as $user_id) {
                send_fcm_notification($pdo, $user_id,
                    'التقرير الأسبوعي',
                    $report_message,
                    'admin_dashboard.php',
                    ['type' => 'weekly_report'],
                    'normal'
                );
            }

            file_put_contents(__DIR__ . '/logs/weekly_report_' . date('Y-W'), 'sent');
        }

    } catch (Exception $e) {
        log_message("خطأ في إرسال التقارير: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * الدالة الرئيسية لتشغيل الـ Agent
 */
function run_agent() {
    global $pdo;

    log_message("بدء تشغيل Background Agent");
    save_pid();

    $start_time = time();
    $cycle_count = 0;

    while (true) {
        $cycle_count++;
        $current_time = time();

        log_message("دورة رقم $cycle_count - " . date('H:i:s'));

        try {
            // 1. معالجة الإشعارات التلقائية
            process_automatic_notifications($pdo);

            // 2. مراقبة العمليات
            monitor_operations($pdo);

            // 3. تنظيف البيانات القديمة
            cleanup_old_data($pdo);

            // 4. إرسال التقارير الدورية
            send_periodic_reports($pdo);

            log_message("انتهت الدورة $cycle_count بنجاح");

        } catch (Exception $e) {
            log_message("خطأ في الدورة $cycle_count: " . $e->getMessage(), 'ERROR');
        }

        // التحقق من الحد الأقصى لوقت التشغيل
        if (($current_time - $start_time) >= AGENT_MAX_RUNTIME) {
            log_message("تم الوصول للحد الأقصى لوقت التشغيل", 'WARNING');
            break;
        }

        // الانتظار قبل الدورة التالية
        sleep(AGENT_SLEEP_TIME);
    }

    remove_pid();
    log_message("انتهى تشغيل Background Agent");
}

/**
 * عرض حالة الـ Agent
 */
function show_status() {
    if (is_agent_running()) {
        $pid = file_get_contents(AGENT_PID_FILE);
        echo "✅ Background Agent يعمل (PID: $pid)\n";

        if (file_exists(AGENT_LOG_FILE)) {
            $log_content = file_get_contents(AGENT_LOG_FILE);
            $lines = explode("\n", trim($log_content));
            $last_lines = array_slice($lines, -5);
            echo "\nآخر 5 رسائل في السجل:\n";
            foreach ($last_lines as $line) {
                echo $line . "\n";
            }
        }
    } else {
        echo "❌ Background Agent متوقف\n";

        if (file_exists(AGENT_LOG_FILE)) {
            echo "\nآخر رسالة في السجل:\n";
            $log_content = file_get_contents(AGENT_LOG_FILE);
            $lines = explode("\n", trim($log_content));
            echo end($lines) . "\n";
        }
    }
}

/**
 * عرض المساعدة
 */
function show_help() {
    echo "Background Agent لنظام إدارة الأجهزة\n\n";
    echo "الاستخدام: php background_agent.php [command]\n\n";
    echo "الأوامر المتاحة:\n";
    echo "  start     - تشغيل الـ Agent في الخلفية\n";
    echo "  stop      - إيقاف الـ Agent\n";
    echo "  status    - عرض حالة الـ Agent\n";
    echo "  run       - تشغيل الـ Agent في الواجهة الأمامية (للاختبار)\n";
    echo "  help      - عرض هذه المساعدة\n\n";
    echo "أمثلة:\n";
    echo "  php background_agent.php start\n";
    echo "  php background_agent.php status\n";
    echo "  php background_agent.php stop\n\n";
}

// ========================================================================
// معالجة الأوامر من سطر الأوامر
// ========================================================================
$command = $argv[1] ?? 'help';

switch ($command) {
    case 'start':
        if (is_agent_running()) {
            echo "❌ الـ Agent يعمل بالفعل!\n";
            exit(1);
        }

        echo "🔄 بدء تشغيل Background Agent...\n";

        // تشغيل في الخلفية
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            $command = "start /B php " . __FILE__ . " run > nul 2>&1";
            pclose(popen($command, "r"));
        } else {
            // Linux/Unix
            $command = "php " . __FILE__ . " run > /dev/null 2>&1 &";
            exec($command);
        }

        sleep(2); // انتظار للتأكد من بدء التشغيل

        if (is_agent_running()) {
            echo "✅ تم تشغيل Background Agent بنجاح\n";
        } else {
            echo "❌ فشل في تشغيل Background Agent\n";
        }
        break;

    case 'stop':
        stop_agent();
        echo "✅ تم إيقاف Background Agent\n";
        break;

    case 'status':
        show_status();
        break;

    case 'run':
        run_agent();
        break;

    case 'help':
    default:
        show_help();
        break;
}

?>
