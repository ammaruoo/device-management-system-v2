<?php
/**
 * نظام الإشعارات المبسط - يعمل مع XAMPP
 * بدون Firebase Admin SDK
 */

// إعدادات Firebase Cloud Messaging V1
define('FIREBASE_PROJECT_ID', 'laptop-repair-system');
define('FIREBASE_SERVICE_ACCOUNT_PATH', __DIR__ . '/firebase-service-account.json');

/**
 * إرسال إشعار FCM V1 API باستخدام cURL
 */
function send_fcm_notification_simple($pdo, $user_id, $title, $message, $link = null, $data = [], $priority = 'normal') {
    try {
        // جلب جميع tokens النشطة للمستخدم
        $stmt = $pdo->prepare("SELECT token FROM fcm_tokens WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tokens)) {
            error_log("No active FCM tokens found for user: $user_id");
            return false;
        }
        
        // إرسال الإشعار لكل token
        $success_count = 0;
        $failed_tokens = [];
        
        foreach ($tokens as $token) {
            if (send_single_fcm_v1($token, $title, $message, $link, $data, $priority)) {
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
        save_notification_to_db_simple($pdo, $user_id, $title, $message, $link, $priority);
        
        return $success_count > 0;
        
    } catch (Exception $e) {
        error_log("FCM Notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إرسال FCM V1 API باستخدام cURL
 */
function send_single_fcm_v1($token, $title, $message, $link = null, $data = [], $priority = 'normal') {
    try {
        // الحصول على Access Token
        $access_token = get_firebase_access_token_simple();
        if (!$access_token) {
            error_log("Failed to get Firebase access token");
            return false;
        }
        
        // إعداد البيانات للإرسال
        $message_data = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $message
                ],
                'data' => array_merge($data, [
                    'url' => $link,
                    'timestamp' => time(),
                    'priority' => $priority,
                    'notification_id' => uniqid()
                ]),
                'webpush' => [
                    'notification' => [
                        'icon' => '/assets/icons/notification-icon.png',
                        'badge' => '/assets/icons/badge-icon.png',
                        'click_action' => $link ?: '/'
                    ]
                ]
            ]
        ];
        
        $headers = [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/v1/projects/' . FIREBASE_PROJECT_ID . '/messages:send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_data));
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
function get_firebase_access_token_simple() {
    try {
        if (!file_exists(FIREBASE_SERVICE_ACCOUNT_PATH)) {
            error_log("Firebase service account file not found");
            return null;
        }
        
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
 * حفظ الإشعار في قاعدة البيانات
 */
function save_notification_to_db_simple($pdo, $user_id, $title, $message, $link = null, $priority = 'normal') {
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
 * إرسال إشعار لجميع المستخدمين
 */
function send_broadcast_notification_simple($pdo, $title, $message, $link = null, $exclude_users = [], $priority = 'normal') {
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
            if (send_fcm_notification_simple($pdo, $user['user_id'], $title, $message, $link, [], $priority)) {
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
?>
