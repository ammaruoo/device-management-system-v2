<?php
session_start();
require 'db.php';

// =================================================================================
// SECURITY & PERMISSIONS - Must be logged in to change password
// =================================================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// =================================================================================
// HANDLE FORM SUBMISSION
// =================================================================================
$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // 1. Fetch current user's data
    $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    // 2. Validate input
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $feedback = ['success' => false, 'message' => 'جميع الحقول مطلوبة.'];
    } elseif ($user['password'] !== $current_password) {
        // NOTE: This is plain text comparison. For production, use password_verify()
        $feedback = ['success' => false, 'message' => 'كلمة المرور الحالية غير صحيحة.'];
    } elseif ($new_password !== $confirm_password) {
        $feedback = ['success' => false, 'message' => 'كلمة المرور الجديدة وتأكيدها غير متطابقين.'];
    } elseif (strlen($new_password) < 6) {
        $feedback = ['success' => false, 'message' => 'كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل.'];
    } else {
        // 3. Update password in the database
        // NOTE: For production, you should hash the new password:
        // $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        if ($update_stmt->execute([$new_password, $user_id])) {
            $feedback = ['success' => true, 'message' => 'تم تغيير كلمة المرور بنجاح!'];
        } else {
            $feedback = ['success' => false, 'message' => 'حدث خطأ في قاعدة البيانات. لم يتم تغيير كلمة المرور.'];
        }
    }

    // If request is AJAX, return JSON and exit so modal JS can handle it
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        $out = ['success' => (bool)$feedback['success']];
        if ($feedback['success']) {
            $out['message'] = $feedback['message'];
        } else {
            $out['error'] = $feedback['message'];
        }
        echo json_encode($out);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تغيير كلمة المرور</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; background-color: #f3f4f6; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body>

<div class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg" x-data="passwordForm()">
        
        <div class="text-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">تغيير كلمة المرور</h1>
            <p class="text-gray-500">مرحباً <span class="font-semibold"><?= htmlspecialchars($username) ?></span>، قم بتحديث كلمة المرور الخاصة بك.</p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-8">
            <form method="POST" @submit.prevent="validateAndSubmit">
                <div class="space-y-6">
                    <!-- Current Password -->
                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700">كلمة المرور الحالية</label>
                        <input id="current_password" name="current_password" type="password" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- New Password -->
                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700">كلمة المرور الجديدة</label>
                        <input id="new_password" name="new_password" type="password" required x-model="newPassword" @input="checkPasswordStrength()" class="mt-1 w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <!-- Password Strength Meter -->
                        <div class="mt-2 flex items-center gap-2">
                            <div class="w-1/3 h-2 rounded-full" :class="strength.level >= 1 ? strength.color : 'bg-gray-200'"></div>
                            <div class="w-1/3 h-2 rounded-full" :class="strength.level >= 2 ? strength.color : 'bg-gray-200'"></div>
                            <div class="w-1/3 h-2 rounded-full" :class="strength.level === 3 ? strength.color : 'bg-gray-200'"></div>
                            <span class="text-xs font-semibold" :class="strength.textColor" x-text="strength.text"></span>
                        </div>
                    </div>

                    <!-- Confirm New Password -->
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700">تأكيد كلمة المرور الجديدة</label>
                        <input id="confirm_password" name="confirm_password" type="password" required x-model="confirmPassword" class="mt-1 w-full p-3 border rounded-lg focus:outline-none focus:ring-2"
                               :class="passwordsMatch ? 'border-gray-300 focus:ring-blue-500' : 'border-red-500 focus:ring-red-500'">
                        <p x-show="!passwordsMatch && confirmPassword" class="mt-1 text-xs text-red-600">كلمتا المرور غير متطابقتين.</p>
                    </div>
                </div>

                <div class="mt-8">
                    <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-lg font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:bg-gray-400" :disabled="!passwordsMatch || !newPassword">
                        تحديث كلمة المرور
                    </button>
                </div>
            </form>
        </div>
        <div class="text-center mt-4">
            <a href="index.php" class="text-sm text-gray-600 hover:text-blue-600">العودة إلى لوحة التحكم</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('passwordForm', () => ({
        newPassword: '',
        confirmPassword: '',
        strength: { level: 0, text: '', color: '', textColor: '' },

        get passwordsMatch() {
            return this.newPassword === this.confirmPassword;
        },

        checkPasswordStrength() {
            const pass = this.newPassword;
            let score = 0;
            if (pass.length >= 8) score++;
            if (pass.match(/[a-z]/) && pass.match(/[A-Z]/)) score++;
            if (pass.match(/[0-9]/)) score++;
            if (pass.match(/[^a-zA-Z0-9]/)) score++; // Special characters

            if (pass.length < 6) {
                this.strength = { level: 0, text: 'قصيرة جداً', color: 'bg-red-500', textColor: 'text-red-600' };
            } else if (score < 3) {
                this.strength = { level: 1, text: 'ضعيفة', color: 'bg-red-500', textColor: 'text-red-600' };
            } else if (score === 3) {
                this.strength = { level: 2, text: 'متوسطة', color: 'bg-yellow-500', textColor: 'text-yellow-600' };
            } else {
                this.strength = { level: 3, text: 'قوية', color: 'bg-green-500', textColor: 'text-green-600' };
            }
        },

        validateAndSubmit(event) {
            if (!this.passwordsMatch) {
                Swal.fire('خطأ!', 'كلمتا المرور الجديدتان غير متطابقتين.', 'error');
                return;
            }
            event.target.submit();
        },

        init() {
            // Handle PHP feedback after form submission
            <?php if ($feedback): ?>
                Swal.fire({
                    icon: '<?= $feedback['success'] ? 'success' : 'error' ?>',
                    title: '<?= $feedback['success'] ? 'تم بنجاح!' : 'حدث خطأ!' ?>',
                    text: '<?= addslashes($feedback['message']) ?>',
                });
            <?php endif; ?>
        }
    }));
});
</script>

</body>
</html>
