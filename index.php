<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$permissions = $_SESSION['permissions'];

// جلب إحصائيات الأجهزة
$total_laptops = $pdo->query("SELECT COUNT(*) FROM broken_laptops")->fetchColumn();
$open_laptops = $pdo->query("SELECT COUNT(*) FROM broken_laptops WHERE status NOT IN ('locked', 'مغلق')")->fetchColumn();
$closed_laptops = $pdo->query("SELECT COUNT(*) FROM broken_laptops WHERE status IN ('locked', 'مغلق')")->fetchColumn();
$pending_review = $pdo->query("SELECT COUNT(*) FROM broken_laptops WHERE status = 'review_pending'")->fetchColumn();

// حساب متوسط وقت الإصلاح
$avg_repair_time_query = $pdo->query("
    SELECT AVG(DATEDIFF(l.lock_date, o.operation_date)) 
    FROM locks l
    JOIN (
        SELECT laptop_id, MIN(operation_date) as operation_date 
        FROM operations 
        GROUP BY laptop_id
    ) o ON l.laptop_id = o.laptop_id
");
$avg_repair_time = $avg_repair_time_query ? round((float)$avg_repair_time_query->fetchColumn(), 1) : 'N/A';

// جلب بيانات المخطط البياني للأسبوع الأخير
$chart_labels = [];
$chart_data_added = [];
$chart_data_closed = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('D, M j', strtotime($date));
    $stmt_added = $pdo->prepare("SELECT COUNT(*) FROM operations WHERE DATE(operation_date) = ? AND repair_result LIKE '%إدخال الجهاز%'");
    $stmt_added->execute([$date]);
    $chart_data_added[] = $stmt_added->fetchColumn();
    $stmt_closed = $pdo->prepare("SELECT COUNT(*) FROM locks WHERE DATE(lock_date) = ?");
    $stmt_closed->execute([$date]);
    $chart_data_closed[] = $stmt_closed->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم الاحترافية</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root { --sidebar-width: 260px; --header-height: 64px; }
        body { font-family: 'Cairo', sans-serif; transition: background-color 0.3s, color 0.3s; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        .dark ::-webkit-scrollbar-track { background: #2d3748; }
        .dark ::-webkit-scrollbar-thumb { background: #718096; }
        #sidebar { transition: margin-right 0.3s ease-in-out; }
        #sidebar.collapsed { margin-right: calc(-1 * var(--sidebar-width)); }
        #main-content { transition: margin-right 0.3s ease-in-out; margin-right: var(--sidebar-width); }
        #main-content.expanded { margin-right: 0; }
        .sidebar-link-icon { transition: transform 0.2s; }
        .sidebar-link:hover .sidebar-link-icon { transform: scale(1.1); }
        .loader { border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        #search-results { max-height: 300px; overflow-y: auto; }
        .kpi-card { transition: transform 0.3s; }
        .kpi-card:hover { transform: translateY(-5px); }
        .widget { box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: box-shadow 0.3s; }
        .widget:hover { box-shadow: 0 10px 15px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200">

    <div class="flex h-screen overflow-hidden">
        
        <!-- SIDEBAR NAVIGATION -->
        <aside id="sidebar" class="w-[var(--sidebar-width)] bg-white dark:bg-gray-800 shadow-lg flex flex-col fixed top-0 right-0 h-full z-30">
            <div class="flex items-center justify-center h-[var(--header-height)] border-b border-gray-200 dark:border-gray-700">
                <svg class="w-8 h-8 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" /></svg>
                <h1 class="text-xl font-bold ml-2 text-gray-800 dark:text-white">نظام الصيانة</h1>
            </div>
            <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto">
                <a href="index.php" class="sidebar-link flex items-center gap-4 px-4 py-3 text-white bg-blue-500 rounded-lg shadow-md"><svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h7.5" /></svg><span class="font-semibold">الرئيسية</span></a>
                <a href="add_broken_laptop.php" class="sidebar-link flex items-center gap-4 px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg"><svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg><span>إضافة جهاز</span></a>
                <a href="broken_laptops.php" class="sidebar-link flex items-center gap-4 px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg"><svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-1.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z" /></svg><span>عرض الأجهزة</span></a>
                
                <a href="device_lookup.php" class="sidebar-link flex items-center gap-4 px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg"><svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h12A2.25 2.25 0 0020.25 14.25V3.75A2.25 2.25 0 0018 1.5H6A2.25 2.25 0 003.75 3zM12 18.75m-2.25 0a2.25 2.25 0 104.5 0 2.25 2.25 0 10-4.5 0z" /></svg><span>التقارير</span></a>
                 <a href="/warehouse_management/warehouse_permissions.php" class="sidebar-link flex items-center gap-4 px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg"><svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-1.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z" /></svg><span> تحويل واستلام</span></a>
                <?php if ($permissions == 'admin' || $permissions == 'manager'): ?>
                    <a href="users.php" class="sidebar-link flex items-center gap-4 px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg"><svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663v-.005z" /></svg><span>إدارة المستخدمين</span></a>
                <?php endif; ?>
                
              
                    <a href="r.php" class="sidebar-link flex items-center gap-4 px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg"><svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663v-.005z" /></svg><span>ابحث عن تحويل </span></a>
                
                <!-- رابط إدارة المخازن -->
                <a href="warehouse_management/" class="sidebar-link flex items-center gap-4 px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">
                    <svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.364 2.636l-4.5 1.636M21.75 9l-4.5 1.636M2.25 9v10.125c0 .621.504 1.125 1.125 1.125h2.25c.621 0 1.125-.504 1.125-1.125V9m0 0h4.5" />
                    </svg>
                    <span>إدارة المخازن</span>
                </a>
                <a href="financial_management/" class="sidebar-link flex items-center gap-4 px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">
                    <svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.364 2.636l-4.5 1.636M21.75 9l-4.5 1.636M2.25 9v10.125c0 .621.504 1.125 1.125 1.125h2.25c.621 0 1.125-.504 1.125-1.125V9m0 0h4.5" />
                    </svg>
                    <span>إدارة المالية</span>
                </a>
                 <?php if ($permissions == 'admin' || $permissions == 'manager'): ?>
                    <a href="manage_tickets.php" class="sidebar-link flex items-center gap-4 px-4 py-3 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg"><svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-4.663v-.005z" /></svg><span>تعديل االمذكرات </span></a>
                <?php endif; ?>
                
                
            </nav>
            <div class="px-4 py-3 mt-auto border-t border-gray-200 dark:border-gray-700"><a href="logout.php" class="sidebar-link flex items-center gap-4 px-4 py-3 text-red-500 hover:bg-red-50 dark:hover:bg-red-500 dark:hover:text-white rounded-lg"><svg class="sidebar-link-icon w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg><span>تسجيل الخروج</span></a></div>
        </aside>

        <!-- MAIN CONTENT AREA -->
        <div id="main-content" class="flex-1 flex flex-col overflow-hidden">
            
            <!-- Header -->
            <header class="h-[var(--header-height)] bg-white dark:bg-gray-800 shadow-sm flex items-center justify-between px-6 z-20">
                <div class="flex items-center gap-4">
                    <button id="sidebar-toggle" class="p-2 -mr-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full"><svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg></button>
                    <div><h2 class="text-xl font-bold text-gray-800 dark:text-white">لوحة التحكم </h2></div>
                
                <div class="flex items-center gap-3">
                    <div class="relative hidden md:block">
                        <input type="text" id="live-search-input" placeholder="بحث بالرقم التسلسلي..." class="w-48 lg:w-64 pl-10 pr-4 py-2 bg-gray-100 dark:bg-gray-700 border border-transparent rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <svg class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                        <div id="search-results" class="absolute top-full mt-2 w-full bg-white dark:bg-gray-800 rounded-lg shadow-xl border dark:border-gray-700 hidden z-30"></div>
                    </div>
                    
                    <button id="theme-toggle" class="p-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full"><svg id="theme-icon-light" class="w-6 h-6 hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.95-4.243l-1.59-1.591M5.25 12H3m4.243-4.95l-1.59-1.591M12 18a6 6 0 100-12 6 6 0 000 12z" /></svg><svg id="theme-icon-dark" class="w-6 h-6 hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg></button>

                    <!-- Notifications Bell -->
                    <div class="relative">
                        <button id="notification-bell" class="p-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full" aria-haspopup="dialog" aria-controls="notification-modal" aria-expanded="false">
                            <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
                        </button>
                        <span id="notification-dot" class="absolute top-1 right-1 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white dark:border-gray-800 hidden"></span>
                    </div>

                    <!-- Notification Modal (centered) -->
                    <div id="notification-modal" class="fixed inset-0 hidden z-50 flex items-center justify-center">
                        <div id="notification-backdrop" class="absolute inset-0 bg-black bg-opacity-50"></div>
                        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-11/12 max-w-lg mx-auto z-10">
                            <div class="p-3 border-b dark:border-gray-700 flex justify-between items-center">
                                <div class="font-bold">الإشعارات</div>
                                <button id="notification-close" class="text-gray-600 hover:text-gray-800 p-2">&times;</button>
                            </div>
                            <div id="notification-list" class="p-2 max-h-80 overflow-y-auto"></div>
                        </div>
                    </div>

                    <div class="relative">
                        <button id="profile-toggle" class="flex items-center gap-2" aria-haspopup="true" aria-controls="profile-panel" aria-expanded="false">
                            <img src="https://placehold.co/40x40/E2E8F0/4A5568?text=<?= strtoupper(substr($username, 0, 1)) ?>" alt="Avatar" class="w-10 h-10 rounded-full">
                            <div class="text-right sm:block">
                                <p class="font-semibold text-sm"><?= htmlspecialchars($username) ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars(ucfirst($permissions)) ?></p>
                            </div>
                        </button>
                        <!-- Small dropdown panel that appears under the avatar -->
                        <div id="profile-panel" class="absolute top-full mt-2 right-0 w-44 bg-white dark:bg-gray-800 rounded-lg shadow-xl border z-20 hidden">
                            <div class="p-3 border-b dark:border-gray-700">
                                <p class="font-semibold text-sm"><?= htmlspecialchars($username) ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars(ucfirst($permissions)) ?></p>
                            </div>
                            <a href="change_password.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700">تغيير كلمة المرور</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-600">تسجيل الخروج</a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Dashboard Content -->
            <main class="flex-1 p-6 lg:p-8 overflow-y-auto">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-md flex items-center gap-5 kpi-card">
                        <div class="bg-blue-100 dark:bg-blue-500/20 p-4 rounded-full">
                            <svg class="w-8 h-8 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-1.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">إجمالي الأجهزة</p>
                            <p class="text-3xl font-bold"><?= $total_laptops ?></p>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-md flex items-center gap-5 kpi-card">
                        <div class="bg-yellow-100 dark:bg-yellow-500/20 p-4 rounded-full">
                            <svg class="w-8 h-8 text-yellow-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0011.667 0l3.181-3.183m-4.991-2.691V5.25a2.25 2.25 0 00-2.25-2.25L10.5 3z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">أجهزة مفتوحة</p>
                            <p class="text-3xl font-bold"><?= $open_laptops ?></p>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-md flex items-center gap-5 kpi-card">
                        <div class="bg-green-100 dark:bg-green-500/20 p-4 rounded-full">
                            <svg class="w-8 h-8 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">أجهزة مغلقة</p>
                            <p class="text-3xl font-bold"><?= $closed_laptops ?></p>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-md flex items-center gap-5 kpi-card">
                        <div class="bg-indigo-100 dark:bg-indigo-500/20 p-4 rounded-full">
                            <svg class="w-8 h-8 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.125 2.25h-4.5c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125v-9M10.125 2.25h.375a9 9 0 019 9v.375M10.125 2.25A3.375 3.375 0 0113.5 5.625v1.5c0 .621.504 1.125 1.125 1.125h1.5a3.375 3.375 0 013.375 3.375M9 15l2.25 2.25L15 12" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">نسبة الإنجاز</p>
                            <p class="text-3xl font-bold"><?= $total_laptops > 0 ? round(($closed_laptops / $total_laptops) * 100) : 0 ?>%</p>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-md flex items-center gap-5 kpi-card">
                        <div class="bg-pink-100 dark:bg-pink-500/20 p-4 rounded-full">
                            <svg class="w-8 h-8 text-pink-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">متوسط الإصلاح</p>
                            <p class="text-3xl font-bold"><?= $avg_repair_time ?> <span class="text-lg">يوم</span></p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-5 gap-8 mb-8">
                    <div class="lg:col-span-3 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-md widget">
                        <h3 class="text-xl font-bold mb-4">أداء آخر 7 أيام</h3>
                        <div class="h-80"><canvas id="performanceChart"></canvas></div>
                    </div>
                    
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-md widget">
                        <h3 class="text-xl font-bold mb-4">توزيع حالات الأجهزة</h3>
                        <div class="h-80"><canvas id="statusDistributionChart"></canvas></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div id="activity-widget" class="lg:col-span-1 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-md widget">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-xl font-bold">آخر النشاطات</h3>
                            <button id="refresh-activity" class="p-1 text-gray-500 hover:text-blue-500">
                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0011.667 0l3.181-3.183m-4.991-2.691V5.25a2.25 2.25 0 00-2.25-2.25L10.5 3z" />
                                </svg>
                            </button>
                        </div>
                        <div id="activity-list" class="space-y-4 overflow-y-auto h-80 pr-2"></div>
                    </div>
                    
                    <div id="attention-widget" class="lg:col-span-1 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-md widget">
                        <h3 class="text-xl font-bold mb-4">أجهزة تتطلب الانتباه</h3>
                        <div id="attention-list" class="space-y-3 h-80 overflow-y-auto"></div>
                    </div>
                    
                    <?php if ($permissions == 'admin' || $permissions == 'manager'): ?>
                    <div id="leaderboard-widget" class="lg:col-span-1 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-md widget">
                        <h3 class="text-xl font-bold mb-4">أفضل الفنيين أداءً</h3>
                        <ul id="leaderboard-list" class="space-y-3 h-80 overflow-y-auto"></ul>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Ensure sidebar is collapsed on small screens (mobile) when page loads
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            function applyResponsiveSidebar() {
                try {
                    if (window.innerWidth <= 768) { // mobile / small
                        sidebar.classList.add('collapsed');
                        mainContent.classList.add('expanded');
                    } else {
                        sidebar.classList.remove('collapsed');
                        mainContent.classList.remove('expanded');
                    }
                } catch (e) {
                    // fail silently if elements not present for some reason
                    console.warn('Responsive sidebar init failed', e);
                }
            }
            // Apply immediately and also on resize
            applyResponsiveSidebar();
            window.addEventListener('resize', applyResponsiveSidebar);

            const API_URL = 'api_handler.php';
            const isDarkMode = () => document.documentElement.classList.contains('dark');

            // THEME (DARK/LIGHT MODE) TOGGLE
            const themeToggle = document.getElementById('theme-toggle');
            const themeIconLight = document.getElementById('theme-icon-light');
            const themeIconDark = document.getElementById('theme-icon-dark');
            const html = document.documentElement;
            const applyTheme = (theme) => {
                if (theme === 'dark') {
                    html.classList.add('dark');
                    themeIconLight.classList.remove('hidden');
                    themeIconDark.classList.add('hidden');
                } else {
                    html.classList.remove('dark');
                    themeIconLight.classList.add('hidden');
                    themeIconDark.classList.remove('hidden');
                }
            };
            const savedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            applyTheme(savedTheme);
            themeToggle.addEventListener('click', () => {
                const newTheme = html.classList.contains('dark') ? 'light' : 'dark';
                localStorage.setItem('theme', newTheme);
                applyTheme(newTheme);
                // Redraw charts with new theme colors
                performanceChart.options.plugins.legend.labels.color = isDarkMode() ? '#E5E7EB' : '#4B5563';
                performanceChart.options.scales.y.grid.color = isDarkMode() ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
                performanceChart.options.scales.y.ticks.color = isDarkMode() ? '#9CA3AF' : '#6B7280';
                performanceChart.options.scales.x.ticks.color = isDarkMode() ? '#9CA3AF' : '#6B7280';
                performanceChart.update();
                statusChart.options.plugins.legend.labels.color = isDarkMode() ? '#E5E7EB' : '#4B5563';
                statusChart.update();
            });

            // SIDEBAR TOGGLE
            document.getElementById('sidebar-toggle').addEventListener('click', () => {
                document.getElementById('sidebar').classList.toggle('collapsed');
                document.getElementById('main-content').classList.toggle('expanded');
            });

            // LIVE SEARCH
            const searchInput = document.getElementById('live-search-input');
            const searchResults = document.getElementById('search-results');
            let searchTimeout;
            searchInput.addEventListener('keyup', () => {
                clearTimeout(searchTimeout);
                const query = searchInput.value;
                if (query.length < 2) {
                    searchResults.classList.add('hidden');
                    return;
                }
                searchResults.innerHTML = `<div class="p-4 flex justify-center"><div class="loader"></div></div>`;
                searchResults.classList.remove('hidden');
                searchTimeout = setTimeout(() => {
                    fetch(`${API_URL}?action=search&query=${query}`)
                        .then(response => response.json())
                        .then(data => {
                            searchResults.innerHTML = '';
                            if (data.length > 0) {
                                data.forEach(item => {
                                    const resultItem = document.createElement('a');
                                    resultItem.href = `laptop_chat.php?laptop_id=${item.laptop_id}`;
                                    resultItem.className = 'block p-3 hover:bg-gray-100 dark:hover:bg-gray-700 border-b dark:border-gray-700';
                                    resultItem.innerHTML = `<p class="font-bold">${item.serial_number}</p><p class="text-sm text-gray-500">${item.status || 'N/A'}</p>`;
                                    searchResults.appendChild(resultItem);
                                });
                            } else {
                                searchResults.innerHTML = `<p class="p-3 text-center text-gray-500">لا توجد نتائج</p>`;
                            }
                        });
                }, 500);
            });
            document.addEventListener('click', (e) => { if (!searchResults.contains(e.target) && e.target !== searchInput) searchResults.classList.add('hidden'); });

            // NOTIFICATIONS
            const notificationBell = document.getElementById('notification-bell');
            const notificationDot = document.getElementById('notification-dot');
            const notificationModal = document.getElementById('notification-modal');
            const notificationBackdrop = document.getElementById('notification-backdrop');
            const notificationClose = document.getElementById('notification-close');
            const notificationList = document.getElementById('notification-list');
            
            function fetchNotifications() {
                fetch(`${API_URL}?action=get_notifications`)
                    .then(response => response.json())
                    .then(data => {
                        notificationList.innerHTML = '';
                        if (data.notifications.length > 0) {
                            data.notifications.forEach(notif => {
                                const notifItem = document.createElement('a');
                                notifItem.href = notif.link;
                                notifItem.dataset.id = notif.notification_id;
                                notifItem.className = 'block p-3 hover:bg-gray-100 dark:hover:bg-gray-700 border-b dark:border-gray-600';
                                notifItem.innerHTML = `<p class="text-sm">${notif.message}</p><p class="text-xs text-gray-400">${notif.created_at}</p>`;
                                notificationList.appendChild(notifItem);
                            });
                        } else {
                            notificationList.innerHTML = `<p class="p-4 text-center text-gray-500">لا توجد إشعارات جديدة</p>`;
                        }
                        if (data.unread_count > 0) {
                            notificationDot.classList.remove('hidden');
                        } else {
                            notificationDot.classList.add('hidden');
                        }
                    });
            }

            notificationBell.addEventListener('click', () => {
                notificationModal.classList.remove('hidden');
                notificationDot.classList.add('hidden');
                fetch(`${API_URL}?action=mark_notifications_read`, { method: 'POST' });
            });

            notificationClose.addEventListener('click', () => {
                notificationModal.classList.add('hidden');
            });

            notificationBackdrop.addEventListener('click', () => {
                notificationModal.classList.add('hidden');
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    notificationModal.classList.add('hidden');
                }
            });

            fetchNotifications();
            setInterval(fetchNotifications, 60000); // Check for new notifications every minute

            // PROFILE DROPDOWN
            const profileToggle = document.getElementById('profile-toggle');
            const profilePanel = document.getElementById('profile-panel');

            profileToggle.addEventListener('click', () => {
                profilePanel.classList.toggle('hidden');
            });

            document.addEventListener('click', (e) => {
                if (!profilePanel.contains(e.target) && e.target !== profileToggle) {
                    profilePanel.classList.add('hidden');
                }
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    profilePanel.classList.add('hidden');
                }
            });

            // AJAX WIDGETS LOADER
            function loadWidget(widgetId, action) {
                const container = document.getElementById(widgetId);
                const list = document.getElementById(widgetId.replace('-widget', '-list'));
                list.innerHTML = `<div class="flex justify-center items-center h-full"><div class="loader"></div></div>`;
                fetch(`${API_URL}?action=${action}`)
                    .then(response => response.json())
                    .then(data => {
                        list.innerHTML = data.html;
                    }).catch(error => {
                        list.innerHTML = `<p class="text-red-500 text-center">فشل تحميل البيانات</p>`;
                    });
            }
            
            loadWidget('activity-widget', 'get_activity');
            loadWidget('attention-widget', 'get_attention_laptops');
            <?php if ($permissions == 'admin' || $permissions == 'manager'): ?>
            loadWidget('leaderboard-widget', 'get_leaderboard');
            <?php endif; ?>
            
            // Refresh activity widget
            document.getElementById('refresh-activity').addEventListener('click', () => {
                loadWidget('activity-widget', 'get_activity');
            });

            // PERFORMANCE CHART
            const performanceCtx = document.getElementById('performanceChart').getContext('2d');
            const performanceChart = new Chart(performanceCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_labels) ?>,
                    datasets: [
                        {
                            label: 'أجهزة مضافة',
                            data: <?= json_encode($chart_data_added) ?>,
                            backgroundColor: 'rgba(59, 130, 246, 0.5)',
                            borderColor: 'rgba(59, 130, 246, 1)',
                            borderWidth: 2,
                            borderRadius: 5
                        },
                        {
                            label: 'أجهزة مغلقة',
                            data: <?= json_encode($chart_data_closed) ?>,
                            backgroundColor: 'rgba(16, 185, 129, 0.5)',
                            borderColor: 'rgba(16, 185, 129, 1)',
                            borderWidth: 2,
                            borderRadius: 5
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                color: isDarkMode() ? '#E5E7EB' : '#4B5563',
                                font: { family: 'Cairo', size: 12 }
                            }
                        },
                        tooltip: {
                            bodyFont: { family: 'Cairo' },
                            titleFont: { family: 'Cairo' }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: isDarkMode() ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)'
                            },
                            ticks: {
                                color: isDarkMode() ? '#9CA3AF' : '#6B7280',
                                font: { family: 'Cairo' }
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: isDarkMode() ? '#9CA3AF' : '#6B7280',
                                font: { family: 'Cairo' }
                            }
                        }
                    }
                }
            });

            // STATUS DISTRIBUTION CHART
            const statusCtx = document.getElementById('statusDistributionChart').getContext('2d');
            const statusChart = new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['تحت الإصلاح', 'مراجعة', 'مغلقة', 'محولة', 'مكلفة', 'قيد المراجعة'],
                    datasets: [{
                        label: 'توزيع الحالات',
                        data: [<?= $open_laptops ?>, <?= $pending_review ?>, <?= $closed_laptops ?>, 12, 8, 5],
                        backgroundColor: ['#3B82F6', '#10B981', '#F59E0B', '#8B5CF6', '#EF4444', '#6B7280'],
                        borderColor: isDarkMode() ? '#4A5568' : '#FFFFFF',
                        borderWidth: 3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: isDarkMode() ? '#E5E7EB' : '#4B5563',
                                font: { family: 'Cairo', size: 12 }
                            }
                        },
                        tooltip: {
                            bodyFont: { family: 'Cairo' },
                            titleFont: { family: 'Cairo' }
                        }
                    }
                }
            });

            // Auto-refresh widgets every 5 minutes
            setInterval(() => {
                loadWidget('activity-widget', 'get_activity');
                loadWidget('attention-widget', 'get_attention_laptops');
                <?php if ($permissions == 'admin' || $permissions == 'manager'): ?>
                loadWidget('leaderboard-widget', 'get_leaderboard');
                <?php endif; ?>
            }, 300000); // 5 minutes

            // Initialize Feather Icons
            feather.replace();
        });
    </script>
</body>
</html>