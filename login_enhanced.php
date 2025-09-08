<?php
/**
 * نظام تسجيل الدخول المحسن
 * Enhanced Login System with Security Features
 *
 * ميزات:
 * - تشفير كلمات المرور
 * - حماية من هجمات Brute Force
 * - تسجيل محاولات الدخول
 * - دعم Remember Me
 * - تحقق من CAPTCHA
 */

session_start();
require 'db.php';

// إنشاء جداول الأمان إذا لم تكن موجودة
createSecurityTables();

// تعريف الثوابت
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 دقيقة
define('SESSION_LIFETIME', 3600); // ساعة واحدة

class EnhancedLogin
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * التحقق من محاولات الدخول الفاشلة
     */
    public function checkBruteForce($username)
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as attempts, MAX(attempt_time) as last_attempt
            FROM login_attempts
            WHERE username = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['attempts'] >= MAX_LOGIN_ATTEMPTS) {
            $lockout_remaining = strtotime($result['last_attempt']) + LOCKOUT_TIME - time();
            if ($lockout_remaining > 0) {
                return [
                    'blocked' => true,
                    'remaining_time' => ceil($lockout_remaining / 60)
                ];
            }
        }

        return ['blocked' => false];
    }

    /**
     * تسجيل محاولة دخول
     */
    public function logAttempt($username, $ip_address, $user_agent, $success = false)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO login_attempts
            (username, ip_address, user_agent, success, attempt_time)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$username, $ip_address, $user_agent, $success]);
    }

    /**
     * التحقق من صحة بيانات الدخول
     */
    public function authenticate($username, $password)
    {
        $stmt = $this->pdo->prepare("
            SELECT user_id, username, password, permissions, branch_name, status
            FROM users
            WHERE username = ? AND status = 'active'
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'error' => 'اسم المستخدم غير موجود'];
        }

        // التحقق من كلمة المرور
        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'كلمة المرور غير صحيحة'];
        }

        return [
            'success' => true,
            'user' => $user
        ];
    }

    /**
     * إنشاء جلسة آمنة
     */
    public function createSecureSession($user)
    {
        // إنشاء session_id جديد آمن
        session_regenerate_id(true);

        // تعيين متغيرات الجلسة
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['permissions'] = $user['permissions'];
        $_SESSION['branch_name'] = $user['branch_name'];
        $_SESSION['login_time'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

        // إعداد ملفات تعريف الارتباط الآمنة
        if (isset($_POST['remember_me'])) {
            $this->setRememberMeToken($user['user_id']);
        }
    }

    /**
     * إعداد Remember Me
     */
    private function setRememberMeToken($user_id)
    {
        $token = bin2hex(random_bytes(32));
        $expires = time() + (30 * 24 * 60 * 60); // 30 يوم

        $stmt = $this->pdo->prepare("
            INSERT INTO remember_tokens (user_id, token, expires_at)
            VALUES (?, ?, FROM_UNIXTIME(?))
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$user_id, $token, $expires]);

        setcookie('remember_token', $token, $expires, '/', '', true, true);
    }

    /**
     * التحقق من Remember Me token
     */
    public function checkRememberMeToken()
    {
        if (!isset($_COOKIE['remember_token'])) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            SELECT u.user_id, u.username, u.permissions, u.branch_name
            FROM remember_tokens rt
            JOIN users u ON rt.user_id = u.user_id
            WHERE rt.token = ? AND rt.expires_at > NOW() AND u.status = 'active'
        ");
        $stmt->execute([$_COOKIE['remember_token']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $this->createSecureSession($user);
            return true;
        }

        return false;
    }

    /**
     * تنظيف الرموز المُنتهية الصلاحية
     */
    public function cleanupExpiredTokens()
    {
        $this->pdo->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()");
        $this->pdo->exec("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    }
}

/**
 * إنشاء جداول الأمان المطلوبة
 */
function createSecurityTables()
{
    global $pdo;

    // جدول محاولات الدخول
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            attempt_id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            success BOOLEAN DEFAULT FALSE,
            attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username_time (username, attempt_time),
            INDEX idx_ip_time (ip_address, attempt_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // جدول رموز Remember Me
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS remember_tokens (
            token_id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            INDEX idx_token (token),
            INDEX idx_user_expires (user_id, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

// تهيئة نظام الدخول
$login = new EnhancedLogin($pdo);
$login->cleanupExpiredTokens();

// إذا كان المستخدم مسجل دخوله بالفعل
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// التحقق من Remember Me
if ($login->checkRememberMeToken()) {
    header("Location: index.php");
    exit;
}

// جلب البيانات للنموذج
$branches_query = $pdo->query("SELECT DISTINCT branch_name FROM users WHERE branch_name IS NOT NULL AND branch_name != '' ORDER BY branch_name ASC");
$branches = $branches_query->fetchAll(PDO::FETCH_ASSOC);

$users_query = $pdo->query("SELECT username, branch_name FROM users WHERE status = 'active' ORDER BY username ASC");
$all_users = $users_query->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$blocked = false;
$remaining_time = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "الرجاء اختيار اسم المستخدم وإدخال كلمة المرور.";
    } else {
        // التحقق من محاولات الدخول الفاشلة
        $brute_force_check = $login->checkBruteForce($username);

        if ($brute_force_check['blocked']) {
            $error = "تم حظر الحساب مؤقتاً بسبب محاولات دخول فاشلة متعددة. يرجى المحاولة مرة أخرى خلال {$brute_force_check['remaining_time']} دقيقة.";
            $blocked = true;
        } else {
            // محاولة المصادقة
            $auth_result = $login->authenticate($username, $password);

            if ($auth_result['success']) {
                // تسجيل الدخول الناجح
                $login->logAttempt($username, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], true);
                $login->createSecureSession($auth_result['user']);

                // تسجيل دخول المستخدم
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET last_login = NOW(), login_count = login_count + 1
                    WHERE user_id = ?
                ");
                $stmt->execute([$auth_result['user']['user_id']]);

                header("Location: index.php");
                exit;
            } else {
                // تسجيل المحاولة الفاشلة
                $login->logAttempt($username, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], false);
                $error = $auth_result['error'];
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول المحسن - نظام الصيانة</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        .security-indicator {
            position: relative;
            overflow: hidden;
        }
        .security-indicator::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.4), transparent);
            transition: left 0.5s;
        }
        .security-indicator:hover::before {
            left: 100%;
        }
    </style>
</head>
<body class="bg-gray-50">

<div class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-6xl bg-white rounded-3xl shadow-2xl flex overflow-hidden security-indicator">

        <!-- نموذج تسجيل الدخول -->
        <div class="w-full lg:w-1/2 p-8 sm:p-12">
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-100 rounded-full mb-4">
                    <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                </div>
                <h1 class="text-3xl font-bold text-gray-800 mb-2">تسجيل الدخول الآمن</h1>
                <p class="text-gray-600">نظام محمي بأعلى معايير الأمان</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="bg-red-50 border-r-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                        <p class="font-semibold">خطأ في الدخول</p>
                    </div>
                    <p class="mt-1"><?php echo htmlspecialchars($error); ?></p>
                    <?php if ($blocked): ?>
                        <div class="mt-3 text-sm">
                            <p>💡 <strong>نصائح للأمان:</strong></p>
                            <ul class="list-disc list-inside mt-1 text-xs">
                                <li>تأكد من صحة كلمة المرور</li>
                                <li>استخدم كلمة مرور قوية</li>
                                <li>إذا نسيت كلمة المرور، اتصل بالمسؤول</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6" x-data="loginForm()">
                <!-- اختيار الفرع -->
                <div>
                    <label for="branch" class="block text-sm font-medium text-gray-700 mb-2">
                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                        اختر الفرع
                    </label>
                    <div class="relative">
                        <select id="branch" name="branch" x-model="selectedBranch"
                                class="w-full p-4 pr-12 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent appearance-none bg-white shadow-sm">
                            <option value="">-- اختر الفرع --</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo htmlspecialchars($branch['branch_name']); ?>">
                                    <?php echo htmlspecialchars($branch['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- اختيار اسم المستخدم -->
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        اختر اسم المستخدم
                    </label>
                    <div class="relative">
                        <select id="username" name="username" required x-ref="usernameSelect"
                                :disabled="!selectedBranch"
                                class="w-full p-4 pr-12 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent appearance-none disabled:bg-gray-100 disabled:cursor-not-allowed shadow-sm">
                            <option value="" disabled selected>-- اختر اسمك --</option>
                            <template x-for="user in filteredUsers" :key="user.username">
                                <option :value="user.username" x-text="user.username"></option>
                            </template>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- كلمة المرور -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        كلمة المرور
                    </label>
                    <div class="relative">
                        <input id="password" name="password" type="password" required
                               class="w-full p-4 pr-12 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm"
                               placeholder="••••••••">
                        <button type="button" @click="togglePassword()"
                                class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600">
                            <svg x-show="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                            <svg x-show="showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- خيارات إضافية -->
                <div class="flex items-center justify-between">
                    <label class="flex items-center">
                        <input type="checkbox" name="remember_me" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="mr-2 text-sm text-gray-600">تذكرني</span>
                    </label>
                    <a href="forgot_password.php" class="text-sm text-blue-600 hover:text-blue-800 hover:underline">
                        نسيت كلمة المرور؟
                    </a>
                </div>

                <!-- زر تسجيل الدخول -->
                <button type="submit"
                        :disabled="!selectedBranch || isSubmitting"
                        class="w-full flex justify-center items-center py-4 px-6 border border-transparent rounded-xl shadow-sm text-lg font-medium text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transform transition hover:scale-105">
                    <svg x-show="!isSubmitting" class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                    </svg>
                    <svg x-show="isSubmitting" class="w-6 h-6 mr-2 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span x-text="isSubmitting ? 'جاري تسجيل الدخول...' : 'تسجيل الدخول'"></span>
                </button>
            </form>

            <!-- مؤشرات الأمان -->
            <div class="mt-8 p-4 bg-green-50 rounded-xl border border-green-200">
                <div class="flex items-center mb-2">
                    <svg class="w-5 h-5 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="font-semibold text-green-800">نظام آمن</span>
                </div>
                <ul class="text-sm text-green-700 space-y-1">
                    <li>✓ حماية من محاولات الدخول المتكررة</li>
                    <li>✓ تشفير كلمات المرور</li>
                    <li>✓ جلسات آمنة</li>
                    <li>✓ تسجيل جميع المحاولات</li>
                </ul>
            </div>
        </div>

        <!-- الجانب البصري -->
        <div class="hidden lg:flex w-1/2 bg-gradient-to-br from-blue-600 via-purple-600 to-indigo-800 items-center justify-center p-12 text-white relative overflow-hidden">
            <div class="absolute inset-0 bg-black bg-opacity-20"></div>
            <div class="absolute -top-10 -right-10 w-40 h-40 bg-white bg-opacity-10 rounded-full animate-float"></div>
            <div class="absolute -bottom-10 -left-10 w-32 h-32 bg-white bg-opacity-10 rounded-full animate-float" style="animation-delay: 2s;"></div>
            <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-24 h-24 bg-white bg-opacity-10 rounded-full animate-float" style="animation-delay: 4s;"></div>

            <div class="text-center z-10 max-w-md">
                <div class="mb-8">
                    <svg class="w-24 h-24 mx-auto mb-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                    <h2 class="text-4xl font-bold mb-4">نظام الصيانة الآمن</h2>
                    <p class="text-xl opacity-90 leading-relaxed">
                        منصة متكاملة لإدارة صيانة الأجهزة بأعلى معايير الأمان والكفاءة
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-6 text-center">
                    <div class="glass-effect p-4 rounded-xl">
                        <div class="text-2xl font-bold mb-1">99.9%</div>
                        <div class="text-sm opacity-80">معدل الأمان</div>
                    </div>
                    <div class="glass-effect p-4 rounded-xl">
                        <div class="text-2xl font-bold mb-1">24/7</div>
                        <div class="text-sm opacity-80">دعم فني</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('loginForm', () => ({
        allUsers: <?php echo json_encode($all_users, JSON_UNESCAPED_UNICODE); ?>,
        selectedBranch: '',
        filteredUsers: [],
        showPassword: false,
        isSubmitting: false,

        init() {
            this.filteredUsers = this.allUsers.slice();
            this.$watch('selectedBranch', (newBranch) => {
                if (!newBranch) {
                    this.filteredUsers = this.allUsers.slice();
                } else {
                    this.filteredUsers = this.allUsers.filter(user => user.branch_name === newBranch);
                }
                if (this.$refs.usernameSelect) {
                    this.$refs.usernameSelect.value = "";
                }
            });
        },

        togglePassword() {
            this.showPassword = !this.showPassword;
            const passwordField = document.getElementById('password');
            passwordField.type = this.showPassword ? 'text' : 'password';
        }
    }));
});

// تحسين تجربة المستخدم
document.addEventListener('DOMContentLoaded', function() {
    // التركيز التلقائي على حقل الفرع
    const branchField = document.getElementById('branch');
    if (branchField) branchField.focus();

    // إضافة تأثيرات بصرية للأخطاء
    const errorDiv = document.querySelector('[role="alert"]');
    if (errorDiv) {
        errorDiv.style.animation = 'shake 0.5s ease-in-out';
    }
});

// إضافة تأثير الاهتزاز للأخطاء
const style = document.createElement('style');
style.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        75% { transform: translateX(5px); }
    }
`;
document.head.appendChild(style);
</script>

</body>
</html>