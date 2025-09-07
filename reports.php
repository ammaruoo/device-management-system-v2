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
// Only admins and managers can view reports
if (!in_array($_SESSION['permissions'], ['admin', 'manager'])) {
    header("Location: index.php?error=access_denied");
    exit;
}

// =================================================================================
// HANDLE CSV EXPORT REQUEST
// =================================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Get filters from GET request
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $status = $_GET['status'] ?? '';
    $assigned_user_id = $_GET['assigned_user_id'] ?? '';

    // Build query based on filters
    $base_query = "FROM broken_laptops b 
                   LEFT JOIN users u ON b.assigned_user_id = u.user_id 
                   LEFT JOIN operations o ON b.laptop_id = o.laptop_id AND o.operation_id = (SELECT MIN(op.operation_id) FROM operations op WHERE op.laptop_id = b.laptop_id)";
    $where_clauses = ["DATE(o.operation_date) BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];
    if (!empty($status)) { $where_clauses[] = "b.status = ?"; $params[] = $status; }
    if (!empty($assigned_user_id)) { $where_clauses[] = "b.assigned_user_id = ?"; $params[] = $assigned_user_id; }
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
    
    $stmt = $pdo->prepare("SELECT b.laptop_id, b.serial_number, b.specs, b.employee_name, b.status, u.username as assigned_to, o.operation_date as entry_date $base_query $where_sql ORDER BY b.laptop_id DESC");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Output CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($output, ['ID', 'Serial Number', 'Specs', 'Employee', 'Status', 'Assigned To', 'Entry Date']);
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// =================================================================================
// FETCH INITIAL DATA FOR FILTERS
// =================================================================================
$users_query = $pdo->query("SELECT user_id, username FROM users WHERE permissions IN ('technician', 'admin', 'manager') ORDER BY username");
$assignable_users = $users_query->fetchAll();
$statuses_query = $pdo->query("SELECT DISTINCT status FROM broken_laptops WHERE status IS NOT NULL AND status != '' ORDER BY status");
$statuses = $statuses_query->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة معلومات التقارير والأداء</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Daterangepicker -->
    <script type="text/javascript" src="https://cdn.jsdelivr.net/jquery/latest/jquery.min.js"></script>
    <script type="text/javascript" src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        [x-cloak] { display: none !important; }
        .kpi-card { transition: all 0.3s ease; }
        .kpi-card:hover { transform: translateY(-5px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
    </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4 sm:p-6 lg:p-8" x-data="reportsDashboard()">
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">لوحة معلومات التقارير</h1>
            <p class="text-gray-500">تحليل الأداء واتجاهات العمل</p>
        </div>
        <a href="index.php" class="text-sm text-blue-600 hover:underline">العودة للرئيسية</a>
    </div>

    <!-- Filters -->
    <div class="bg-white p-4 rounded-xl shadow-md mb-8">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label for="daterange" class="text-sm font-medium text-gray-700">النطاق الزمني</label>
                <input type="text" id="daterange" class="mt-1 w-full p-2 border border-gray-300 rounded-lg">
            </div>
            <div>
                <label for="status" class="text-sm font-medium text-gray-700">الحالة</label>
                <select x-model="filters.status" id="status" class="mt-1 w-full p-2 border border-gray-300 rounded-lg">
                    <option value="">الكل</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="assigned" class="text-sm font-medium text-gray-700">الفني المسؤول</label>
                <select x-model="filters.assigned_user_id" id="assigned" class="mt-1 w-full p-2 border border-gray-300 rounded-lg">
                    <option value="">الكل</option>
                    <?php foreach ($assignable_users as $user): ?>
                        <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button @click="fetchData" class="w-full h-10 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                    <span>تطبيق الفلتر</span>
                </button>
                <button @click="exportToCsv" class="w-1/3 h-10 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 flex items-center justify-center">
                     <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div x-show="isLoading" class="text-center py-16"><div class="w-10 h-10 border-4 border-blue-500 border-t-transparent rounded-full animate-spin mx-auto"></div></div>
    <div x-show="!isLoading" x-cloak>
        <!-- KPIs -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="kpi-card bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">إجمالي التذاكر</p><p class="text-3xl font-bold text-blue-600" x-text="kpis.total_tickets"></p></div>
            <div class="kpi-card bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">التذاكر المغلقة</p><p class="text-3xl font-bold text-green-600" x-text="kpis.closed_tickets"></p></div>
            <div class="kpi-card bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">نسبة الإنجاز</p><p class="text-3xl font-bold text-indigo-600" x-text="kpis.completion_rate + '%'"></p></div>
            <div class="kpi-card bg-white p-6 rounded-xl shadow-md"><p class="text-gray-500">متوسط وقت الإصلاح (يوم)</p><p class="text-3xl font-bold text-red-600" x-text="kpis.avg_repair_time"></p></div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <div class="bg-white p-6 rounded-xl shadow-md"><h3 class="font-bold mb-4">أداء الفنيين (التذاكر المغلقة)</h3><canvas id="techPerformanceChart"></canvas></div>
            <div class="bg-white p-6 rounded-xl shadow-md"><h3 class="font-bold mb-4">أكثر أنواع المشاكل شيوعاً</h3><canvas id="problemTypesChart"></canvas></div>
        </div>

        <!-- Data Table -->
        <div class="bg-white p-6 rounded-xl shadow-md">
            <h3 class="font-bold mb-4">البيانات التفصيلية</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-right">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="p-3 text-sm font-semibold text-gray-600">الرقم التسلسلي</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">المواصفات</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">الموظف</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">الحالة</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">الفني المسؤول</th>
                            <th class="p-3 text-sm font-semibold text-gray-600">تاريخ الإدخال</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in tableData" :key="row.laptop_id">
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-3 font-semibold" x-text="row.serial_number"></td>
                                <td class="p-3" x-text="row.specs"></td>
                                <td class="p-3" x-text="row.employee_name"></td>
                                <td class="p-3" x-text="row.status"></td>
                                <td class="p-3" x-text="row.assigned_to"></td>
                                <td class="p-3" x-text="new Date(row.entry_date).toISOString().slice(0,19).replace('T',' ')"></td>
                            </tr>
                        </template>
                        <tr x-show="tableData.length === 0">
                            <td colspan="6" class="text-center p-8 text-gray-500">لا توجد بيانات لعرضها حسب الفلاتر المحددة.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('reportsDashboard', () => ({
        isLoading: true,
        filters: {
            start_date: moment().startOf('month').format('YYYY-MM-DD'),
            end_date: moment().format('YYYY-MM-DD'),
            status: '',
            assigned_user_id: ''
        },
        kpis: {},
        tableData: [],
        charts: {
            techPerformance: null,
            problemTypes: null,
        },

        init() {
            // Initialize Daterangepicker
            $('#daterange').daterangepicker({
                startDate: moment().startOf('month'),
                endDate: moment(),
                ranges: {
                   'اليوم': [moment(), moment()],
                   'الأمس': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                   'آخر 7 أيام': [moment().subtract(6, 'days'), moment()],
                   'آخر 30 يوم': [moment().subtract(29, 'days'), moment()],
                   'هذا الشهر': [moment().startOf('month'), moment().endOf('month')],
                   'الشهر الماضي': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
                },
                locale: {
                    format: 'YYYY-MM-DD',
                    separator: ' - ',
                    applyLabel: 'تطبيق',
                    cancelLabel: 'إلغاء',
                    fromLabel: 'من',
                    toLabel: 'إلى',
                    customRangeLabel: 'نطاق مخصص',
                    weekLabel: 'أ',
                    daysOfWeek: ['ح', 'ن', 'ث', 'ر', 'خ', 'ج', 'س'],
                    monthNames: ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
                }
            }, (start, end) => {
                this.filters.start_date = start.format('YYYY-MM-DD');
                this.filters.end_date = end.format('YYYY-MM-DD');
            });

            this.fetchData();
        },

        fetchData() {
            this.isLoading = true;
            const params = new URLSearchParams(this.filters).toString();
            fetch(`api_handler.php?action=get_report_data&${params}`)
                .then(res => res.json())
                .then(data => {
                    this.kpis = data.kpis;
                    this.tableData = data.table_data;
                    this.updateCharts(data.charts);
                    this.isLoading = false;
                });
        },

        updateCharts(chartData) {
            // Tech Performance Chart
            const techCtx = document.getElementById('techPerformanceChart').getContext('2d');
            if(this.charts.techPerformance) this.charts.techPerformance.destroy();
            this.charts.techPerformance = new Chart(techCtx, {
                type: 'bar',
                data: {
                    labels: chartData.tech_performance.map(d => d.username),
                    datasets: [{
                        label: 'التذاكر المغلقة',
                        data: chartData.tech_performance.map(d => d.count),
                        backgroundColor: '#3B82F6',
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });

            // Problem Types Chart
            const problemCtx = document.getElementById('problemTypesChart').getContext('2d');
            if(this.charts.problemTypes) this.charts.problemTypes.destroy();
            this.charts.problemTypes = new Chart(problemCtx, {
                type: 'doughnut',
                data: {
                    labels: chartData.problem_types.map(d => d.problem_type),
                    datasets: [{
                        data: chartData.problem_types.map(d => d.count),
                        backgroundColor: ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6'],
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        },
        
        exportToCsv() {
            const params = new URLSearchParams(this.filters).toString();
            window.location.href = `reports.php?export=csv&${params}`;
        }
    }));
});
</script>

</body>
</html>
