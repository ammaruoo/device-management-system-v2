<?php
session_start();
require 'db.php';

// =================================================================================
// SECURITY & PERMISSIONS
// =================================================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
// Only admins and managers can manage users
if (!in_array($_SESSION['permissions'], ['admin', 'manager'])) {
    header("Location: index.php?error=access_denied");
    exit;
}

// =================================================================================
// FETCH ALL USERS WITH THEIR STATS FOR INITIAL PAGE LOAD
// =================================================================================
$users_query = $pdo->query("
    SELECT 
        u.user_id,
        u.username,
        u.permissions,
        u.branch_name,
        (SELECT COUNT(*) FROM broken_laptops bl WHERE bl.entered_by_user_id = u.user_id) as entered_count,
        (SELECT COUNT(*) FROM broken_laptops bl WHERE bl.assigned_user_id = u.user_id AND bl.status NOT IN ('locked', 'مغلق')) as assigned_count,
        (SELECT COUNT(*) FROM locks l WHERE l.user_id = u.user_id) as closed_count
    FROM users u
    ORDER BY u.username
");
$all_users = $users_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing branches for the datalist
$branches_query = $pdo->query("SELECT DISTINCT branch_name FROM users WHERE branch_name IS NOT NULL AND branch_name != '' ORDER BY branch_name ASC");
$all_branches = $branches_query->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المستخدمين</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        [x-cloak] { display: none !important; }
        .swal2-input, .swal2-select {
            border: 1px solid #D1D5DB !important;
            border-radius: 0.5rem !important;
            padding: 0.75rem !important;
            width: 90% !important;
            margin: 0.5rem auto !important;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4 sm:p-6 lg:p-8" x-data="userManagement()">
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">إدارة المستخدمين</h1>
            <p class="text-gray-500">إضافة وتعديل صلاحيات وفروع فريق العمل</p>
        </div>
        <div class="flex items-center gap-4">
             <button @click="openAddUserModal()" class="px-4 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                <span>إضافة مستخدم</span>
            </button>
            <a href="index.php" class="text-sm text-blue-600 hover:underline">العودة للرئيسية</a>
        </div>
    </div>

    <!-- Users Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <template x-for="user in users" :key="user.user_id">
            <div class="bg-white rounded-xl shadow-lg p-5 flex flex-col">
                <div class="flex-1">
                    <div class="flex justify-between items-start">
                        <div>
                            <h3 class="font-bold text-lg text-gray-800" x-text="user.username"></h3>
                            <p class="text-sm font-semibold" :class="getPermissionClass(user.permissions)" x-text="getPermissionName(user.permissions)"></p>
                            <p class="text-xs text-gray-500" x-text="user.branch_name || 'بدون فرع'"></p>
                        </div>
                        <div class="w-12 h-12 rounded-full bg-gray-200 flex items-center justify-center font-bold text-xl" :style="{ backgroundColor: userColor(user.username), color: 'white' }" x-text="user.username.charAt(0).toUpperCase()"></div>
                    </div>
                    <div class="mt-4 border-t pt-4 space-y-2 text-sm text-gray-600">
                        <p class="flex justify-between"><span>تذاكر مدخلة:</span> <strong class="text-gray-800" x-text="user.entered_count"></strong></p>
                        <p class="flex justify-between"><span>تذاكر معينة:</span> <strong class="text-gray-800" x-text="user.assigned_count"></strong></p>
                        <p class="flex justify-between"><span>تذاكر مغلقة:</span> <strong class="text-gray-800" x-text="user.closed_count"></strong></p>
                    </div>
                </div>
                <div class="mt-4 pt-4 border-t flex gap-2">
                    <button @click="openEditUserModal(user)" class="flex-1 px-3 py-2 text-sm bg-gray-200 text-gray-700 font-semibold rounded-lg hover:bg-gray-300">تعديل</button>
                    <button @click="deleteUser(user.user_id)" class="flex-1 px-3 py-2 text-sm bg-red-100 text-red-700 font-semibold rounded-lg hover:bg-red-200">حذف</button>
                </div>
            </div>
        </template>
    </div>

</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('userManagement', () => ({
        users: <?= json_encode($all_users, JSON_UNESCAPED_UNICODE) ?>,
        branches: <?= json_encode($all_branches, JSON_UNESCAPED_UNICODE) ?>,
        api_url: 'api_handler.php',
        colors: ['#3B82F6', '#10B981', '#F59E0B', '#8B5CF6', '#EF4444', '#6366F1', '#14B8A6', '#F97316'],
        userColors: {},

        getPermissionName(permission) {
            const names = { 
                admin: 'مدير النظام', 
                manager: 'مسؤول صيانة', 
                technician: 'فني صيانة',
                storekeeper: 'أمين مخازن',
                sales: 'مبيعات',
                prep_engineer: 'مهندس تجهيز'
            };
            return names[permission] || permission;
        },

        getPermissionClass(permission) {
            const classes = { 
                admin: 'text-red-600', 
                manager: 'text-purple-600', 
                technician: 'text-blue-600',
                storekeeper: 'text-orange-600',
                sales: 'text-green-600',
                prep_engineer: 'text-teal-600'
            };
            return classes[permission] || 'text-gray-600';
        },

        userColor(username) {
            if (!this.userColors[username]) {
                let hash = 0;
                for (let i = 0; i < username.length; i++) {
                    hash = username.charCodeAt(i) + ((hash << 5) - hash);
                }
                this.userColors[username] = this.colors[Math.abs(hash % this.colors.length)];
            }
            return this.userColors[username];
        },

        async openAddUserModal() {
            const { value: formValues } = await Swal.fire({
                title: 'إضافة مستخدم جديد',
                html: `
                    <input id="swal-username" class="swal2-input" placeholder="اسم المستخدم" required>
                    <input id="swal-password" type="password" class="swal2-input" placeholder="كلمة المرور" required>
                    <input id="swal-branch" class="swal2-input" placeholder="اسم الفرع (جديد أو اختر)" list="branch-list" required>
                    <datalist id="branch-list">
                        ${this.branches.map(branch => `<option value="${branch}"></option>`).join('')}
                    </datalist>
                    <select id="swal-permissions" class="swal2-select">
                        <option value="technician">فني صيانة</option>
                        <option value="prep_engineer">مهندس تجهيز</option>
                        <option value="storekeeper">أمين مخازن</option>
                        <option value="sales">مبيعات</option>
                        <option value="manager">مسؤول صيانة</option>
                        <option value="admin">مدير النظام</option>
                    </select>
                `,
                focusConfirm: false,
                preConfirm: () => {
                    return {
                        username: document.getElementById('swal-username').value,
                        password: document.getElementById('swal-password').value,
                        branch_name: document.getElementById('swal-branch').value,
                        permissions: document.getElementById('swal-permissions').value
                    }
                }
            });

            if (formValues) {
                const formData = new FormData();
                formData.append('username', formValues.username);
                formData.append('password', formValues.password);
                formData.append('branch_name', formValues.branch_name);
                formData.append('permissions', formValues.permissions);

                const response = await this.sendRequest('add_user', formData);
                if (response.success) {
                    const newUser = { ...response.user, entered_count: 0, assigned_count: 0, closed_count: 0 };
                    this.users.push(newUser);
                    if (!this.branches.includes(formValues.branch_name)) {
                        this.branches.push(formValues.branch_name);
                    }
                    Swal.fire('تم!', 'تم إضافة المستخدم بنجاح.', 'success');
                } else {
                    Swal.fire('خطأ!', response.message, 'error');
                }
            }
        },

        async openEditUserModal(user) {
            const { value: formValues } = await Swal.fire({
                title: 'تعديل بيانات المستخدم',
                html: `
                    <input id="swal-username" class="swal2-input" placeholder="اسم المستخدم" value="${user.username}" required>
                    <input id="swal-password" type="password" class="swal2-input" placeholder="كلمة مرور جديدة (اتركه فارغاً لعدم التغيير)">
                    <input id="swal-branch" class="swal2-input" placeholder="اسم الفرع" value="${user.branch_name || ''}" list="branch-list" required>
                    <datalist id="branch-list">
                        ${this.branches.map(branch => `<option value="${branch}"></option>`).join('')}
                    </datalist>
                    <select id="swal-permissions" class="swal2-select">
                        <option value="technician" ${user.permissions === 'technician' ? 'selected' : ''}>فني صيانة</option>
                        <option value="prep_engineer" ${user.permissions === 'prep_engineer' ? 'selected' : ''}>مهندس تجهيز</option>
                        <option value="storekeeper" ${user.permissions === 'storekeeper' ? 'selected' : ''}>أمين مخازن</option>
                        <option value="sales" ${user.permissions === 'sales' ? 'selected' : ''}>مبيعات</option>
                        <option value="manager" ${user.permissions === 'manager' ? 'selected' : ''}>مسؤول صيانة</option>
                        <option value="admin" ${user.permissions === 'admin' ? 'selected' : ''}>مدير النظام</option>
                    </select>
                `,
                focusConfirm: false,
                preConfirm: () => {
                    return {
                        username: document.getElementById('swal-username').value,
                        password: document.getElementById('swal-password').value,
                        branch_name: document.getElementById('swal-branch').value,
                        permissions: document.getElementById('swal-permissions').value
                    }
                }
            });

            if (formValues) {
                const formData = new FormData();
                formData.append('user_id', user.user_id);
                formData.append('username', formValues.username);
                formData.append('password', formValues.password);
                formData.append('branch_name', formValues.branch_name);
                formData.append('permissions', formValues.permissions);

                const response = await this.sendRequest('update_user', formData);
                if (response.success) {
                    const userIndex = this.users.findIndex(u => u.user_id === user.user_id);
                    if (userIndex > -1) {
                        this.users[userIndex].username = formValues.username;
                        this.users[userIndex].permissions = formValues.permissions;
                        this.users[userIndex].branch_name = formValues.branch_name;
                    }
                    Swal.fire('تم!', 'تم تحديث المستخدم بنجاح.', 'success');
                } else {
                    Swal.fire('خطأ!', response.message, 'error');
                }
            }
        },

        deleteUser(userId) {
            Swal.fire({
                title: 'هل أنت متأكد؟',
                text: "لن تتمكن من التراجع عن هذا الإجراء!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'نعم، قم بالحذف!',
                cancelButtonText: 'إلغاء'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('user_id', userId);
                    const response = await this.sendRequest('delete_user', formData);
                    if (response.success) {
                        this.users = this.users.filter(u => u.user_id !== userId);
                        Swal.fire('تم الحذف!', response.message, 'success');
                    } else {
                        Swal.fire('خطأ!', response.message, 'error');
                    }
                }
            });
        },

        async sendRequest(action, formData) {
            try {
                const response = await fetch(`${this.api_url}?action=${action}`, {
                    method: 'POST',
                    body: formData
                });
                return await response.json();
            } catch (error) {
                console.error('API Error:', error);
                return { success: false, message: 'فشل الاتصال بالخادم.' };
            }
        }
    }));
});
</script>

</body>
</html>
