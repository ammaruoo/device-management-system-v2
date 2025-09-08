<?php
/**
 * نظام التقارير المتقدم
 * Advanced Reporting System
 *
 * ميزات:
 * - تقارير تفاعلية
 * - تصدير البيانات
 * - مخططات متقدمة
 * - فلترة متقدمة
 * - تقارير مجدولة
 */

session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

class AdvancedReports
{
    private $pdo;
    private $user_id;
    private $permissions;

    public function __construct($pdo, $user_id, $permissions)
    {
        $this->pdo = $pdo;
        $this->user_id = $user_id;
        $this->permissions = $permissions;
    }

    /**
     * جلب إحصائيات عامة
     */
    public function getGeneralStats($date_from = null, $date_to = null)
    {
        $conditions = [];
        $params = [];

        if ($date_from) {
            $conditions[] = "bl.creation_date >= ?";
            $params[] = $date_from;
        }

        if ($date_to) {
            $conditions[] = "bl.creation_date <= ?";
            $params[] = $date_to;
        }

        $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $query = "
            SELECT
                COUNT(*) as total_devices,
                SUM(CASE WHEN bl.status NOT IN ('locked', 'مغلق') THEN 1 ELSE 0 END) as open_devices,
                SUM(CASE WHEN bl.status IN ('locked', 'مغلق') THEN 1 ELSE 0 END) as closed_devices,
                AVG(CASE WHEN bl.status IN ('locked', 'مغلق')
                    THEN DATEDIFF(bl.lock_date, bl.creation_date)
                    ELSE NULL END) as avg_repair_days,
                COUNT(DISTINCT bl.assigned_user_id) as active_technicians,
                COUNT(DISTINCT bl.branch_name) as active_branches
            FROM broken_laptops bl
            $where_clause
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * إحصائيات الأداء حسب الفني
     */
    public function getTechnicianPerformance($date_from = null, $date_to = null)
    {
        $conditions = ["bl.assigned_user_id IS NOT NULL"];
        $params = [];

        if ($date_from) {
            $conditions[] = "bl.creation_date >= ?";
            $params[] = $date_from;
        }

        if ($date_to) {
            $conditions[] = "bl.creation_date <= ?";
            $params[] = $date_to;
        }

        $where_clause = "WHERE " . implode(" AND ", $conditions);

        $query = "
            SELECT
                u.username,
                u.permissions,
                COUNT(*) as total_assigned,
                SUM(CASE WHEN bl.status IN ('locked', 'مغلق') THEN 1 ELSE 0 END) as completed,
                ROUND(
                    (SUM(CASE WHEN bl.status IN ('locked', 'مغلق') THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1
                ) as completion_rate,
                ROUND(AVG(
                    CASE WHEN bl.status IN ('locked', 'مغلق')
                    THEN DATEDIFF(bl.lock_date, bl.creation_date)
                    ELSE NULL END
                ), 1) as avg_repair_days,
                MAX(bl.creation_date) as last_activity
            FROM users u
            LEFT JOIN broken_laptops bl ON u.user_id = bl.assigned_user_id
            $where_clause
            GROUP BY u.user_id, u.username, u.permissions
            ORDER BY completion_rate DESC, total_assigned DESC
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * إحصائيات حسب الفرع
     */
    public function getBranchStats($date_from = null, $date_to = null)
    {
        $conditions = [];
        $params = [];

        if ($date_from) {
            $conditions[] = "creation_date >= ?";
            $params[] = $date_from;
        }

        if ($date_to) {
            $conditions[] = "creation_date <= ?";
            $params[] = $date_to;
        }

        $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $query = "
            SELECT
                branch_name,
                COUNT(*) as total_devices,
                SUM(CASE WHEN status NOT IN ('locked', 'مغلق') THEN 1 ELSE 0 END) as open_devices,
                SUM(CASE WHEN status IN ('locked', 'مغلق') THEN 1 ELSE 0 END) as closed_devices,
                ROUND(
                    (SUM(CASE WHEN status IN ('locked', 'مغلق') THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1
                ) as completion_rate,
                GROUP_CONCAT(DISTINCT item_number SEPARATOR ', ') as common_issues
            FROM broken_laptops
            $where_clause
            GROUP BY branch_name
            ORDER BY total_devices DESC
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * إحصائيات المشاكل الأكثر شيوعاً
     */
    public function getCommonIssues($date_from = null, $date_to = null, $limit = 10)
    {
        $conditions = [];
        $params = [];

        if ($date_from) {
            $conditions[] = "creation_date >= ?";
            $params[] = $date_from;
        }

        if ($date_to) {
            $conditions[] = "creation_date <= ?";
            $params[] = $date_to;
        }

        $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        $query = "
            SELECT
                problem_type,
                problem_nature,
                COUNT(*) as count,
                ROUND((COUNT(*) / (SELECT COUNT(*) FROM broken_laptops bl2 $where_clause)) * 100, 1) as percentage,
                AVG(CASE WHEN status IN ('locked', 'مغلق') THEN DATEDIFF(lock_date, creation_date) ELSE NULL END) as avg_resolution_days
            FROM broken_laptops
            $where_clause
            GROUP BY problem_type, problem_nature
            HAVING COUNT(*) > 0
            ORDER BY count DESC
            LIMIT ?
        ";

        $stmt = $this->pdo->prepare($query);
        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * بيانات المخطط الزمني
     */
    public function getTimeSeriesData($date_from, $date_to, $interval = 'day')
    {
        $date_format = $interval === 'month' ? '%Y-%m' : '%Y-%m-%d';
        $group_by = $interval === 'month' ? "DATE_FORMAT(creation_date, '%Y-%m')" : "DATE(creation_date)";

        $query = "
            SELECT
                $group_by as period,
                COUNT(*) as devices_added,
                SUM(CASE WHEN status IN ('locked', 'مغلق') THEN 1 ELSE 0 END) as devices_completed,
                ROUND(AVG(
                    CASE WHEN status IN ('locked', 'مغلق')
                    THEN DATEDIFF(lock_date, creation_date)
                    ELSE NULL END
                ), 1) as avg_repair_time
            FROM broken_laptops
            WHERE creation_date BETWEEN ? AND ?
            GROUP BY $group_by
            ORDER BY period
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$date_from, $date_to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * تصدير البيانات
     */
    public function exportData($type, $format = 'csv', $filters = [])
    {
        $data = [];

        switch ($type) {
            case 'devices':
                $query = "SELECT * FROM broken_laptops WHERE 1=1";
                break;
            case 'technicians':
                $query = "SELECT u.username, COUNT(bl.laptop_id) as devices_count FROM users u LEFT JOIN broken_laptops bl ON u.user_id = bl.assigned_user_id GROUP BY u.user_id";
                break;
            case 'branches':
                $query = "SELECT branch_name, COUNT(*) as devices_count FROM broken_laptops GROUP BY branch_name";
                break;
        }

        $stmt = $this->pdo->query($query);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($format === 'csv') {
            return $this->exportToCSV($data, $type);
        } elseif ($format === 'json') {
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        return $data;
    }

    private function exportToCSV($data, $filename)
    {
        if (empty($data)) return '';

        $output = fopen('php://temp', 'r+');

        // كتابة عناوين الأعمدة
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
        }

        // كتابة البيانات
        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}

// تهيئة نظام التقارير
$reports = new AdvancedReports($pdo, $_SESSION['user_id'], $_SESSION['permissions']);

// معالجة الطلبات AJAX
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    $action = $_GET['ajax'];
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;

    switch ($action) {
        case 'general_stats':
            echo json_encode($reports->getGeneralStats($date_from, $date_to));
            break;

        case 'technician_performance':
            echo json_encode($reports->getTechnicianPerformance($date_from, $date_to));
            break;

        case 'branch_stats':
            echo json_encode($reports->getBranchStats($date_from, $date_to));
            break;

        case 'common_issues':
            echo json_encode($reports->getCommonIssues($date_from, $date_to));
            break;

        case 'time_series':
            echo json_encode($reports->getTimeSeriesData($date_from, $date_to));
            break;

        case 'export':
            $type = $_GET['type'] ?? 'devices';
            $format = $_GET['format'] ?? 'csv';
            $data = $reports->exportData($type, $format);

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $type . '_report.csv"');
                echo "\xEF\xBB\xBF"; // BOM for UTF-8
                echo $data;
            } else {
                header('Content-Type: application/json; charset=utf-8');
                echo $data;
            }
            exit;
    }
    exit;
}

// جلب البيانات الأولية
$general_stats = $reports->getGeneralStats();
$technician_stats = $reports->getTechnicianPerformance();
$branch_stats = $reports->getBranchStats();
$common_issues = $reports->getCommonIssues();

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التقارير المتقدمة - نظام الصيانة</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/luxon@3.0.1/build/global/luxon.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Cairo', sans-serif; }
        .animate-fade-in { animation: fadeIn 0.5s ease-in-out; }
        .animate-slide-up { animation: slideUp 0.3s ease-out; }
        .metric-card { transition: all 0.3s ease; }
        .metric-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
        }

        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }

        .export-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: all 0.3s ease;
        }

        .export-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
    </style>
</head>
<body class="bg-gray-50" x-data="reportsApp()">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8 max-w-7xl">

        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 mb-2">التقارير المتقدمة</h1>
                <p class="text-gray-600">تحليل شامل لأداء النظام وإحصائيات مفصلة</p>
            </div>
            <div class="flex items-center gap-4">
                <a href="index.php" class="text-blue-600 hover:text-blue-800 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    العودة للرئيسية
                </a>
            </div>
        </div>

        <!-- فلاتر التاريخ -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8 animate-fade-in">
            <div class="flex flex-col sm:flex-row gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">من تاريخ</label>
                    <input type="date" x-model="dateFrom" @change="updateReports()"
                           class="p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">إلى تاريخ</label>
                    <input type="date" x-model="dateTo" @change="updateReports()"
                           class="p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <button @click="resetFilters()" class="px-6 py-3 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    إعادة تعيين
                </button>
                <div class="flex gap-2">
                    <button @click="exportReport('devices', 'csv')" class="export-btn px-4 py-3 text-white rounded-lg flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        تصدير CSV
                    </button>
                    <button @click="exportReport('devices', 'json')" class="export-btn px-4 py-3 text-white rounded-lg flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
                        </svg>
                        تصدير JSON
                    </button>
                </div>
            </div>
        </div>

        <!-- الإحصائيات العامة -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="metric-card bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm">إجمالي الأجهزة</p>
                        <p class="text-3xl font-bold text-blue-600" x-text="generalStats.total_devices || 0"></p>
                    </div>
                    <div class="p-3 bg-blue-100 rounded-full">
                        <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="metric-card bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm">أجهزة مفتوحة</p>
                        <p class="text-3xl font-bold text-yellow-600" x-text="generalStats.open_devices || 0"></p>
                    </div>
                    <div class="p-3 bg-yellow-100 rounded-full">
                        <svg class="w-8 h-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="metric-card bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm">أجهزة مغلقة</p>
                        <p class="text-3xl font-bold text-green-600" x-text="generalStats.closed_devices || 0"></p>
                    </div>
                    <div class="p-3 bg-green-100 rounded-full">
                        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="metric-card bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm">متوسط وقت الإصلاح</p>
                        <p class="text-3xl font-bold text-purple-600" x-text="(generalStats.avg_repair_days || 0) + ' يوم'"></p>
                    </div>
                    <div class="p-3 bg-purple-100 rounded-full">
                        <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- المخططات -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">

            <!-- مخطط الأداء الزمني -->
            <div class="bg-white rounded-xl shadow-lg p-6 animate-slide-up">
                <h3 class="text-xl font-bold text-gray-800 mb-4">أداء النظام عبر الزمن</h3>
                <div class="chart-container">
                    <canvas id="timeSeriesChart"></canvas>
                </div>
            </div>

            <!-- مخطط توزيع المشاكل -->
            <div class="bg-white rounded-xl shadow-lg p-6 animate-slide-up">
                <h3 class="text-xl font-bold text-gray-800 mb-4">أكثر المشاكل شيوعاً</h3>
                <div class="chart-container">
                    <canvas id="issuesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- جدول أداء الفنيين -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8 animate-slide-up">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-800">أداء الفنيين</h3>
                <div class="flex gap-2">
                    <button @click="sortTechnicians('completion_rate')" class="px-4 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200">
                        ترتيب حسب المعدل
                    </button>
                    <button @click="sortTechnicians('total_assigned')" class="px-4 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200">
                        ترتيب حسب العدد
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full table-auto">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right text-sm font-semibold text-gray-600">الفني</th>
                            <th class="px-4 py-3 text-right text-sm font-semibold text-gray-600">المجموع</th>
                            <th class="px-4 py-3 text-right text-sm font-semibold text-gray-600">المكتمل</th>
                            <th class="px-4 py-3 text-right text-sm font-semibold text-gray-600">معدل الإنجاز</th>
                            <th class="px-4 py-3 text-right text-sm font-semibold text-gray-600">متوسط الوقت</th>
                            <th class="px-4 py-3 text-right text-sm font-semibold text-gray-600">آخر نشاط</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <template x-for="tech in technicianStats" :key="tech.username">
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm text-gray-900" x-text="tech.username"></td>
                                <td class="px-4 py-3 text-sm text-gray-900" x-text="tech.total_assigned"></td>
                                <td class="px-4 py-3 text-sm text-gray-900" x-text="tech.completed"></td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="px-2 py-1 rounded-full text-xs font-semibold"
                                          :class="tech.completion_rate >= 80 ? 'bg-green-100 text-green-800' : tech.completion_rate >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'"
                                          x-text="tech.completion_rate + '%'"></span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900" x-text="tech.avg_repair_days + ' يوم'"></td>
                                <td class="px-4 py-3 text-sm text-gray-500" x-text="formatDate(tech.last_activity)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- إحصائيات الفروع -->
        <div class="bg-white rounded-xl shadow-lg p-6 animate-slide-up">
            <h3 class="text-xl font-bold text-gray-800 mb-6">إحصائيات الفروع</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <template x-for="branch in branchStats" :key="branch.branch_name">
                    <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <h4 class="font-semibold text-lg text-gray-800 mb-3" x-text="branch.branch_name || 'غير محدد'"></h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">إجمالي الأجهزة:</span>
                                <span class="font-semibold" x-text="branch.total_devices"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">مفتوحة:</span>
                                <span class="text-yellow-600" x-text="branch.open_devices"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">مغلقة:</span>
                                <span class="text-green-600" x-text="branch.closed_devices"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">معدل الإنجاز:</span>
                                <span class="font-semibold" x-text="branch.completion_rate + '%'"></span>
                            </div>
                        </div>
                        <div class="mt-3 pt-3 border-t">
                            <div class="text-xs text-gray-500">
                                <span>المشاكل الشائعة: </span>
                                <span x-text="branch.common_issues ? branch.common_issues.substring(0, 50) + '...' : 'غير محدد'"></span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('reportsApp', () => ({
                dateFrom: '',
                dateTo: '',
                generalStats: <?php echo json_encode($general_stats); ?>,
                technicianStats: <?php echo json_encode($technician_stats); ?>,
                branchStats: <?php echo json_encode($branch_stats); ?>,
                commonIssues: <?php echo json_encode($common_issues); ?>,
                timeSeriesData: [],
                timeSeriesChart: null,
                issuesChart: null,

                init() {
                    this.setDefaultDates();
                    this.initCharts();
                    this.loadTimeSeriesData();
                },

                setDefaultDates() {
                    const now = new Date();
                    const thirtyDaysAgo = new Date(now.getTime() - (30 * 24 * 60 * 60 * 1000));
                    this.dateFrom = thirtyDaysAgo.toISOString().split('T')[0];
                    this.dateTo = now.toISOString().split('T')[0];
                },

                updateReports() {
                    this.loadGeneralStats();
                    this.loadTechnicianStats();
                    this.loadBranchStats();
                    this.loadCommonIssues();
                    this.loadTimeSeriesData();
                },

                resetFilters() {
                    this.setDefaultDates();
                    this.updateReports();
                },

                async loadGeneralStats() {
                    const response = await fetch(`advanced_reports.php?ajax=general_stats&date_from=${this.dateFrom}&date_to=${this.dateTo}`);
                    this.generalStats = await response.json();
                },

                async loadTechnicianStats() {
                    const response = await fetch(`advanced_reports.php?ajax=technician_performance&date_from=${this.dateFrom}&date_to=${this.dateTo}`);
                    this.technicianStats = await response.json();
                },

                async loadBranchStats() {
                    const response = await fetch(`advanced_reports.php?ajax=branch_stats&date_from=${this.dateFrom}&date_to=${this.dateTo}`);
                    this.branchStats = await response.json();
                },

                async loadCommonIssues() {
                    const response = await fetch(`advanced_reports.php?ajax=common_issues&date_from=${this.dateFrom}&date_to=${this.dateTo}`);
                    this.commonIssues = await response.json();
                    this.updateIssuesChart();
                },

                async loadTimeSeriesData() {
                    const response = await fetch(`advanced_reports.php?ajax=time_series&date_from=${this.dateFrom}&date_to=${this.dateTo}`);
                    this.timeSeriesData = await response.json();
                    this.updateTimeSeriesChart();
                },

                initCharts() {
                    // مخطط الأداء الزمني
                    const timeCtx = document.getElementById('timeSeriesChart').getContext('2d');
                    this.timeSeriesChart = new Chart(timeCtx, {
                        type: 'line',
                        data: {
                            labels: [],
                            datasets: [{
                                label: 'أجهزة مضافة',
                                data: [],
                                borderColor: '#3B82F6',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                tension: 0.4
                            }, {
                                label: 'أجهزة مكتملة',
                                data: [],
                                borderColor: '#10B981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'top' },
                                tooltip: { mode: 'index', intersect: false }
                            },
                            scales: {
                                y: { beginAtZero: true },
                                x: { display: true }
                            }
                        }
                    });

                    // مخطط المشاكل
                    const issuesCtx = document.getElementById('issuesChart').getContext('2d');
                    this.issuesChart = new Chart(issuesCtx, {
                        type: 'doughnut',
                        data: {
                            labels: [],
                            datasets: [{
                                data: [],
                                backgroundColor: [
                                    '#3B82F6', '#10B981', '#F59E0B', '#EF4444',
                                    '#8B5CF6', '#06B6D4', '#84CC16', '#F97316'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom' }
                            }
                        }
                    });
                },

                updateTimeSeriesChart() {
                    if (this.timeSeriesChart && this.timeSeriesData.length > 0) {
                        this.timeSeriesChart.data.labels = this.timeSeriesData.map(item => item.period);
                        this.timeSeriesChart.data.datasets[0].data = this.timeSeriesData.map(item => item.devices_added);
                        this.timeSeriesChart.data.datasets[1].data = this.timeSeriesData.map(item => item.devices_completed);
                        this.timeSeriesChart.update();
                    }
                },

                updateIssuesChart() {
                    if (this.issuesChart && this.commonIssues.length > 0) {
                        this.issuesChart.data.labels = this.commonIssues.map(item => item.problem_type || 'غير محدد');
                        this.issuesChart.data.datasets[0].data = this.commonIssues.map(item => item.count);
                        this.issuesChart.update();
                    }
                },

                sortTechnicians(field) {
                    this.technicianStats.sort((a, b) => b[field] - a[field]);
                },

                formatDate(dateString) {
                    if (!dateString) return 'غير محدد';
                    const date = new Date(dateString);
                    return date.toLocaleDateString('ar-SA', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    });
                },

                async exportReport(type, format) {
                    try {
                        const response = await fetch(`advanced_reports.php?ajax=export&type=${type}&format=${format}&date_from=${this.dateFrom}&date_to=${this.dateTo}`);

                        if (format === 'csv') {
                            const blob = await response.blob();
                            const url = window.URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = `${type}_report.csv`;
                            document.body.appendChild(a);
                            a.click();
                            window.URL.revokeObjectURL(url);
                            document.body.removeChild(a);
                        } else {
                            const data = await response.json();
                            console.log('JSON Data:', data);
                            // يمكن إضافة منطق حفظ JSON هنا
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'تم التصدير بنجاح',
                            text: `تم تصدير تقرير ${type} بصيغة ${format.toUpperCase()}`
                        });
                    } catch (error) {
                        console.error('Export error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'خطأ في التصدير',
                            text: 'حدث خطأ أثناء تصدير البيانات'
                        });
                    }
                }
            }));
        });
    </script>

</body>
</html>