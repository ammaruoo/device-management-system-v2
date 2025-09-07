<?php
/**
 * نظام الإشعارات المتكامل - يعمل حتى لو كان المتصفح مغلق
 * Firebase Cloud Messaging (FCM) Integration - V1 API
 */

// إعدادات Firebase Cloud Messaging V1
define('FIREBASE_PROJECT_ID', 'laptop-repair-system');
define('FIREBASE_SERVICE_ACCOUNT_PATH', __DIR__ . '/firebase-service-account.json');

// تحقق من وجود Firebase Admin SDK
if (!class_exists('Kreait\Firebase\Factory')) {
    // إذا لم يكن Firebase Admin SDK مثبت، استخدم cURL مع V1 API
    define('USE_CURL_FCM', true);
    define('FCM_V1_URL', 'https://fcm.googleapis.com/v1/projects/' . FIREBASE_PROJECT_ID . '/messages:send');
} else {
    define('USE_CURL_FCM', false);
}

/**
 * إرسال إشعار FCM (Firebase Cloud Messaging) - V1 API
 * يعمل حتى لو كان المتصفح مغلق
 */
function send_fcm_notification($pdo, $user_id, $title, $message, $link = null, $data = [], $priority = 'normal') {
    try {
        // جلب جميع tokens النشطة للمستخدم
        $stmt = $pdo->prepare("SELECT token FROM fcm_tokens WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tokens)) {
            error_log("No active FCM tokens found for user: $user_id");
            return false;
        }
        
        // إعداد البيانات للإشعار
        $notification_data = [
            'title' => $title,
            'body' => $message,
            'icon' => '/assets/icons/notification-icon.png',
            'badge' => '/assets/icons/badge-icon.png',
            'click_action' => $link ?: '/',
            'data' => array_merge($data, [
                'url' => $link,
                'timestamp' => time(),
                'priority' => $priority,
                'notification_id' => uniqid()
            ])
        ];
        
        // إرسال الإشعار لكل token
        $success_count = 0;
        $failed_tokens = [];
        
        foreach ($tokens as $token) {
            if (send_single_fcm_notification_v1($token, $notification_data)) {
                $success_count++;
            } else {
                $failed_tokens[] = $token;
            }
        }
        
        // تعطيل الـ tokens الفاشلة
        if (!empty($failed_tokens)) {
            $placeholders = implode(',', array_fill(0, count($failed_tokens), '?'));
            $stmt = $pdo->prepare("UPDATE fcm_tokens SET is_active = 0 WHERE token IN ($placeholders)");
            $stmt->execute($failed_tokens);
        }
        
        // تسجيل الإشعار في قاعدة البيانات
        save_notification_to_db($pdo, $user_id, $title, $message, $link, $priority);
        
        return $success_count > 0;
        
    } catch (Exception $e) {
        error_log("FCM Notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إرسال إشعار FCM V1 API لـ token واحد
 */
function send_single_fcm_notification_v1($token, $notification_data) {
    if (USE_CURL_FCM) {
        return send_fcm_v1_with_curl($token, $notification_data);
    } else {
        return send_fcm_v1_with_sdk($token, $notification_data);
    }
}

/**
 * إرسال FCM V1 API باستخدام cURL
 */
function send_fcm_v1_with_curl($token, $notification_data) {
    try {
        // الحصول على Access Token
        $access_token = get_firebase_access_token();
        if (!$access_token) {
            error_log("Failed to get Firebase access token");
            return false;
        }
        
        // إعداد البيانات للإرسال
        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $notification_data['title'],
                    'body' => $notification_data['body']
                ],
                'data' => $notification_data['data'],
                'webpush' => [
                    'notification' => [
                        'icon' => $notification_data['icon'],
                        'badge' => $notification_data['badge'],
                        'click_action' => $notification_data['click_action']
                    ]
                ]
            ]
        ];
        
        $headers = [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, FCM_V1_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            error_log("FCM V1 cURL Error: " . $curl_error);
            return false;
        }
        
        if ($http_code == 200) {
            $response = json_decode($result, true);
            if (isset($response['name'])) {
                return true;
            } else {
                error_log("FCM V1 API Error: " . json_encode($response));
                return false;
            }
        }
        
        error_log("FCM V1 HTTP Error: " . $http_code . " - " . $result);
        return false;
        
    } catch (Exception $e) {
        error_log("FCM V1 Error: " . $e->getMessage());
        return false;
    }
}

/**
 * الحصول على Firebase Access Token
 */
