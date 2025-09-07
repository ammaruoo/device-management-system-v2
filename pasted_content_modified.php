<?php
// تفعيل عرض الأخطاء للأغراض التطويرية
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// بدء الجلسة
session_start();
require 'db.php';
require_once 'dompdf/autoload.inc.php';
require_once 'generate_pdf.php'; // تضمين ملف توليد PDF

// التحقق من الصلاحيات
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_permissions = $_SESSION['permissions'];
if (!in_array($user_permissions, ['admin', 'manager', 'technician'])) {
    header("Location: index.php?error=access_denied");
    exit;
}

// معالجة البحث عن رقم التحويل
$transfer_ref = '';
$devices = [];
$transfer_ref_exists = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_ref'])) {
    $transfer_ref = trim($_POST['transfer_ref']);
    
    if (!empty($transfer_ref)) {
        // التحقق من وجود رقم التحويل في قاعدة البيانات
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM broken_laptops WHERE transfer_ref = ?");
        $check_stmt->execute([$transfer_ref]);
        $transfer_ref_exists = $check_stmt->fetchColumn() > 0;
        
        if ($transfer_ref_exists) {
            // جلب الأجهزة المرتبطة برقم التحويل
            $stmt = $pdo->prepare("
                SELECT bl.*,  
                       u.username as assigned_technician,
                       (SELECT COUNT(*) FROM complaints WHERE laptop_id = bl.laptop_id) as problem_count 
                FROM broken_laptops bl 
                LEFT JOIN users u ON bl.assigned_user_id = u.user_id 
                WHERE bl.transfer_ref = ? 
                ORDER BY bl.laptop_id DESC
            ");
            $stmt->execute([$transfer_ref]);
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // جلب المشاكل لكل جهاز
            foreach ($devices as &$device) {
                $problem_stmt = $pdo->prepare("
                    SELECT * FROM complaints  
                    WHERE laptop_id = ?  
                    ORDER BY laptop_id DESC
                ");
                $problem_stmt->execute([$device['laptop_id']]);
                $device['problems'] = $problem_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($device);
        }
    }
}

// معالجة طلب طباعة PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['print_pdf']) && isset($_POST['transfer_ref_to_print'])) {
    $transfer_ref_to_print = $_POST['transfer_ref_to_print'];

    // جلب الأجهزة المرتبطة برقم التحويل مرة أخرى للطباعة
    $stmt = $pdo->prepare("
        SELECT bl.*,  
               u.username as assigned_technician,
               (SELECT COUNT(*) FROM complaints WHERE laptop_id = bl.laptop_id) as problem_count 
        FROM broken_laptops bl 
        LEFT JOIN users u ON bl.assigned_user_id = u.user_id 
        WHERE bl.transfer_ref = ? 
        ORDER BY bl.laptop_id DESC
    ");
    $stmt->execute([$transfer_ref_to_print]);
    $devices_to_print = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($devices_to_print as $device_to_print) {
        $problem_stmt = $pdo->prepare("
            SELECT * FROM complaints  
            WHERE laptop_id = ?  
            ORDER BY laptop_id DESC
        ");
        $problem_stmt->execute([$device_to_print['laptop_id']]);
        $problems_to_print = $problem_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // استدعاء دالة توليد PDF لكل تذكرة
        generateTicketPdf($device_to_print, $problems_to_print, $transfer_ref_to_print);
    }
    exit; // هام: إيقاف التنفيذ بعد توليد PDF
}

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقارير أرقام التحويل - تفاصيل كاملة</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background-color: #f5f7fa; }
        .header-gradient { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); }
        .card-shadow { box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.08); }
        .print-section { display: none; }
        @media print {
            body * { visibility: hidden; }
            .print-section, .print-section * { visibility: visible; }
            .print-section { display: block; position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none !important; }
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .status-entered { background-color: #FEF3C7; color: #92400E; }
        .status-in_progress { background-color: #DBEAFE; color: #1E40AF; }
        .status-fixed { background-color: #D1FAE5; color: #065F46; }
        .status-closed { background-color: #E5E7EB; color: #374151; }
        .problem-resolved { background-color: #D1FAE5; color: #065F46; }
        .problem-pending { background-color: #FEF3C7; color: #92400E; }
        .problem-critical { background-color: #FEE2E2; color: #DC2626; }
        .device-card { transition: all 0.3s ease; }
        .device-card:hover { transform: translateY(-5px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
        .problem-item { border-left: 4px solid #E5E7EB; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="header-gradient text-white">
            <div class="container mx-auto px-4 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold">نظام إدارة تذاكر الصيانة</h1>
                        <p class="mt-1">تقارير أرقام التحويل - تفاصيل كاملة</p>
                    </div>
                    <div>
                        <a href="index.php" class="bg-white text-indigo-700 px-4 py-2 rounded-lg font-semibold hover:bg-gray-100 no-print">
                            <i class="fas fa-home ml-2"></i> الرئيسية
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="container mx-auto px-4 py-8">
            <!-- Search Card -->
            <div class="bg-white rounded-xl card-shadow p-6 mb-8 no-print">
                <h2 class="text-xl font-bold text-gray-800 mb-6">البحث عن أرقام التحويل</h2>
                
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="md:col-span-2">
                        <label for="transfer_ref" class="block text-sm font-medium text-gray-700 mb-2">رقم التحويل</label>
                        <input type="text" id="transfer_ref" name="transfer_ref" value="<?= htmlspecialchars($transfer_ref) ?>"
                               class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                               placeholder="أدخل رقم التحويل للبحث" required>
                    </div>
                    
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-indigo-600 text-white py-3 px-4 rounded-lg hover:bg-indigo-700 flex items-center justify-center">
                            <i class="fas fa-search ml-2"></i> بحث
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <?php if (empty($transfer_ref)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
                        <strong class="font-bold">خطأ!</strong>
                        <span class="block sm:inline">يرجى إدخال رقم التحويل.</span>
                    </div>
                <?php elseif (!$transfer_ref_exists): ?>
                    <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-6" role="alert">
                        <strong class="font-bold">تنبيه!</strong>
                        <span class="block sm:inline">لم يتم العثور على أي أجهزة برقم التحويل "<?= htmlspecialchars($transfer_ref) ?>".</span>
                    </div>
                <?php else: ?>
                    <!-- Results Section -->
                    <div id="resultsSection" class="mb-8 no-print">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-xl font-bold text-gray-800">نتائج البحث لرقم التحويل: <?= htmlspecialchars($transfer_ref) ?></h2>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="print_pdf" value="1">
                                <input type="hidden" name="transfer_ref_to_print" value="<?= htmlspecialchars($transfer_ref) ?>">
                                <button type="submit" id="printBtn" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 flex items-center">
                                    <i class="fas fa-print ml-2"></i> طباعة التقرير
                                </button>
                            </form>
                        </div>
                        
                        <div id="devicesContainer" class="grid grid-cols-1 gap-6">
                            <?php foreach ($devices as $device): ?>
                                <?php
                                // تحديد نص الحالة وفئة التنسيق
                                $statusClass = 'status-entered';
                                $statusText = 'تم الإدخال';
                                
                                if ($device['status'] === 'in_progress') {
                                    $statusClass = 'status-in_progress';
                                    $statusText = 'قيد المعالجة';
                                } elseif ($device['status'] === 'fixed') {
                                    $statusClass = 'status-fixed';
                                    $statusText = 'تم الإصلاح';
                                } elseif ($device['status'] === 'closed') {
                                    $statusClass = 'status-closed';
                                    $statusText = 'مغلق';
                                }
                                ?>
                                
                                <div class="device-card bg-white rounded-xl card-shadow p-6">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h3 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($device['item_number']) ?></h3>
                                            <p class="text-gray-600"><?= htmlspecialchars($device['specs']) ?></p>
                                        </div>
                                        <span class="<?= $statusClass ?> status-badge"><?= $statusText ?></span>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <p class="text-sm text-gray-600">رقم التذكرة: <span class="font-medium"><?= htmlspecialchars($device['ticket_number'] ?? 'N/A') ?></span></p>
                                            <p class="text-sm text-gray-600">الرقم التسلسلي: <span class="font-medium"><?= htmlspecialchars($device['serial_number'] ?? 'N/A') ?></span></p>
                                            <p class="text-sm text-gray-600">الموظف: <span class="font-medium"><?= htmlspecialchars($device['employee_name']) ?></span></p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600">الفرع: <span class="font-medium"><?= htmlspecialchars($device['branch_name'] ?? 'N/A') ?></span></p>
                                            <p class="text-sm text-gray-600">نوع المشكلة: <span class="font-medium"><?= htmlspecialchars($device['problem_type'] ?? 'N/A') ?></span></p>
                                            <p class="text-sm text-gray-600">طبيعة المشكلة: <span class="font-medium"><?= htmlspecialchars($device['problem_nature'] ?? 'N/A') ?></span></p>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <p class="text-sm text-gray-600">مع الشاحن: <span class="font-medium"><?= $device['with_charger'] ? 'نعم' : 'لا' ?></span></p>
                                        <?php if (!empty($device['assigned_technician'])): ?>
                                            <p class="text-sm text-gray-600">الفني المسؤول: <span class="font-medium"><?= htmlspecialchars($device['assigned_technician']) ?></span></p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div>
                                        <h4 class="font-semibold text-gray-800 mb-3">المشاكل المبلغ عنها (<?= $device['problem_count'] ?>):</h4>
                                        
                                        <?php if (!empty($device['problems'])): ?>
                                            <?php foreach ($device['problems'] as $problem): ?>
                                                <?php
                                                $problemStatusClass = 'problem-pending';
                                                $problemStatusText = 'قيد المعالجة';
                                                
                                                // يمكنك إضافة منطق لتحديد حالة المشكلة بناءً على البيانات
                                                if (stripos($problem['repair_result'] ?? '', 'تم الإصلاح') !== false) {
                                                    $problemStatusClass = 'problem-resolved';
                                                    $problemStatusText = 'تم الإصلاح';
                                                }
                                                ?>
                                                
                                                <div class="problem-item bg-gray-50 p-4 rounded-lg mb-3">
                                                    <div class="flex justify-between items-start">
                                                        <div>
                                                            <h4 class="font-semibold text-gray-800"><?= htmlspecialchars($problem['problem_title']) ?></h4>
                                                            <p class="text-gray-600 mt-1"><?= htmlspecialchars($problem['problem_details']) ?></p>
                                                            <?php if (!empty($problem['repair_result'])): ?>
                                                                <p class="text-sm text-green-600 mt-2"><i class="fas fa-wrench ml-1"></i> نتيجة الإصلاح: <?= htmlspecialchars($problem['repair_result']) ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                        <span class="<?= $problemStatusClass ?> status-badge"><?= $problemStatusText ?></span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-gray-500">لا توجد مشاكل مسجلة لهذا الجهاز.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Print Section (مخفي للعرض العادي) -->
                    <div id="printSection" class="print-section bg-white p-8">
                        <div class="text-center mb-6">
                            <h1 class="text-2xl font-bold">تقرير أرقام التحويل</h1>
                            <p class="text-gray-600">تاريخ التقرير: <?= date('Y-m-d H:i') ?></p>
                            <p class="text-gray-600">رقم التحويل: <?= htmlspecialchars($transfer_ref) ?></p>
                        </div>
                        
                        <div id="printDevicesContainer">
                            <?php 
                            $fixedCount = 0;
                            $inProgressCount = 0;
                            
                            foreach ($devices as $device): 
                                if ($device['status'] === 'fixed') $fixedCount++;
                                if ($device['status'] === 'in_progress') $inProgressCount++;
                                
                                // تحديد نص الحالة
                                $statusClass = 'status-entered';
                                $statusText = 'تم الإدخال';
                                if ($device['status'] === 'in_progress') {
                                    $statusClass = 'status-in_progress';
                                    $statusText = 'قيد المعالجة';
                                } elseif ($device['status'] === 'fixed') {
                                    $statusClass = 'status-fixed';
                                    $statusText = 'تم الإصلاح';
                                } elseif ($device['status'] === 'closed') {
                                    $statusClass = 'status-closed';
                                    $statusText = 'مغلق';
                                }
                                ?>
                                <div class="device-card bg-white rounded-xl card-shadow p-6 mb-4">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h3 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($device['item_number']) ?></h3>
                                            <p class="text-gray-600"><?= htmlspecialchars($device['specs']) ?></p>
                                        </div>
                                        <span class="<?= $statusClass ?> status-badge"><?= $statusText ?></span>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div>
                                            <p class="text-sm text-gray-600">رقم التذكرة: <span class="font-medium"><?= htmlspecialchars($device['ticket_number'] ?? 'N/A') ?></span></p>
                                            <p class="text-sm text-gray-600">الرقم التسلسلي: <span class="font-medium"><?= htmlspecialchars($device['serial_number'] ?? 'N/A') ?></span></p>
                                            <p class="text-sm text-gray-600">الموظف: <span class="font-medium"><?= htmlspecialchars($device['employee_name']) ?></span></p>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600">الفرع: <span class="font-medium"><?= htmlspecialchars($device['branch_name'] ?? 'N/A') ?></span></p>
                                            <p class="text-sm text-gray-600">نوع المشكلة: <span class="font-medium"><?= htmlspecialchars($device['problem_type'] ?? 'N/A') ?></span></p>
                                            <p class="text-sm text-gray-600">طبيعة المشكلة: <span class="font-medium"><?= htmlspecialchars($device['problem_nature'] ?? 'N/A') ?></span></p>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <p class="text-sm text-gray-600">مع الشاحن: <span class="font-medium"><?= $device['with_charger'] ? 'نعم' : 'لا' ?></span></p>
                                        <?php if (!empty($device['assigned_technician'])): ?>
                                            <p class="text-sm text-gray-600">الفني المسؤول: <span class="font-medium"><?= htmlspecialchars($device['assigned_technician']) ?></span></p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div>
                                        <h4 class="font-semibold text-gray-800 mb-3">المشاكل المبلغ عنها (<?= $device['problem_count'] ?>):</h4>
                                        
                                        <?php if (!empty($device['problems'])): ?>
                                            <?php foreach ($device['problems'] as $problem): ?>
                                                <?php
                                                $problemStatusClass = 'problem-pending';
                                                $problemStatusText = 'قيد المعالجة';
                                                
                                                // يمكنك إضافة منطق لتحديد حالة المشكلة بناءً على البيانات
                                                if (stripos($problem['repair_result'] ?? '', 'تم الإصلاح') !== false) {
                                                    $problemStatusClass = 'problem-resolved';
                                                    $problemStatusText = 'تم الإصلاح';
                                                }
                                                ?>
                                                
                                                <div class="problem-item bg-gray-50 p-4 rounded-lg mb-3">
                                                    <div class="flex justify-between items-start">
                                                        <div>
                                                            <h4 class="font-semibold text-gray-800"><?= htmlspecialchars($problem['problem_title']) ?></h4>
                                                            <p class="text-gray-600 mt-1"><?= htmlspecialchars($problem['problem_details']) ?></p>
                                                            <?php if (!empty($problem['repair_result'])): ?>
                                                                <p class="text-sm text-green-600 mt-2"><i class="fas fa-wrench ml-1"></i> نتيجة الإصلاح: <?= htmlspecialchars($problem['repair_result']) ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                        <span class="<?= $problemStatusClass ?> status-badge"><?= $problemStatusText ?></span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-gray-500">لا توجد مشاكل مسجلة لهذا الجهاز.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="text-center mt-6">
                            <p class="text-gray-700">عدد الأجهزة التي تم إصلاحها: <?= $fixedCount ?></p>
                            <p class="text-gray-700">عدد الأجهزة قيد المعالجة: <?= $inProgressCount ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>

        <!-- Footer -->
        <footer class="bg-gray-800 text-white text-center p-4 no-print">
            <p>&copy; <?= date('Y') ?> نظام إدارة تذاكر الصيانة. جميع الحقوق محفوظة.</p>
        </footer>
    </div>
</body>
</html>
