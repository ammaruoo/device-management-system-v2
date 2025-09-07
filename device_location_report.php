<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['permissions'], ['admin', 'manager'])) {
    header("Location: index.php?error=access_denied");
    exit;
}

// Fetch data for filters
$branches_query = $pdo->query("SELECT DISTINCT branch_name FROM users WHERE branch_name IS NOT NULL AND branch_name != '' ORDER BY branch_name ASC");
$branches = $branches_query->fetchAll(PDO::FETCH_COLUMN);
$users_query = $pdo->query("SELECT user_id, username FROM users ORDER BY username ASC");
$all_users = $users_query->fetchAll(PDO::FETCH_ASSOC);

function getStatusDetails($status) {
    $status_map = [
        'entered' => ['name' => 'تم الإدخال', 'bg' => 'bg-gray-100', 'text' => 'text-gray-800'],
        'assigned' => ['name' => 'مُعيّن', 'bg' => 'bg-blue-100', 'text' => 'text-blue-800'],
        'في انتظار قطع' => ['name' => 'في انتظار قطع', 'bg' => 'bg-yellow-100', 'text' => 'text-yellow-800'],
        'جاهز للبيع' => ['name' => 'جاهز للبيع', 'bg' => 'bg-green-100', 'text' => 'text-green-800'],
        'لم يتم الإصلاح' => ['name' => 'لم يتم الإصلاح', 'bg' => 'bg-red-100', 'text' => 'text-red-800'],
    ];
    return $status_map[$status] ?? ['name' => ucfirst($status), 'bg' => 'bg-gray-100', 'text' => 'text-gray-800'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير تتبع مواقع الأجهزة</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style> body { font-family: 'Cairo', sans-serif; } [x-cloak] { display: none !important; } </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4 sm:p-6 lg:p-8" x-data="locationReport()">
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">تقرير تتبع مواقع الأجهزة</h1>
            <p class="text-gray-500">معرفة الموظف المسؤول عن كل جهاز حالياً والمدة التي قضاها معه.</p>
        </div>
        <a href="index.php" class="text-sm text-blue-600 hover:underline">العودة للرئيسية</a>
    </div>

    <!-- Filters -->
    <div class="bg-white p-4 rounded-xl shadow-md mb-8">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
                <label for="branch" class="text-sm font-medium text-gray-700">الفرع</label>
                <select x-model="filters.branch" @change="fetchReportData" id="branch" class="mt-1 w-full p-2 border border-gray-300 rounded-lg">
                    <option value="">كل الفروع</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= htmlspecialchars($branch) ?>"><?= htmlspecialchars($branch) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="user" class="text-sm font-medium text-gray-700">الموظف الحالي</label>
                <select x-model="filters.user_id" @change="fetchReportData" id="user" class="mt-1 w-full p-2 border border-gray-300 rounded-lg">
                    <option value="">كل الموظفين</option>
                     <?php foreach ($all_users as $user): ?>
                        <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end">
                <button @click="exportToCsv" class="w-full h-10 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700">تصدير إلى Excel</button>
            </div>
        </div>
    </div>

    <!-- KPIs Section -->
    <div x-show="!isLoading && reportData.length > 0" x-cloak class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white p-5 rounded-xl shadow-md flex items-center gap-4">
            <div class="bg-blue-100 p-3 rounded-full"><svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg></div>
            <div><p class="text-gray-500">إجمالي الأجهزة</p><p class="text-2xl font-bold" x-text="stats.total"></p></div>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-md flex items-center gap-4">
            <div class="bg-indigo-100 p-3 rounded-full"><svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg></div>
            <div><p class="text-gray-500">عدد الفروع</p><p class="text-2xl font-bold" x-text="stats.branches"></p></div>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-md flex items-center gap-4">
            <div class="bg-teal-100 p-3 rounded-full"><svg class="w-6 h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg></div>
            <div><p class="text-gray-500">الأكثر استحواذاً</p><p class="text-2xl font-bold" x-text="stats.topHolder"></p></div>
        </div>
    </div>

    <!-- Report Table -->
    <div class="bg-white p-6 rounded-xl shadow-md">
        <div x-show="isLoading" class="text-center py-10">جاري تحميل التقرير...</div>
        <div x-show="!isLoading" x-cloak class="overflow-x-auto">
            <table class="w-full text-right">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-3">رقم التذكرة</th>
                        <th class="p-3">الموقع الحالي (الموظف)</th>
                        <th class="p-3">المدة مع الحالي</th>
                        <th class="p-3">المدة مع السابق</th>
                        <th class="p-3">تاريخ آخر تحديث</th>
                        <th class="p-3">الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="device in reportData" :key="device.laptop_id">
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3 font-mono"><a :href="`device_report.php?laptop_id=${device.laptop_id}`" class="text-blue-600 hover:underline" x-text="device.laptop_id"></a></td>
                            <td class="p-3 font-semibold" x-text="device.current_holder"></td>
                            <td class="p-3 font-semibold text-blue-700" x-text="device.time_with_current"></td>
                            <td class="p-3 text-gray-600" x-text="device.time_with_previous"></td>
                            <td class="p-3" x-text="new Date(device.last_event_date).toLocaleDateString('ar-EG')"></td>
                            <td class="p-3">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full" 
                                      :class="getStatusDetails(device.status).bg + ' ' + getStatusDetails(device.status).text"
                                      x-text="getStatusDetails(device.status).name"></span>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="reportData.length === 0">
                        <td colspan="6" class="text-center p-8 text-gray-500">لا توجد بيانات لعرضها.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('locationReport', () => ({
        isLoading: true,
        reportData: [],
        filters: { branch: '', user_id: '' },
        stats: { total: 0, branches: 0, topHolder: 'N/A' },
        api_url: 'api_handler.php',

        init() {
            this.fetchReportData();
        },

        fetchReportData() {
            this.isLoading = true;
            const params = new URLSearchParams(this.filters).toString();
            fetch(`${this.api_url}?action=get_device_locations&${params}`)
                .then(res => res.json())
                .then(data => {
                    this.reportData = data;
                    this.calculateStats();
                    this.isLoading = false;
                });
        },
        
        calculateStats() {
            this.stats.total = this.reportData.length;
            
            const branches = new Set(this.reportData.map(d => d.branch_name).filter(b => b));
            this.stats.branches = branches.size;

            const holderCounts = this.reportData.reduce((acc, device) => {
                if(device.current_holder) {
                    acc[device.current_holder] = (acc[device.current_holder] || 0) + 1;
                }
                return acc;
            }, {});
            
            let topHolder = 'N/A';
            let maxCount = 0;
            for (const holder in holderCounts) {
                if (holderCounts[holder] > maxCount) {
                    maxCount = holderCounts[holder];
                    topHolder = holder;
                }
            }
            this.stats.topHolder = topHolder;
        },

        getStatusDetails(status) {
            const statusMap = {
                entered: { name: 'تم الإدخال', bg: 'bg-gray-100', text: 'text-gray-800' },
                assigned: { name: 'مُعيّن', bg: 'bg-blue-100', text: 'text-blue-800' },
                'في انتظار قطع': { name: 'في انتظار قطع', bg: 'bg-yellow-100', text: 'text-yellow-800' },
                'جاهز للبيع': { name: 'جاهز للبيع', bg: 'bg-green-100', text: 'text-green-800' },
                'لم يتم الإصلاح': { name: 'لم يتم الإصلاح', bg: 'bg-red-100', text: 'text-red-800' },
            };
            return statusMap[status] || { name: status, bg: 'bg-gray-100', text: 'text-gray-800' };
        },

        exportToCsv() {
            let csvContent = "data:text/csv;charset=utf-8,\uFEFF"; // UTF-8 BOM
            csvContent += ["رقم التذكرة", "الموقع الحالي", "المدة مع الحالي", "المدة مع السابق", "تاريخ آخر تحديث", "الحالة"].join(",") + "\r\n";
            this.reportData.forEach(row => {
                let csvRow = [
                    `"${row.laptop_id}"`,
                    `"${row.current_holder}"`,
                    `"${row.time_with_current}"`,
                    `"${row.time_with_previous}"`,
                    `"${new Date(row.last_event_date).toLocaleDateString('en-CA')}"`,
                    `"${this.getStatusDetails(row.status).name}"`
                ].join(",");
                csvContent += csvRow + "\r\n";
            });
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "device_location_report.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }));
});
</script>

</body>
</html>
