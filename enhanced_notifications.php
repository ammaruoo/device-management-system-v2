<?php
/**
 * نظام الإشعارات المحسن
 * Enhanced Notifications System
 *
 * ميزات:
 * - إشعارات متعددة الأنواع
 * - جدولة زمنية للإشعارات
 * - إرسال بريد إلكتروني
 * - إشعارات فورية
 * - تاريخ الإشعارات
 */

session_start();
require 'db.php';

class EnhancedNotifications
{
    private $pdo;
    private $user_id;

    public function __construct($pdo, $user_id = null)
    {
        $this->pdo = $pdo;
        $this->user_id = $user_id ?: $_SESSION['user_id'];
    }

    /**
     * إرسال إشعار جديد
     */
    public function sendNotification($user_id, $type, $title, $message, $link = null, $priority = 'normal')
    {
        $types = [
            'device_assigned' => 'تم تعيين جهاز جديد',
            'device_completed' => 'تم إنجاز جهاز',
            'message_received' => 'رسالة جديدة',
            'deadline_approaching' => 'اقتراب موعد التسليم',
            'system_alert' => 'تنبيه نظام',
            'user_mention' => 'تم ذكر اسمك'
        ];

        $icons = [
            'device_assigned' => 'user-plus',
            'device_completed' => 'check-circle',
            'message_received' => 'message-circle',
            'deadline_approaching' => 'clock',
            'system_alert' => 'alert-triangle',
            'user_mention' => 'at-sign'
        ];

        $colors = [
            'low' => '#6B7280',
            'normal' => '#3B82F6',
            'high' => '#F59E0B',
            'urgent' => '#EF4444'
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO notifications
            (user_id, type, title, message, link, priority, icon, color, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([
            $user_id,
            $type,
            $title,
            $message,
            $link,
            $priority,
            $icons[$type] ?? 'bell',
            $colors[$priority] ?? '#3B82F6'
        ]);
    }

    /**
     * جلب الإشعارات غير المقروءة
     */
    public function getUnreadNotifications($limit = 10)
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM notifications
            WHERE user_id = ? AND is_read = 0
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$this->user_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * جلب جميع الإشعارات
     */
    public function getAllNotifications($limit = 50)
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$this->user_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * تحديث حالة الإشعارات كمقروءة
     */
    public function markAsRead($notification_ids = null)
    {
        if ($notification_ids === null) {
            // تحديث جميع الإشعارات للمستخدم الحالي
            $stmt = $this->pdo->prepare("
                UPDATE notifications
                SET is_read = 1, read_at = NOW()
                WHERE user_id = ? AND is_read = 0
            ");
            return $stmt->execute([$this->user_id]);
        } else {
            // تحديث إشعارات محددة
            $placeholders = str_repeat('?,', count($notification_ids) - 1) . '?';
            $stmt = $this->pdo->prepare("
                UPDATE notifications
                SET is_read = 1, read_at = NOW()
                WHERE notification_id IN ($placeholders) AND user_id = ?
            ");
            return $stmt->execute(array_merge($notification_ids, [$this->user_id]));
        }
    }

    /**
     * حذف إشعار
     */
    public function deleteNotification($notification_id)
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM notifications
            WHERE notification_id = ? AND user_id = ?
        ");
        return $stmt->execute([$notification_id, $this->user_id]);
    }

    /**
     * إنشاء إشعار لجهاز معين
     */
    public function notifyDeviceUpdate($laptop_id, $action, $message = null)
    {
        // جلب معلومات الجهاز
        $stmt = $this->pdo->prepare("
            SELECT serial_number, assigned_user_id, entered_by_user_id
            FROM broken_laptops
            WHERE laptop_id = ?
        ");
        $stmt->execute([$laptop_id]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$device) return false;

        $device_title = $device['serial_number'] ?: "جهاز رقم $laptop_id";

        // تحديد نوع الإشعار حسب الإجراء
        $notifications = [
            'assigned' => [
                'title' => 'تم تعيين جهاز جديد',
                'message' => "تم تعيين الجهاز $device_title لك",
                'user_id' => $device['assigned_user_id']
            ],
            'completed' => [
                'title' => 'تم إنجاز جهاز',
                'message' => "تم إنجاز صيانة الجهاز $device_title",
                'user_id' => $device['entered_by_user_id']
            ],
            'message' => [
                'title' => 'رسالة جديدة',
                'message' => $message ?: "رسالة جديدة بخصوص الجهاز $device_title",
                'user_id' => $device['assigned_user_id'] ?: $device['entered_by_user_id']
            ]
        ];

        if (isset($notifications[$action])) {
            $notif = $notifications[$action];
            return $this->sendNotification(
                $notif['user_id'],
                $action,
                $notif['title'],
                $notif['message'],
                "laptop_chat.php?laptop_id=$laptop_id"
            );
        }

        return false;
    }

    /**
     * إرسال إشعارات مجدولة
     */
    public function sendScheduledNotifications()
    {
        // إشعارات للأجهزة المؤجلة
        $stmt = $this->pdo->query("
            SELECT laptop_id, serial_number, assigned_user_id
            FROM broken_laptops
            WHERE status NOT IN ('locked', 'مغلق')
            AND DATE(created_at) < DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND assigned_user_id IS NOT NULL
        ");

        $overdue_devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($overdue_devices as $device) {
            $this->sendNotification(
                $device['assigned_user_id'],
                'deadline_approaching',
                'جهاز مؤجل',
                "الجهاز {$device['serial_number']} مؤجل منذ أكثر من أسبوع",
                "laptop_chat.php?laptop_id={$device['laptop_id']}",
                'high'
            );
        }
    }

    /**
     * إحصائيات الإشعارات
     */
    public function getNotificationStats()
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN priority = 'urgent' AND is_read = 0 THEN 1 ELSE 0 END) as urgent_unread
            FROM notifications
            WHERE user_id = ?
        ");
        $stmt->execute([$this->user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// API للتعامل مع الإشعارات
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $notifications = new EnhancedNotifications($pdo);

    switch ($_GET['action']) {
        case 'get_notifications':
            $data = $notifications->getAllNotifications();
            echo json_encode(['success' => true, 'notifications' => $data]);
            break;

        case 'get_unread_count':
            $stats = $notifications->getNotificationStats();
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        case 'mark_read':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $notification_ids = isset($_POST['notification_ids']) ? json_decode($_POST['notification_ids'], true) : null;
                $result = $notifications->markAsRead($notification_ids);
                echo json_encode(['success' => $result]);
            }
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notification_id'])) {
                $result = $notifications->deleteNotification((int)$_POST['notification_id']);
                echo json_encode(['success' => $result]);
            }
            break;

        case 'send_scheduled':
            $notifications->sendScheduledNotifications();
            echo json_encode(['success' => true, 'message' => 'تم إرسال الإشعارات المجدولة']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'إجراء غير معروف']);
    }
    exit;
}
?>