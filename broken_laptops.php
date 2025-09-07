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
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// =================================================================================
// DATA FETCHING FOR THE ENTIRE PAGE
// =================================================================================

// 1. Fetch all laptops with their related data
$laptops_query = $pdo->query("
    SELECT 
        b.laptop_id,
        b.serial_number,
        b.employee_name,
        b.item_number,
        b.status,
        b.specs,
        b.problem_details,
        c.category_name,
        u_assigned.username AS assigned_to,
        op.first_entry_date
    FROM broken_laptops b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN users u_assigned ON b.assigned_user_id = u_assigned.user_id
    LEFT JOIN (
        SELECT laptop_id, MIN(operation_date) as first_entry_date 
        FROM operations 
        GROUP BY laptop_id
    ) op ON b.laptop_id = op.laptop_id
    ORDER BY b.laptop_id DESC
");
$all_laptops = $laptops_query->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch data for filter dropdowns
$assignable_users_query = $pdo->query("SELECT user_id, username FROM users WHERE permissions IN ('technician', 'admin', 'manager') ORDER BY username");
$assignable_users = $assignable_users_query->fetchAll();

$statuses_query = $pdo->query("SELECT DISTINCT status FROM broken_laptops WHERE status IS NOT NULL AND status != '' ORDER BY status");
$statuses = $statuses_query->fetchAll(PDO::FETCH_COLUMN);

// =================================================================================
// HELPER FUNCTION for status colors and names
// =================================================================================
function getStatusDetails($status) {
    $status_map = [
        'entered' => ['name' => 'تم الإدخال', 'color' => 'gray'],
        'review_pending' => ['name' => 'قيد المراجعة', 'color' => 'orange'],
        'assigned' => ['name' => 'مُعيّن', 'color' => 'blue'],
        'in_repair' => ['name' => 'قيد الإصلاح', 'color' => 'yellow'],
        'returned_for_review' => ['name' => 'مرجع للمراجعة', 'color' => 'purple'],
        'locked' => ['name' => 'مغلق', 'color' => 'green'],
        'مغلق' => ['name' => 'مغلق', 'color' => 'green'], // Handle old status
    ];
    return $status_map[$status] ?? ['name' => ucfirst($status), 'color' => 'gray'];
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>عرض الأجهزة - لوحة تحكم الصيانة</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        [x-cloak] { display: none !important; }
        .card-status-border-gray { border-right-color: #6B7280; }
        .card-status-border-orange { border-right-color: #F97316; }
        .card-status-border-blue { border-right-color: #3B82F6; }
        .card-status-border-yellow { border-right-color: #F59E0B; }
        .card-status-border-purple { border-right-color: #8B5CF6; }
        .card-status-border-green { border-right-color: #10B981; }
        .card-enter-active, .card-leave-active { transition: all 0.3s ease-out; }
        .card-enter-from { opacity: 0; transform: scale(0.95); }
        .card-enter-to { opacity: 1; transform: scale(1); }
        .card-leave-from { opacity: 1; transform: scale(1); }
        .card-leave-to { opacity: 0; transform: scale(0.95); }
    </style>
</head>
<body class="bg-gray-100">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8" x-data="laptopsPage()">
        
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-center mb-6 gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">سجل الأجهزة</h1>
                <p class="text-gray-500">عرض وتصفية جميع تذاكر الصيانة المسجلة</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="add_broken_laptop.php" class="px-4 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                    <span>إضافة جهاز جديد</span>
                </a>
                <?php if (in_array($_SESSION['permissions'], ['admin', 'manager', 'accountant'])): ?>
                <a href="financial_management/index.php" class="px-4 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path></svg>
                    <span>الإدارة المالية</span>
                </a>
                <?php endif; ?>
                <a href="index.php" class="text-sm text-gray-600 hover:text-blue-600">العودة للرئيسية</a>
            </div>
        </div>

        <!-- Filters and View Toggle -->
        <div class="bg-white p-4 rounded-xl shadow-md mb-8">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                <!-- Search -->
                <div class="lg:col-span-2">
                    <label for="search" class="text-sm font-medium text-gray-700">بحث شامل</label>
                    <input type="text" x-model.debounce.300ms="filters.search" id="search" placeholder="ابحث بالرقم التسلسلي، المواصفات، الموظف..." class="mt-1 w-full p-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
                <!-- Status Filter -->
                <div>
                    <label for="status" class="text-sm font-medium text-gray-700">الحالة</label>
                    <select x-model="filters.status" id="status" class="mt-1 w-full p-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <option value="">الكل</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(getStatusDetails($status)['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Assigned User Filter -->
                <div>
                    <label for="assigned" class="text-sm font-medium text-gray-700">الفني المسؤول</label>
                    <select x-model="filters.assignedTo" id="assigned" class="mt-1 w-full p-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <option value="">الكل</option>
                        <?php foreach ($assignable_users as $user): ?>
                            <option value="<?= htmlspecialchars($user['username']) ?>"><?= htmlspecialchars($user['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- View Toggle -->
                <div class="flex items-end">
                    <div class="p-1 bg-gray-200 rounded-lg flex">
                        <button @click="viewMode = 'grid'" :class="viewMode === 'grid' ? 'bg-white text-blue-600 shadow' : 'text-gray-500'" class="p-2 rounded-md">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        </button>
                        <button @click="viewMode = 'list'" :class="viewMode === 'list' ? 'bg-white text-blue-600 shadow' : 'text-gray-500'" class="p-2 rounded-md">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Laptops Display Area -->
        <div x-show="isLoading" class="text-center py-10">
            <div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin mx-auto"></div>
            <p class="mt-2 text-gray-600">جاري تحميل البيانات...</p>
        </div>

        <div x-show="!isLoading" x-cloak>
            <!-- Grid View (Cards) -->
            <div x-show="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <template x-for="laptop in filteredLaptops" :key="laptop.laptop_id">
                    <div x-transition:enter="card-enter-active" x-transition:enter-start="card-enter-from" x-transition:enter-end="card-enter-to"
                         class="bg-white rounded-xl shadow-lg hover:shadow-xl transition-shadow duration-300 border-r-4" :class="'card-status-border-' + laptop.statusDetails.color">
                        <div class="p-5">
                            <div class="flex justify-between items-start">
                                <div class="min-w-0 text-left">
                                    <h4 class="text-sm text-gray-500 truncate mt-1" x-text="laptop.laptop_id">#</h4>
<h3 class="font-bold text-lg text-gray-800 whitespace-normal break-words text-left" x-text="laptop.specs || 'لا توجد مواصفات'"></h3>
                                    <p class="text-sm text-gray-600 mt-1 whitespace-normal break-words" x-text="laptop.detailsDisplay || ''"></p>
                                    <p class="text-sm text-gray-500 truncate mt-1" x-text="laptop.serial_number"></p>
                                </div>
                                <div class="relative" x-data="{ open: false }">
                                    <button @click="open = !open" class="p-1 text-gray-500 hover:bg-gray-100 rounded-full">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path></svg>
                                    </button>
                                    <div x-show="open" @click.away="open = false" x-transition class="absolute left-0 mt-2 w-40 bg-white rounded-md shadow-lg z-10">
                                        <a :href="'laptop_chat.php?laptop_id=' + laptop.laptop_id" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">عرض الدردشة</a>
                                         <a :href="'transfers1.php?laptop_id=' + laptop.laptop_id" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">  تحويل </a>
                                        <a :href="'assign_tasks.php?laptop_id=' + laptop.laptop_id" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"> تكليف مهندس</a>
                                        <a :href="'operations.php?laptop_id=' + laptop.laptop_id" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">تسجيل عملية</a>
                                        <a :href="'locks.php?laptop_id=' + laptop.laptop_id" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50">إغلاق التذكرة</a>
                                        <a :href="'device_report.php?laptop_id=' + laptop.laptop_id" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50"> تقرير تفصيلي</a>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full" :class="{
                                    'bg-gray-100 text-gray-700': laptop.statusDetails.color === 'gray',
                                    'bg-orange-100 text-orange-700': laptop.statusDetails.color === 'orange',
                                    'bg-blue-100 text-blue-700': laptop.statusDetails.color === 'blue',
                                    'bg-yellow-100 text-yellow-700': laptop.statusDetails.color === 'yellow',
                                    'bg-purple-100 text-purple-700': laptop.statusDetails.color === 'purple',
                                    'bg-green-100 text-green-700': laptop.statusDetails.color === 'green',
                                }" x-text="laptop.statusDetails.name"></span>
                            </div>
                            <div class="mt-4 border-t pt-4 space-y-2 text-sm text-gray-600">
                                <!--<p><strong class="font-semibold">المشكلة الرئيسية:</strong> <span class="text-gray-800" x-text="laptop.firstProblem"></span></p>-->
                                                               <p><strong class="font-semibold">رقم الصنف:</strong> <span x-text="laptop.item_number || 'غير محدد'"></span></p>
                            <p><strong class="font-semibold">الموظف:</strong> <span x-text="laptop.employee_name || 'غير محدد'"></span></p>
                                <p><strong class="font-semibold">الفني:</strong> <span x-text="laptop.assigned_to || 'لم يعين'"></span></p>
                                <p><strong class="font-semibold">تاريخ الإدخال:</strong> <span x-text="new Date(laptop.first_entry_date).toISOString().slice(0,10)"></span></p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- List View (Table) -->
            <div x-show="viewMode === 'list'" class="bg-white rounded-xl shadow-md overflow-x-auto">
                <table class="w-full text-right">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="p-3 text-sm font-semibold text-gray-600">المواصفات</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">الرقم التسلسلي</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">الموظف</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">الحالة</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">الفني المسؤول</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">تاريخ الإدخال</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="laptop in filteredLaptops" :key="laptop.laptop_id">
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-3 font-semibold text-left">
                                    <div x-text="laptop.specs || 'N/A'"></div>
                                    <div class="text-sm text-gray-600" x-text="laptop.detailsDisplay || ''"></div>
                                </td>
                                <td class="p-3" x-text="laptop.serial_number"></td>
                                <td class="p-3" x-text="laptop.employee_name || 'N/A'"></td>
                                <td class="p-3">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full" :class="{
                                        'bg-gray-100 text-gray-700': laptop.statusDetails.color === 'gray',
                                        'bg-orange-100 text-orange-700': laptop.statusDetails.color === 'orange',
                                        'bg-blue-100 text-blue-700': laptop.statusDetails.color === 'blue',
                                        'bg-yellow-100 text-yellow-700': laptop.statusDetails.color === 'yellow',
                                        'bg-purple-100 text-purple-700': laptop.statusDetails.color === 'purple',
                                        'bg-green-100 text-green-700': laptop.statusDetails.color === 'green',
                                    }" x-text="laptop.statusDetails.name"></span>
                                </td>
                                <td class="p-3" x-text="laptop.assigned_to || 'لم يعين'"></td>
                                <td class="p-3" x-text="new Date(laptop.first_entry_date).toISOString().slice(0,10)"></td>
                                <td class="p-3">
                                    <a :href="'laptop_chat.php?laptop_id=' + laptop.laptop_id" class="text-blue-600 hover:underline">تفاصيل</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- No Results Message -->
            <div x-show="!filteredLaptops.length" class="text-center py-16 bg-white rounded-xl shadow-md">
                <svg class="w-16 h-16 mx-auto text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <h3 class="mt-2 text-lg font-semibold text-gray-800">لا توجد نتائج مطابقة</h3>
                <p class="mt-1 text-gray-500">حاول تغيير أو إزالة الفلاتر المطبقة.</p>
            </div>
        </div>

    </div>

    <script>
        function laptopsPage() {
            return {
                isLoading: true,
                viewMode: 'grid', // 'grid' or 'list'
                laptops: [],
                filteredLaptops: [],
                filters: {
                    search: '',
                    status: '',
                    assignedTo: ''
                },

                init() {
                    // Fetch data from PHP and enrich it
                    let rawData = <?= json_encode($all_laptops, JSON_UNESCAPED_UNICODE) ?>;
                    this.laptops = rawData.map(laptop => {
                        // Build a detailed first message that includes specs and full problem details
                        let specs = laptop.specs && laptop.specs.trim() ? laptop.specs.trim() : 'لا توجد مواصفات';
                        let details = '';
                        if (laptop.problem_details) {
                            try {
                                const problems = JSON.parse(laptop.problem_details);
                                if (Array.isArray(problems) && problems.length) {
                                    details = problems.map(p => {
                                        const title = p.title ? p.title.trim() : '';
                                        const det = p.details ? p.details.trim() : '';
                                        if (title && det) return title + ': ' + det;
                                        return title || det || '';
                                    }).filter(Boolean).join(' | ');
                                } else if (typeof problems === 'object' && (problems.title || problems.details)) {
                                    details = (problems.title || '') + (problems.details ? ': ' + problems.details : '');
                                } else {
                                    details = String(problems);
                                }
                            } catch (e) {
                                // If not JSON, use raw string
                                details = laptop.problem_details;
                            }
                        }

                        const firstProblem = specs + (details ? ' — ' + details : '');

                        return {
                            ...laptop,
                            firstProblem: firstProblem,
                            detailsDisplay: details,
                            statusDetails: <?= json_encode(array_map('getStatusDetails', array_column($all_laptops, 'status', 'status'))) ?>[laptop.status] || { name: laptop.status, color: 'gray' }
                        };
                    });
                    
                    this.applyFilters();
                    this.isLoading = false;

                    // Watch for any changes in the filters object
                    this.$watch('filters', () => {
                        this.applyFilters();
                    }, { deep: true });
                },

                applyFilters() {
                    const { search, status, assignedTo } = this.filters;
                    const searchTerm = search.toLowerCase();
                    
                    this.filteredLaptops = this.laptops.filter(laptop => {
                        const searchMatch = !searchTerm || 
                            (laptop.serial_number && laptop.serial_number.toLowerCase().includes(searchTerm)) ||
                            (laptop.specs && laptop.specs.toLowerCase().includes(searchTerm)) ||
                            (laptop.employee_name && laptop.employee_name.toLowerCase().includes(searchTerm)) ||
                            (laptop.item_number && laptop.item_number.toLowerCase().includes(searchTerm));

                        const statusMatch = !status || laptop.status === status;
                        const assignedMatch = !assignedTo || laptop.assigned_to === assignedTo;
                        
                        return searchMatch && statusMatch && assignedMatch;
                    });
                }
            }
        }
    </script>

</body>
</html>