function get_firebase_access_token() {
    try {
        $service_account = json_decode(file_get_contents(FIREBASE_SERVICE_ACCOUNT_PATH), true);
        
        $jwt_header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];
        
        $jwt_payload = [
            'iss' => $service_account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => time(),
            'exp' => time() + 3600
        ];
        
        $jwt_header_encoded = base64url_encode(json_encode($jwt_header));
        $jwt_payload_encoded = base64url_encode(json_encode($jwt_payload));
        
        $signature = '';
        $private_key = $service_account['private_key'];
        openssl_sign($jwt_header_encoded . '.' . $jwt_payload_encoded, $signature, $private_key, 'SHA256');
        $jwt_signature_encoded = base64url_encode($signature);
        
        $jwt = $jwt_header_encoded . '.' . $jwt_payload_encoded . '.' . $jwt_signature_encoded;
        
        // طلب Access Token
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            $response = json_decode($result, true);
            return $response['access_token'] ?? null;
        }
        
        error_log("Failed to get access token: " . $result);
        return null;
        
    } catch (Exception $e) {
        error_log("Access Token Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Base64 URL encode
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * إرسال FCM V1 API باستخدام Firebase Admin SDK
 */
function send_fcm_v1_with_sdk($token, $notification_data) {
    try {
        $factory = (new \Kreait\Firebase\Factory)
            ->withServiceAccount(FIREBASE_SERVICE_ACCOUNT_PATH);
        
        $messaging = $factory->createMessaging();
        
        $message = \Kreait\Firebase\Messaging\CloudMessage::withTarget('token', $token)
            ->withNotification(\Kreait\Firebase\Messaging\Notification::create(
                $notification_data['title'],
                $notification_data['body']
            ))
            ->withData($notification_data['data']);
        
        $result = $messaging->send($message);
        return !empty($result);
        
    } catch (Exception $e) {
        error_log("FCM SDK Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إرسال إشعار FCM لـ token واحد (Legacy - للتوافق)
 */
function send_single_fcm_notification($token, $notification_data) {
    $fields = [
        'to' => $token,
        'notification' => [
            'title' => $notification_data['title'],
            'body' => $notification_data['body'],
            'icon' => $notification_data['icon'],
            'badge' => $notification_data['badge'],
            'click_action' => $notification_data['click_action']
        ],
        'data' => $notification_data['data'],
        'priority' => $notification_data['data']['priority'] === 'urgent' ? 'high' : 'normal'
    ];
    
    $headers = [
        'Authorization: key=' . FCM_SERVER_KEY,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, FCM_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        error_log("FCM cURL Error: " . $curl_error);
        return false;
    }
    
    if ($http_code == 200) {
        $response = json_decode($result, true);
        if (isset($response['success']) && $response['success'] == 1) {
            return true;
        } else {
            error_log("FCM API Error: " . json_encode($response));
            return false;
        }
    }
    
    error_log("FCM HTTP Error: " . $http_code . " - " . $result);
    return false;
}

/**
 * إرسال إشعار لجميع المستخدمين (مثل الواتساب)
 */
function send_broadcast_notification($pdo, $title, $message, $link = null, $exclude_users = [], $priority = 'normal') {
    try {
        // جلب جميع المستخدمين النشطين
        $where_clause = "";
        $params = [];
        
        if (!empty($exclude_users)) {
            $placeholders = implode(',', array_fill(0, count($exclude_users), '?'));
            $where_clause = "WHERE u.user_id NOT IN ($placeholders)";
            $params = $exclude_users;
        }
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.user_id, u.username 
            FROM users u 
            $where_clause
            ORDER BY u.username
        ");
        
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $success_count = 0;
        $total_users = count($users);
        
        foreach ($users as $user) {
            if (send_fcm_notification($pdo, $user['user_id'], $title, $message, $link, [], $priority)) {
                $success_count++;
            }
        }
        
        return [
            'success' => true,
            'sent_to' => $success_count,
            'total_users' => $total_users,
            'failed' => $total_users - $success_count
        ];
        
    } catch (Exception $e) {
        error_log("Broadcast Notification Error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * إرسال إشعار لمجموعة محددة من المستخدمين
 */
function send_group_notification($pdo, $user_ids, $title, $message, $link = null, $priority = 'normal') {
    $success_count = 0;
    $total_users = count($user_ids);
    
    foreach ($user_ids as $user_id) {
        if (send_fcm_notification($pdo, $user_id, $title, $message, $link, [], $priority)) {
            $success_count++;
        }
    }
    
    return [
        'success' => true,
        'sent_to' => $success_count,
        'total_users' => $total_users,
        'failed' => $total_users - $success_count
    ];
}

/**
 * إرسال إشعار حسب الفرع
 */
function send_branch_notification($pdo, $branch_name, $title, $message, $link = null, $priority = 'normal') {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.user_id 
            FROM users u 
            WHERE u.branch_name = ?
        ");
        $stmt->execute([$branch_name]);
        $user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return send_group_notification($pdo, $user_ids, $title, $message, $link, $priority);
        
    } catch (Exception $e) {
        error_log("Branch Notification Error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * إرسال إشعار حسب الصلاحيات
 */
function send_permission_notification($pdo, $permissions, $title, $message, $link = null, $priority = 'normal') {
    try {
        $placeholders = implode(',', array_fill(0, count($permissions), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.user_id 
            FROM users u 
            WHERE u.permissions IN ($placeholders)
        ");
        $stmt->execute($permissions);
        $user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return send_group_notification($pdo, $user_ids, $title, $message, $link, $priority);
        
    } catch (Exception $e) {
        error_log("Permission Notification Error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * حفظ الإشعار في قاعدة البيانات
 */
function save_notification_to_db($pdo, $user_id, $title, $message, $link = null, $priority = 'normal') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, message, link, notification_type, priority, sent_at) 
            VALUES (?, ?, ?, 'fcm', ?, NOW())
        ");
        $stmt->execute([$user_id, $message, $link, $priority]);
        return true;
    } catch (Exception $e) {
        error_log("Save Notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إرسال إشعارات تلقائية للأحداث
 */
function send_automatic_notifications($pdo, $event_type, $data) {
    switch ($event_type) {
        case 'task_assigned':
            send_fcm_notification(
                $pdo, 
                $data['assigned_user_id'], 
                'مهمة جديدة', 
                "تم تعيين الجهاز {$data['laptop_id']} لك", 
                "laptop_chat.php?laptop_id={$data['laptop_id']}",
                ['laptop_id' => $data['laptop_id'], 'type' => 'task_assigned'],
                'high'
            );
            break;
            
        case 'task_overdue':
            send_fcm_notification(
                $pdo, 
                $data['user_id'], 
                'مهمة متأخرة', 
                "المهمة {$data['laptop_id']} متأخرة عن الموعد المحدد", 
                "laptop_chat.php?laptop_id={$data['laptop_id']}",
                ['laptop_id' => $data['laptop_id'], 'type' => 'task_overdue'],
                'urgent'
            );
            break;
            
        case 'invoice_created':
            send_permission_notification(
                $pdo, 
                ['admin', 'manager'], 
                'فاتورة جديدة', 
                "تم إنشاء فاتورة جديدة للمهمة {$data['laptop_id']}", 
                "operations.php?laptop_id={$data['laptop_id']}",
                'normal'
            );
            break;
            
        case 'transfer_created':
            send_fcm_notification(
                $pdo, 
                $data['receive_user_id'], 
                'تحويل جديد', 
                "لديك تحويل جديد برقم {$data['transfer_id']}", 
                "transfers.php?transfer_id={$data['transfer_id']}",
                ['transfer_id' => $data['transfer_id'], 'type' => 'transfer_created'],
                'high'
            );
            break;
    }
}

/**
 * تنظيف الـ tokens المنتهية الصلاحية
 */
function cleanup_expired_tokens($pdo) {
    try {
        // تعطيل الـ tokens التي لم تستخدم لأكثر من 30 يوم
        $stmt = $pdo->prepare("
            UPDATE fcm_tokens 
            SET is_active = 0 
            WHERE last_used < DATE_SUB(NOW(), INTERVAL 30 DAY) 
            AND is_active = 1
        ");
        $stmt->execute();
        
        // حذف الـ tokens المعطلة لأكثر من 60 يوم
        $stmt = $pdo->prepare("
            DELETE FROM fcm_tokens 
            WHERE is_active = 0 
            AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
        ");
        $stmt->execute();
        
        return true;
    } catch (Exception $e) {
        error_log("Token Cleanup Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إحصائيات الإشعارات
 */
function get_notification_stats($pdo, $user_id = null) {
    try {
        $where_clause = $user_id ? "WHERE user_id = ?" : "";
        $params = $user_id ? [$user_id] : [];
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
            FROM notifications 
            $where_clause
        ");
        $stmt->execute($params);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Notification Stats Error: " . $e->getMessage());
        return ['total' => 0, 'unread' => 0, 'today' => 0];
    }
}
?>
