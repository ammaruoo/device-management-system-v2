<?php
session_start();
require 'db.php';

// If user is already logged in, redirect to the dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Fetch all branches from the users table
$branches_query = $pdo->query("SELECT DISTINCT branch_name FROM users WHERE branch_name IS NOT NULL AND branch_name != '' ORDER BY branch_name ASC");
$branches = $branches_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch all users with their branches to be filtered by Alpine.js
$users_query = $pdo->query("SELECT username, branch_name FROM users ORDER BY username ASC");
$all_users = $users_query->fetchAll(PDO::FETCH_ASSOC);

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "الرجاء اختيار اسم المستخدم وإدخال كلمة المرور.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && $password === $user['password']) {
            // Login successful, set session variables
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['permissions'] = $user['permissions'];
            
            header("Location: index.php");
            exit;
        } else {
            $error = "بيانات الدخول غير صحيحة.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - نظام الصيانة</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { 
            font-family: 'Cairo', sans-serif;
            background-color: #f3f4f6;
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body>

<div class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-4xl bg-white rounded-2xl shadow-2xl flex overflow-hidden" x-data="loginForm()">
        
        <!-- Form Section -->
        <div class="w-full lg:w-1/2 p-8 sm:p-12">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">مرحباً بعودتك!</h1>
            <p class="text-gray-600 mb-8">اختر حسابك وسجل الدخول للمتابعة</p>

            <?php if (!empty($error)): ?>
                <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                    <p class="font-bold">خطأ في الدخول</p>
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <!-- Branch Selection (اختياري) -->
                <div>
                    <label for="branch" class="block text-sm font-medium text-gray-700">اختر الفرع</label>
                    <div class="mt-1 relative">
                        <select id="branch" name="branch" x-model="selectedBranch" class="w-full p-3 pr-10 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none">
                            <option value="">-- اختر الفرع () --</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= htmlspecialchars($branch['branch_name']) ?>"><?= htmlspecialchars($branch['branch_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Username Selection -->
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700">اختر اسم المستخدم</label>
                    <div class="mt-1 relative">
                        <select id="username" name="username" required x-ref="usernameSelect" class="w-full p-3 pr-10 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none">
                            <option value="" disabled>-- اختر اسمك --</option>
                            <template x-for="user in filteredUsers" :key="user.username">
                                <option :value="user.username" x-text="user.username"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <!-- Password -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">كلمة المرور</label>
                    <div class="mt-1 relative">
                        <input id="password" name="password" type="password" required class="w-full p-3 pr-10 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="ادخل كلمة المرور">
                    </div>
                </div>

                <div>
                    <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-lg font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        تسجيل الدخول
                    </button>
                </div>
            </form>
        </div>

        <!-- Visual Section -->
        <div class="hidden lg:flex w-1/2 bg-blue-600 items-center justify-center p-12 text-white relative overflow-hidden">
             <div class="absolute bg-blue-700 rounded-full w-96 h-96 -top-10 -right-16 opacity-50"></div>
             <div class="absolute bg-blue-700 rounded-full w-80 h-80 -bottom-24 -left-20 opacity-50"></div>
            <div class="text-center z-10">
                <svg class="w-32 h-32 mx-auto mb-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M12 6V3m0 18v-3M5.636 5.636l-1.414-1.414M19.778 19.778l-1.414-1.414M18.364 5.636l-1.414 1.414M4.222 19.778l1.414-1.414M12 12a6 6 0 100-12 6 6 0 000 12z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 12a3 3 0 100-6 3 3 0 000 6z"></path></svg>
                <h2 class="text-4xl font-bold mb-2">نظام إدارة الصيانة</h2>
                <p class="opacity-80">منصة متكاملة لتتبع وإدارة أجهزة الشركة بكفاءة عالية.</p>
            </div>
        </div>

    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('loginForm', () => ({
            allUsers: <?= json_encode($all_users) ?>,
            selectedBranch: '',
            filteredUsers: [],

            init() {
                // initially show all users
                this.filteredUsers = this.allUsers.slice();
                this.$watch('selectedBranch', (newBranch) => {
                    if (!newBranch) {
                        this.filteredUsers = this.allUsers.slice();
                    } else {
                        this.filteredUsers = this.allUsers.filter(user => user.branch_name === newBranch);
                    }
                    // Reset username selection when branch changes
                    if (this.$refs.usernameSelect) this.$refs.usernameSelect.value = "";
                });
            }
        }));
    });
</script>

</body>
</html>
