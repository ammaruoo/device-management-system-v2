<?php
// تفعيل عرض الأخطاء للأغراض التطويرية
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// بدء الجلسة

session_start();
require 'db.php';

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
        }
    }
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
                            <button id="printBtn" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 flex items-center">
                                <i class="fas fa-print ml-2"></i> طباعة التقرير
                            </button>
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
                                                                <p class="text-sm text-green-600 mt-2">
                                                                    <i class="fas fa-wrench ml-1"></i> نتيجة الإصلاح: <?= htmlspecialchars($problem['repair_result']) ?>
                                                                </p>
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
                                $statusText = 'تم الإدخال';
                                if ($device['status'] === 'in_progress') {
                                    $statusText = 'قيد المعالجة';
                                } elseif ($device['status'] === 'fixed') {
                                    $statusText = 'تم الإصلاح';
                                } elseif ($device['status'] === 'closed') {
                                    $statusText = 'مغلق';
                                }
                            ?>
                                <div style="margin-bottom: 24px; border-bottom: 1px solid #E5E7EB; padding-bottom: 16px;">
                                    <h3 style="font-size: 18px; font-weight: bold; color: #1F2937; margin-bottom: 8px;"><?= htmlspecialchars($device['item_number']) ?></h3>
                                    <p style="color: #6B7280; margin-bottom: 12px;"><?= htmlspecialchars($device['specs']) ?></p>
                                    
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                                        <div>
                                            <p style="font-size: 14px; color: #6B7280; margin: 4px 0;">رقم التذكرة: <span style="font-weight: 500;"><?= htmlspecialchars($device['ticket_number'] ?? 'N/A') ?></span></p>
                                            <p style="font-size: 14px; color: #6B7280; margin: 4px 0;">الرقم التسلسلي: <span style="font-weight: 500;"><?= htmlspecialchars($device['serial_number'] ?? 'N/A') ?></span></p>
                                            <p style="font-size: 14px; color: #6B7280; margin: 4px 0;">الموظف: <span style="font-weight: 500;"><?= htmlspecialchars($device['employee_name']) ?></span></p>
                                        </div>
                                        <div>
                                            <p style="font-size: 14px; color: #6B7280; margin: 4px 0;">الفرع: <span style="font-weight: 500;"><?= htmlspecialchars($device['branch_name'] ?? 'N/A') ?></span></p>
                                            <p style="font-size: 14px; color: #6B7280; margin: 4px 0;">نوع المشكلة: <span style="font-weight: 500;"><?= htmlspecialchars($device['problem_type'] ?? 'N/A') ?></span></p>
                                            <p style="font-size: 14px; color: #6B7280; margin: 4px 0;">طبيعة المشكلة: <span style="font-weight: 500;"><?= htmlspecialchars($device['problem_nature'] ?? 'N/A') ?></span></p>
                                        </div>
                                    </div>
                                    
                                    <p style="font-size: 14px; color: #6B7280; margin: 4px 0;">مع الشاحن: <span style="font-weight: 500;"><?= $device['with_charger'] ? 'نعم' : 'لا' ?></span></p>
                                    <p style="font-size: 14px; color: #6B7280; margin: 4px 0 12px 0;">الحالة: <span style="font-weight: 500;"><?= $statusText ?></span></p>
                                    
                                    <h4 style="font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 8px;">المشاكل المبلغ عنها:</h4>
                                    
                                    <?php if (!empty($device['problems'])): ?>
                                        <?php foreach ($device['problems'] as $problem): ?>
                                            <?php
                                            $problemStatusText = 'قيد المعالجة';
                                            $problemStatusColor = '#FEF3C7';
                                            $problemTextColor = '#92400E';
                                            
                                            if (stripos($problem['repair_result'] ?? '', 'تم الإصلاح') !== false) {
                                                $problemStatusText = 'تم الإصلاح';
                                                $problemStatusColor = '#D1FAE5';
                                                $problemTextColor = '#065F46';
                                            }
                                            ?>
                                            
                                            <div style="border-left: 3px solid #E5E7EB; padding: 8px; margin-bottom: 8px; background: #F9FAFB;">
                                                <div style="display: flex; justify-content: space-between;">
                                                    <div>
                                                        <h4 style="font-weight: bold; margin: 0; color: #374151;"><?= htmlspecialchars($problem['problem_title']) ?></h4>
                                                        <p style="margin: 4px 0; color: #6B7280;"><?= htmlspecialchars($problem['problem_details']) ?></p>
                                                        <?php if (!empty($problem['repair_result'])): ?>
                                                            <p style="margin: 4px 0; color: #065F46;">نتيجة الإصلاح: <?= htmlspecialchars($problem['repair_result']) ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span style="background: <?= $problemStatusColor ?>; color: <?= $problemTextColor ?>; padding: 4px 8px; border-radius: 9999px; font-size: 12px; font-weight: 600;"><?= $problemStatusText ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p style="color: #6B7280;">لا توجد مشاكل مسجلة لهذا الجهاز.</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 32px; border-top: 1px solid #E5E7EB; padding-top: 16px;">
                            <p>إجمالي الأجهزة: <span style="font-weight: 500;"><?= count($devices) ?></span></p>
                            <p>عدد الأجهزة التي تم إصلاحها: <span style="font-weight: 500;"><?= $fixedCount ?></span></p>
                            <p>عدد الأجهزة قيد المعالجة: <span style="font-weight: 500;"><?= $inProgressCount ?></span></p>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>

        <!-- Footer -->
        <footer class="bg-gray-800 text-white py-6 mt-12 no-print">
            <div class="container mx-auto px-4 text-center">
                <p>© 2023 نظام إدارة تذاكر الصيانة. جميع الحقوق محفوظة.</p>
            </div>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const printBtn = document.getElementById('printBtn');
            
            if (printBtn) {
                printBtn.addEventListener('click', function() {
                    window.print();
                });
            }
        });
    </script>
</body>
</html>