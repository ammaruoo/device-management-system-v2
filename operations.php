<?php
// ========================================================================
// الإعدادات الأولية وبدء الجلسة
// ========================================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'db.php';

// ========================================================================
// التحقق من صلاحيات المستخدم والبيانات المطلوبة
// ========================================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];
$laptop_id = $_GET['laptop_id'] ?? 0;
if (empty($laptop_id)) {
    die("رقم الجهاز غير صالح.");
}

// ========================================================================
// جلب البيانات الأساسية للجهاز من قاعدة البيانات
// ========================================================================
$stmt = $pdo->prepare("
    SELECT b.*, u.username as assigned_to 
    FROM broken_laptops b 
    LEFT JOIN users u ON b.assigned_user_id = u.user_id 
    WHERE b.laptop_id = ?
");
$stmt->execute([$laptop_id]);
$laptop = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$laptop) {
    die("الجهاز غير موجود.");
}

// ========================================================================
// جلب كل الأحداث المتعلقة بالجهاز (عمليات، شكاوى، إغلاق، مناقشات)
// ========================================================================
$timeline_query = $pdo->prepare("
    (SELECT 'operation' as type, o.operation_date as date, u.username, o.repair_result as title, o.details, o.image_path, o.operation_id, o.receipt_number
     FROM operations o JOIN users u ON o.user_id = u.user_id WHERE o.laptop_id = ?)
    UNION ALL
    (SELECT 'complaint' as type, c.complaint_date as date, u.username, c.problem_title as title, c.problem_details as details, c.image_path, NULL as operation_id, NULL as receipt_number
     FROM complaints c JOIN users u ON c.user_id = u.user_id WHERE c.laptop_id = ?)
    UNION ALL
    (SELECT 'lock' as type, l.lock_date as date, u.username, CONCAT('إغلاق تذكرة: ', l.lock_type) as title, l.more_description as details, NULL as image_path, NULL as operation_id, NULL as receipt_number
     FROM locks l JOIN users u ON l.user_id = u.user_id WHERE l.laptop_id = ?)
    UNION ALL
    (SELECT 'discussion' as type, d.created_at as date, u.username, 'رسالة في الدردشة' as title, d.message as details, d.image_path, NULL as operation_id, NULL as receipt_number
     FROM laptop_discussions d JOIN users u ON d.user_id = u.user_id WHERE d.laptop_id = ?)
    ORDER BY date DESC
");
$timeline_query->execute([$laptop_id, $laptop_id, $laptop_id, $laptop_id]);
$timeline_events = $timeline_query->fetchAll(PDO::FETCH_ASSOC);

// ========================================================================
// جلب بيانات التكاليف وحساب الإجمالي
// ========================================================================
$costs_query = $pdo->prepare("SELECT operation_id, work_order_ref, total_cost, cost_items FROM repair_costs WHERE laptop_id = ?");
$costs_query->execute([$laptop_id]);
$costs_data = $costs_query->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

$total_repair_cost = 0;
if (is_array($costs_data)) {
    foreach($costs_data as $op_costs) {
        if (is_array($op_costs)) {
            foreach($op_costs as $cost_entry) {
                $total_repair_cost += (float)($cost_entry['total_cost'] ?? 0);
            }
        }
    }
}

// ========================================================================
// تحديد قائمة العمليات المعرفة مسبقًا للأزرار
// ========================================================================
$predefined_ops = [
    "سند استلام",
    "امر شغل",
    "إنشاء فاتورة",
    "اعتماد فاتورة",
    "تقرير مسؤول الصيانة",
    "غير ذلك"
];
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجل عمليات الجهاز: <?= htmlspecialchars($laptop['serial_number'] ?: $laptop['laptop_id']) ?></title>
    
    <!-- تحميل المكتبات الخارجية -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <link href="https://unpkg.com/filepond/dist/filepond.css" rel="stylesheet">
    <link href="https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <!-- تنسيقات مخصصة للصفحة -->
    <style>
        body { font-family: 'Cairo', sans-serif; }
        [x-cloak] { display: none !important; }
        .ql-editor { min-height: 120px; font-size: 1rem; }
        .timeline-item:not(:last-child)::before {
            content: ''; position: absolute; top: 1.25rem; right: 0.7rem; width: 2px;
            height: calc(100% + 2rem); background-color: #E5E7EB;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4 sm:p-6 lg:p-8" x-data="operationsPage()">
    
    <!-- رأس الصفحة -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">السجل الزمني للجهاز</h1>
            <p class="text-gray-500">رقم التذكرة: <span class="font-semibold font-mono"><?= htmlspecialchars($laptop['laptop_id']) ?></span></p>
        </div>
        <div class="flex gap-4">
            <a href="broken_laptops.php" class="text-sm text-blue-600 hover:underline">العودة لقائمة الأجهزة</a>
            <?php if (in_array($_SESSION['permissions'], ['admin', 'manager', 'accountant'])): ?>
            <a href="financial_management/index.php" class="text-sm text-green-600 hover:underline">الإدارة المالية</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- تقسيم الصفحة إلى عمودين -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- العمود الجانبي: ملخص الجهاز -->
        <aside class="lg:col-span-1 bg-white p-6 rounded-2xl shadow-lg h-fit lg:sticky top-8">
            <h2 class="text-xl font-bold mb-4 border-b pb-3">ملخص الجهاز</h2>
            <div class="space-y-4">
                <div><p class="text-sm text-gray-500">المواصفات</p><p class="font-semibold text-gray-800"><?= htmlspecialchars($laptop['specs'] ?: 'غير محدد') ?></p></div>
                <div><p class="text-sm text-gray-500">الفني المسؤول</p><p class="font-semibold text-gray-800"><?= htmlspecialchars($laptop['assigned_to'] ?: 'لم يعين') ?></p></div>
                <div><p class="text-sm text-gray-500">الموظف</p><p class="font-semibold text-gray-800"><?= htmlspecialchars($laptop['employee_name'] ?: 'غير محدد') ?></p></div>
                <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                    <p class="text-sm text-blue-800">إجمالي تكاليف الإصلاح</p>
                    <p class="font-bold text-2xl text-blue-600">$<?= number_format($total_repair_cost, 2) ?></p>
                </div>
            </div>
        </aside>

        <!-- المحتوى الرئيسي -->
        <main class="lg:col-span-2">
            <!-- نموذج إضافة عملية جديدة -->
            <div class="bg-white p-6 sm:p-8 rounded-2xl shadow-lg mb-8">
                <h2 class="text-xl font-bold mb-6">إضافة عملية جديدة</h2>
                <form @submit.prevent="submitOperation">
                    <div class="space-y-6">
                        <!-- أزرار اختيار نوع العملية -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">اختر نوع العملية:</label>
                            <div class="grid grid-cols-1 gap-3">
                                <?php foreach($predefined_ops as $op): ?>
                                    <button type="button" 
                                            @click="selectOperation('<?= htmlspecialchars($op) ?>')"
                                            :class="{
                                                'bg-blue-600 text-white ring-2 ring-offset-2 ring-blue-500': selectedOperation === '<?= htmlspecialchars($op) ?>',
                                                'bg-gray-200 text-gray-800 hover:bg-gray-300': selectedOperation !== '<?= htmlspecialchars($op) ?>'
                                            }"
                                            class="w-full text-center px-4 py-3 text-md font-semibold rounded-lg transition-all duration-200 ease-in-out">
                                        <?= htmlspecialchars($op) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- حقل رقم سند الاستلام (يظهر عند اختيار "سند استلام") -->
                        <div x-show="isReceiptVoucher" x-transition class="border-t pt-4 space-y-4">
                            <div>
                            <label for="receipt_number" class="block text-sm font-medium text-gray-700 mb-1">رقم سند الاستلام</label>
                                <input type="text" id="receipt_number" name="receipt_number" x-model="receiptNumber" placeholder="أدخل رقم سند الاستلام هنا" :required="isReceiptVoucher" class="w-full p-3 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label for="receipt_date" class="block text-sm font-medium text-gray-700 mb-1">تاريخ سند الاستلام</label>
                                <input type="date" id="receipt_date" name="receipt_date" x-model="receiptDate" :required="isReceiptVoucher" class="w-full p-3 border border-gray-300 rounded-lg">
                            </div>
                        </div>

                        <!-- حقول أمر الشغل (تظهر عند اختيار "امر شغل") -->
                        <div x-show="isWorkOrder" x-transition class="border-t pt-4 space-y-4">
                             <div>
                                <label for="work_order_ref" class="block text-sm font-medium text-gray-700 mb-1">رقم أمر الشغل (من النظام المحاسبي)</label>
                                <input type="text" id="work_order_ref" name="work_order_ref" x-model="workOrder.ref" placeholder="..." :required="isWorkOrder" class="w-full p-3 border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">قائمة التكاليف</label>
                                <div class="space-y-3">
                                    <template x-for="(cost, index) in workOrder.costs" :key="index">
                                        <div class="flex flex-col sm:flex-row items-center gap-2">
                                            <input type="text" name="cost_description[]" x-model="cost.description" placeholder="وصف القطعة أو العمل" :required="isWorkOrder" class="w-full sm:flex-1 p-2 border border-gray-300 rounded-lg">
                                            <input type="number" step="0.01" name="cost_amount[]" x-model="cost.cost" placeholder="التكلفة بالدولار" :required="isWorkOrder" class="w-full sm:w-32 p-2 border border-gray-300 rounded-lg">
                                            <button type="button" @click="removeCost(index)" x-show="workOrder.costs.length > 1" class="text-red-500 hover:text-red-700 p-2">&times;</button>
                                        </div>
                                    </template>
                                </div>
                                <button type="button" @click="addCost" class="mt-3 text-sm font-semibold text-blue-600 hover:text-blue-800">+ إضافة تكلفة أخرى</button>
                                <div class="mt-3 text-right font-bold text-lg">الإجمالي: $<span x-text="totalCost.toFixed(2)"></span></div>
                            </div>
                        </div>

                        <!-- حقول إنشاء الفاتورة (تظهر عند اختيار "إنشاء فاتورة") -->
                        <div x-show="isCreateInvoice" x-transition class="border-t pt-4 space-y-4">
                            <div>
                                <h4 class="font-semibold text-blue-800 mb-3">أوامر الشغل المنجزة للجهاز (بدون فواتير):</h4>
                                
                                <!-- رسالة التحميل -->
                                <div x-show="isLoadingWorkOrders" class="text-center py-6">
                                    <div class="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-700 rounded-lg">
                                        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        جاري تحميل أوامر الشغل...
                                    </div>
                                </div>
                                
                                <!-- قائمة أوامر الشغل -->
                                <div x-show="!isLoadingWorkOrders" class="space-y-3">
                                    <template x-for="workOrder in availableWorkOrders" :key="workOrder.operation_id">
                                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition-colors"
                                             @click="selectWorkOrderForInvoice(workOrder)">
                                            <div class="flex justify-between items-center">
                                                <div>
                                                    <span class="font-medium text-gray-700">رقم أمر الشغل:</span>
                                                    <span class="text-blue-600 font-semibold ml-2" x-text="workOrder.work_order_ref"></span>
                                                </div>
                                                <div class="text-right">
                                                    <span class="font-medium text-gray-700">التاريخ:</span>
                                                    <span class="text-blue-600 ml-2" x-text="workOrder.operation_date"></span>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <span class="font-medium text-gray-700">إجمالي التكاليف:</span>
                                                <span class="text-blue-600 font-bold ml-2">$<span x-text="workOrder.total_cost"></span></span>
                                            </div>
                                            <div class="mt-2 text-sm text-gray-600">
                                                <span class="font-medium">المهندس:</span>
                                                <span class="ml-2" x-text="workOrder.engineer_name"></span>
                                            </div>
                                        </div>
                                    </template>
                                    
                                    <div x-show="availableWorkOrders.length === 0" class="text-center py-6 text-gray-500">
                                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                            <svg class="w-12 h-12 text-yellow-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                            </svg>
                                            <h4 class="font-semibold text-yellow-800 mb-2">لا توجد أوامر شغل متاحة</h4>
                                            <p class="text-yellow-700 mb-3">لم يتم العثور على أوامر شغل منجزة بدون فواتير لهذا الجهاز</p>
                                            <div class="text-sm text-yellow-600">
                                                <p>الأسباب المحتملة:</p>
                                                <ul class="list-disc list-inside mt-2 space-y-1">
                                                    <li>لم يتم إنشاء أي أمر شغل لهذا الجهاز بعد</li>
                                                    <li>جميع أوامر الشغل لها فواتير بالفعل</li>
                                                    <li>قد تكون هناك مشكلة في قاعدة البيانات</li>
                                                </ul>
                                            </div>
                                            <div class="mt-4">
                                                <button type="button" @click="loadAvailableWorkOrders()" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">
                                                    إعادة المحاولة
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- عرض بيانات أمر الشغل المحدد -->
                            <div x-show="selectedWorkOrder" class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                                <h4 class="font-semibold text-blue-800 mb-3">بيانات أمر الشغل المحدد:</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <span class="font-medium text-gray-700">رقم أمر الشغل:</span>
                                        <span class="text-blue-600 font-semibold" x-text="selectedWorkOrder?.work_order_ref || ''"></span>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-700">التاريخ:</span>
                                        <span class="text-blue-600" x-text="selectedWorkOrder?.operation_date || ''"></span>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-700">المهندس:</span>
                                        <span class="text-blue-600" x-text="selectedWorkOrder?.engineer_name || ''"></span>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-700">إجمالي التكاليف:</span>
                                        <span class="text-blue-600 font-bold">$<span x-text="selectedWorkOrder?.total_cost || '0.00'"></span></span>
                                    </div>
                                </div>
                                
                                <!-- تفاصيل التكاليف مع إمكانية التعديل -->
                                <div class="mt-4">
                                    <h5 class="font-medium text-gray-700 mb-2">تفاصيل التكاليف (يمكن تعديلها):</h5>
                                    <div class="space-y-2">
                                        <template x-for="(cost, index) in (selectedWorkOrder?.cost_items || [])" :key="index">
                                            <div class="flex justify-between items-center bg-white p-2 rounded border">
                                                <span x-text="cost.description" class="flex-1"></span>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-sm text-gray-500">الأصلي: $<span x-text="cost.cost"></span></span>
                                                    <input type="number" step="0.01" 
                                                           x-model="cost.modified_cost" 
                                                           :placeholder="cost.cost"
                                                           class="w-24 p-1 border border-gray-300 rounded text-sm">
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <!-- حقول الفاتورة -->
                            <div x-show="isCreateInvoice && selectedWorkOrder" class="space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label for="invoice_number" class="block text-sm font-medium text-gray-700 mb-1">رقم الفاتورة (من النظام المحاسبي)</label>
                                        <input type="text" id="invoice_number" name="invoice_number" x-model="invoice.invoice_number" placeholder="مثال: INV-2025-001" :required="isCreateInvoice" class="w-full p-3 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label for="invoice_date" class="block text-sm font-medium text-gray-700 mb-1">تاريخ الفاتورة</label>
                                        <input type="date" id="invoice_date" name="invoice_date" x-model="invoice.invoice_date" :required="isCreateInvoice" class="w-full p-3 border border-gray-300 rounded-lg">
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات إضافية</label>
                                    <textarea x-model="invoice.notes" rows="3" placeholder="ملاحظات حول الفاتورة..." class="w-full p-3 border border-gray-300 rounded-lg"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- حقول اعتماد الفاتورة (تظهر عند اختيار "اعتماد فاتورة") -->
                        <div x-show="isApproveInvoice" x-transition class="border-t pt-4 space-y-4">
                            <div>
                                <label for="invoice_search" class="block text-sm font-medium text-gray-700 mb-1">البحث عن الفاتورة</label>
                                <div class="flex gap-2">
                                    <input type="text" id="invoice_search" x-model="invoiceSearch" placeholder="أدخل رقم الفاتورة أو رقم أمر الشغل" class="w-full p-3 border border-gray-300 rounded-lg">
                                    <button type="button" @click="searchInvoice()" class="px-4 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                        </svg>
                                        بحث
                                    </button>
                                </div>
                            </div>
                            
                            <!-- عرض بيانات الفاتورة -->
                            <div x-show="selectedInvoice" class="bg-green-50 p-4 rounded-lg border border-green-200">
                                <h4 class="font-semibold text-green-800 mb-3">بيانات الفاتورة المحددة:</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <span class="font-medium text-gray-700">رقم الفاتورة:</span>
                                        <span class="text-green-600 font-semibold" x-text="selectedInvoice?.invoice_number || ''"></span>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-700">التاريخ:</span>
                                        <span class="text-green-600" x-text="selectedInvoice?.invoice_date || ''"></span>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-700">المبلغ الإجمالي:</span>
                                        <span class="text-green-600 font-bold">$<span x-text="selectedInvoice?.total_amount || '0.00'"></span></span>
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-700">الحالة:</span>
                                        <span class="text-green-600" x-text="selectedInvoice?.status || ''"></span>
                                    </div>
                                </div>
                                
                                <!-- تفاصيل بنود الفاتورة -->
                                <div class="mt-4">
                                    <h5 class="font-medium text-gray-700 mb-2">تفاصيل البنود:</h5>
                                    <div class="space-y-2">
                                        <template x-for="item in (selectedInvoice?.items || [])" :key="item.item_id">
                                            <div class="flex justify-between items-center bg-white p-2 rounded border">
                                                <span x-text="item.description"></span>
                                                <span class="font-semibold">$<span x-text="item.final_cost"></span></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                
                                <!-- حقول الاعتماد -->
                                <div class="mt-4 space-y-4">
                                    <div>
                                        <label for="approved_amount" class="block text-sm font-medium text-gray-700 mb-1">المبلغ المعتمد</label>
                                        <input type="number" step="0.01" id="approved_amount" name="approved_amount" x-model="approval.approved_amount" :placeholder="selectedInvoice?.total_amount || '0.00'" :required="isApproveInvoice" class="w-full p-3 border border-gray-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات الاعتماد</label>
                                        <textarea id="approval_notes" name="approval_notes" x-model="approval.notes" rows="3" placeholder="ملاحظات حول الاعتماد..." class="w-full p-3 border border-gray-300 rounded-lg"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- حقول التفاصيل والصورة (تظهر دائمًا) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">تفاصيل إضافية (اختياري)</label>
                            <div x-ref="quillEditor"></div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">إرفاق صورة (اختياري)</label>
                            <input type="file" class="filepond" name="image">
                        </div>
                    </div>
                    <!-- زر التسجيل -->
                    <div class="mt-6">
                        <button type="submit" class="w-full px-6 py-3 bg-blue-600 text-white font-bold rounded-lg hover:bg-blue-700 disabled:bg-gray-400" :disabled="isSubmitting || !selectedOperation">تسجيل العملية</button>
                    </div>
                </form>
            </div>
            
            <!-- السجل الزمني (Timeline) -->
            <div>
                <div class="mb-4">
                    <input type="text" x-model="timelineSearch" placeholder="بحث في السجل..." class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                <div class="space-y-8">
                    <template x-for="event in filteredTimeline" :key="event.date + event.title">
                        <div class="flex gap-4 relative timeline-item">
                            <!-- أيقونة الحدث -->
                            <div class="bg-white flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center z-10" :class="getEventStyle(event.type).bg">
                                <svg class="w-6 h-6" :class="getEventStyle(event.type).iconColor" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-html="getEventStyle(event.type).icon"></svg>
                            </div>
                            <!-- تفاصيل الحدث -->
                            <div class="flex-1">
                                <div class="bg-white p-4 rounded-lg shadow">
                                    <div class="flex justify-between items-center mb-2">
                                        <p class="font-bold text-gray-800" x-text="event.title"></p>
                                        <span class="text-xs text-gray-500" x-text="new Date(event.date).toLocaleString('ar-EG', {dateStyle: 'medium', timeStyle: 'short'})"></span>
                                    </div>
                                    
                                    <!-- عرض رقم سند الاستلام إن وجد -->
                                    <template x-if="event.receipt_number">
                                        <p class="text-sm text-gray-600 mb-2">رقم سند الاستلام: <span class="font-semibold font-mono bg-gray-100 px-2 py-1 rounded" x-text="event.receipt_number"></span></p>
                                    </template>

                                    <p class="text-sm text-gray-500 mb-2">بواسطة: <span class="font-semibold" x-text="event.username"></span></p>
                                    <div class="prose prose-sm max-w-none text-gray-700" x-html="event.details"></div>
                                    
                                    <!-- عرض الصورة المرفقة إن وجدت -->
                                    <template x-if="event.image_path">
                                        <img :src="event.image_path" class="mt-3 w-full max-w-xs rounded-lg cursor-pointer" @click="openImage(event.image_path)">
                                    </template>

                                    <!-- عرض تفاصيل التكاليف إن وجدت -->
                                    <template x-if="event.cost_details">
                                        <div class="mt-3 border-t pt-3">
                                            <p class="font-semibold text-sm">تفاصيل أمر الشغل: <span class="font-mono text-blue-600" x-text="event.cost_details.work_order_ref"></span></p>
                                            <ul class="list-disc pr-5 mt-2 text-sm">
                                                <template x-for="item in event.cost_details.cost_items" :key="item.description">
                                                    <li><span x-text="item.description"></span>: <span class="font-semibold" x-text="`$${parseFloat(item.cost).toFixed(2)}`"></span></li>
                                                </template>
                                            </ul>
                                            <p class="text-right font-bold mt-2">الإجمالي: <span x-text="`$${parseFloat(event.cost_details.total_cost).toFixed(2)}`"></span></p>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                     <div x-show="filteredTimeline.length === 0" class="text-center py-10 text-gray-500">
                        <p>لا توجد نتائج مطابقة للبحث.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- نافذة عرض الصور -->
    <div x-show="isImageViewerOpen" @keydown.escape.window="isImageViewerOpen = false" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 p-4" x-cloak>
        <div @click.away="isImageViewerOpen = false" class="relative">
            <img :src="imageViewerSrc" class="max-w-full max-h-[90vh] rounded-lg">
            <button @click="isImageViewerOpen = false" class="absolute -top-2 -right-2 text-white bg-gray-800 rounded-full p-1">&times;</button>
        </div>
    </div>


</div>

<!-- تحميل مكتبات JavaScript -->
<script src="https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.js"></script>
<script src="https://unpkg.com/filepond/dist/filepond.js"></script>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('operationsPage', () => ({
        // متغيرات الحالة
        isSubmitting: false,
        quill: null,
        pond: null,
        timelineSearch: '',
        selectedOperation: '',
        isWorkOrder: false,
        isReceiptVoucher: false,
        receiptNumber: '',
        receiptDate: new Date().toISOString().split('T')[0], // تاريخ اليوم
        workOrder: { ref: '', costs: [{ description: '', cost: 0.00 }] },
        timelineEvents: [],
        isImageViewerOpen: false,
        imageViewerSrc: '',
        
        // متغيرات الفاتورة
        invoice: {
            invoice_number: '',
            invoice_date: new Date().toISOString().split('T')[0],
            items: [],
            notes: ''
        },
        
        // متغيرات إنشاء الفاتورة
        isCreateInvoice: false,
        availableWorkOrders: [],
        selectedWorkOrder: null,
        isLoadingWorkOrders: false,
        
        // متغيرات اعتماد الفاتورة
        isApproveInvoice: false,
        deviceInvoices: [],
        selectedInvoice: null,
        invoiceSearch: '',
        approval: {
            approved_amount: '',
            notes: ''
        },

        // دالة محسوبة لحساب إجمالي التكلفة
        get totalCost() {
            return this.workOrder.costs.reduce((total, item) => total + (parseFloat(item.cost) || 0), 0);
        },
        
        // دالة محسوبة لحساب إجمالي الفاتورة
        get invoiceTotal() {
            return this.invoice.items.reduce((total, item) => {
                const cost = parseFloat(item.modified_cost) || parseFloat(item.original_cost) || 0;
                return total + cost;
            }, 0);
        },
        
        // دالة محسوبة لفلترة السجل الزمني بناءً على البحث
        get filteredTimeline() {
            if (!this.timelineSearch.trim()) {
                return this.timelineEvents;
            }
            const search = this.timelineSearch.toLowerCase();
            return this.timelineEvents.filter(event => {
                const titleMatch = event.title && event.title.toLowerCase().includes(search);
                const detailsMatch = event.details && event.details.toLowerCase().includes(search);
                const usernameMatch = event.username && event.username.toLowerCase().includes(search);
                const receiptMatch = event.receipt_number && event.receipt_number.toLowerCase().includes(search);
                return titleMatch || detailsMatch || usernameMatch || receiptMatch;
            });
        },

        // دالة التهيئة عند تحميل الصفحة
        init() {
            // تحويل بيانات PHP إلى متغيرات JavaScript
            this.timelineEvents = <?= json_encode($timeline_events) ?>.map(event => {
                const cost_data = <?= json_encode($costs_data) ?>;
                const cost_details = cost_data && cost_data[event.operation_id] ? cost_data[event.operation_id][0] : null;
                // التأكد من أن حقل التكاليف هو مصفوفة وليس نص
                if (cost_details && typeof cost_details.cost_items === 'string') {
                    try {
                        cost_details.cost_items = JSON.parse(cost_details.cost_items);
                    } catch(e) {
                        cost_details.cost_items = []; // في حال وجود خطأ بالـ JSON
                    }
                }
                return { ...event, cost_details: cost_details };
            });

            // تهيئة محرر النصوص Quill
            this.quill = new Quill(this.$refs.quillEditor, { theme: 'snow', placeholder: 'اكتب تفاصيل إضافية هنا...' });
            
            // تهيئة مكتبة رفع الملفات FilePond
            FilePond.registerPlugin(FilePondPluginImagePreview);
            this.pond = FilePond.create(document.querySelector('.filepond'), {
                labelIdle: `اسحب وأفلت الصورة هنا أو <span class="filepond--label-action">تصفح</span>`,
                credits: false, storeAsFile: true,
            });
        },

        // دالة عند اختيار عملية من الأزرار
        selectOperation(op) {
            this.selectedOperation = op;
            this.isWorkOrder = (op === 'امر شغل');
            this.isReceiptVoucher = (op === 'سند استلام');
            this.isCreateInvoice = (op === 'إنشاء فاتورة');
            this.isApproveInvoice = (op === 'اعتماد فاتورة');
            
            // إعادة تعيين المتغيرات عند تغيير نوع العملية
            if (op === 'إنشاء فاتورة') {
                this.selectedWorkOrder = null;
                this.invoice = {
                    invoice_number: '',
                    invoice_date: new Date().toISOString().split('T')[0],
                    items: [],
                    notes: ''
                };
                // تحميل أوامر الشغل المتاحة
                this.loadAvailableWorkOrders();
            } else if (op === 'اعتماد فاتورة') {
                this.selectedInvoice = null;
                this.approval = {
                    approved_amount: '',
                    notes: ''
                };
                // تحميل فواتير الجهاز
                this.loadDeviceInvoices();
            }
        },

        // دوال لإدارة قائمة التكاليف
        addCost() { this.workOrder.costs.push({ description: '', cost: 0.00 }); },
        removeCost(index) { this.workOrder.costs.splice(index, 1); },
        
        // دوال لإدارة عرض الصور
        openImage(src) { this.imageViewerSrc = src; this.isImageViewerOpen = true; },

        // دالة لتحديد أيقونة ولون الحدث في السجل الزمني
        getEventStyle(type) {
            const styles = {
                operation:  { icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />', bg: 'bg-blue-100', iconColor: 'text-blue-600' },
                complaint:  { icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />', bg: 'bg-red-100', iconColor: 'text-red-600' },
                lock:       { icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />', bg: 'bg-green-100', iconColor: 'text-green-600' },
                discussion: { icon: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />', bg: 'bg-gray-100', iconColor: 'text-gray-600' }
            };
            return styles[type] || styles['discussion'];
        },

        // دالة تسجيل العملية عند الضغط على زر الإرسال
        submitOperation() {
            // التحقق من صحة المدخلات
            if (!this.selectedOperation) {
                Swal.fire({ icon: 'error', title: 'خطأ', text: 'الرجاء اختيار نوع العملية أولاً.' });
                return;
            }
            if (this.isReceiptVoucher && !this.receiptNumber.trim()) {
                Swal.fire({ icon: 'error', title: 'خطأ', text: 'الرجاء إدخال رقم سند الاستلام.' });
                this.isSubmitting = false;
                return;
            }
            
            if (this.isWorkOrder && !this.workOrder.ref.trim()) {
                Swal.fire({ icon: 'error', title: 'خطأ', text: 'الرجاء إدخال رقم أمر الشغل.' });
                this.isSubmitting = false;
                return;
            }
            
            if (this.isWorkOrder && this.workOrder.costs.some(cost => !cost.description.trim() || !cost.cost)) {
                Swal.fire({ icon: 'error', title: 'خطأ', text: 'الرجاء إدخال جميع تفاصيل التكاليف.' });
                this.isSubmitting = false;
                return;
            }

            this.isSubmitting = true;
            const formData = new FormData();
            
            // تجميع البيانات لإرسالها
            formData.append('laptop_id', <?= json_encode($laptop_id) ?>);
            formData.append('user_id', <?= json_encode($user_id) ?>);
            formData.append('repair_result', this.selectedOperation);
            formData.append('details', this.quill ? this.quill.root.innerHTML : '');
            
            if (this.pond && this.pond.getFiles().length > 0) {
                formData.append('image', this.pond.getFile().file);
            }

            if (this.isReceiptVoucher) {
                formData.append('receipt_number', this.receiptNumber);
                formData.append('receipt_date', this.receiptDate);
            }

            if (this.isWorkOrder) {
                formData.append('work_order_ref', this.workOrder.ref);
                formData.append('cost_items', JSON.stringify(this.workOrder.costs));
            }

            if (this.isCreateInvoice) {
                if (!this.selectedWorkOrder) {
                    Swal.fire({ icon: 'error', title: 'خطأ', text: 'يجب اختيار أمر الشغل أولاً.' });
                    this.isSubmitting = false;
                    return;
                }
                if (!this.invoice.invoice_number.trim()) {
                    Swal.fire({ icon: 'error', title: 'خطأ', text: 'الرجاء إدخال رقم الفاتورة.' });
                    this.isSubmitting = false;
                    return;
                }
                if (!this.invoice.invoice_date) {
                    Swal.fire({ icon: 'error', title: 'خطأ', text: 'الرجاء إدخال تاريخ الفاتورة.' });
                    this.isSubmitting = false;
                    return;
                }
                formData.append('work_order_id', this.selectedWorkOrder.operation_id);
                formData.append('invoice_number', this.invoice.invoice_number);
                formData.append('invoice_date', this.invoice.invoice_date);
                formData.append('notes', this.invoice.notes);
            }

            if (this.isApproveInvoice) {
                if (!this.selectedInvoice) {
                    Swal.fire({ icon: 'error', title: 'خطأ', text: 'يجب اختيار الفاتورة أولاً.' });
                    this.isSubmitting = false;
                    return;
                }
                if (!this.approval.approved_amount || this.approval.approved_amount <= 0) {
                    Swal.fire({ icon: 'error', title: 'خطأ', text: 'الرجاء إدخال المبلغ المعتمد.' });
                    this.isSubmitting = false;
                    return;
                }
                formData.append('invoice_id', this.selectedInvoice.invoice_id);
                formData.append('approved_amount', this.approval.approved_amount);
                formData.append('approval_notes', this.approval.notes);
            }

            // إرسال البيانات إلى الخادم
            fetch('api_handler.php?action=add_operation', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'تم!', text: data.message, timer: 1500, showConfirmButton: false });
                    setTimeout(() => window.location.reload(), 1500); // إعادة تحميل الصفحة بعد النجاح
                } else {
                    Swal.fire({ icon: 'error', title: 'خطأ', text: data.message });
                }
            })
            .catch(err => Swal.fire({ icon: 'error', title: 'خطأ فني', text: 'فشل الاتصال بالخادم.' }))
            .finally(() => this.isSubmitting = false);
        },



        // دوال إنشاء الفاتورة
        loadAvailableWorkOrders() {
            this.isLoadingWorkOrders = true;
            this.availableWorkOrders = [];
            
            fetch(`api_handler.php?action=get_available_work_orders&laptop_id=${<?= json_encode($laptop_id) ?>}`, {
                method: 'GET',
                credentials: 'same-origin', // إرسال cookies الجلسة
                headers: {
                    'Content-Type': 'application/json',
                }
            })
            .then(res => {
                console.log('Response status:', res.status);
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                return res.json();
            })
            .then(data => {
                console.log('API Response:', data); // تسجيل للتحقق
                if (data.success) {
                    this.availableWorkOrders = data.work_orders;
                } else {
                    console.error('API Error:', data.message);
                    this.availableWorkOrders = [];
                    // عرض رسالة خطأ للمستخدم
                    if (data.message) {
                        Swal.fire({
                            icon: 'error',
                            title: 'خطأ في تحميل أوامر الشغل',
                            text: data.message
                        });
                    }
                }
            })
            .catch(err => {
                console.error('Error loading work orders:', err);
                this.availableWorkOrders = [];
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ في الاتصال',
                    text: 'فشل في تحميل أوامر الشغل. تأكد من تسجيل الدخول.'
                });
            })
            .finally(() => {
                this.isLoadingWorkOrders = false;
            });
        },

        selectWorkOrderForInvoice(workOrder) {
            this.selectedWorkOrder = { ...workOrder };
            // تحضير التكاليف للتعديل
            this.selectedWorkOrder.cost_items = this.selectedWorkOrder.cost_items.map(cost => ({
                ...cost,
                modified_cost: cost.cost // القيمة الأصلية
            }));
        },

        // دوال اعتماد الفاتورة
        loadDeviceInvoices() {
            fetch(`api_handler.php?action=get_device_invoices&laptop_id=${<?= json_encode($laptop_id) ?>}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.deviceInvoices = data.invoices;
                } else {
                    this.deviceInvoices = [];
                }
            })
            .catch(err => {
                console.error('Error loading invoices:', err);
                this.deviceInvoices = [];
            });
        },

        selectInvoiceForApproval(invoice) {
            this.selectedInvoice = { ...invoice };
            this.approval.approved_amount = invoice.total_amount;
            // تحضير البنود للتعديل
            this.selectedInvoice.items = this.selectedInvoice.items.map(item => ({
                ...item,
                modified_cost: item.final_cost // القيمة الأصلية
            }));
        },

        searchInvoice() {
            if (!this.invoiceSearch.trim()) {
                Swal.fire({ icon: 'error', title: 'خطأ', text: 'الرجاء إدخال رقم الفاتورة أو رقم أمر الشغل.' });
                return;
            }
            
            // البحث في الفواتير المحملة
            const foundInvoice = this.deviceInvoices.find(invoice => 
                invoice.invoice_number.toLowerCase().includes(this.invoiceSearch.toLowerCase()) ||
                invoice.work_order_ref.toLowerCase().includes(this.invoiceSearch.toLowerCase())
            );
            
            if (foundInvoice) {
                this.selectInvoiceForApproval(foundInvoice);
            } else {
                Swal.fire({ icon: 'warning', title: 'لم يتم العثور', text: 'لم يتم العثور على فاتورة بهذا الرقم.' });
            }
        },

        getStatusText(status) {
            const statusMap = {
                'pending': 'معلق',
                'approved': 'معتمد',
                'rejected': 'مرفوض'
            };
            return statusMap[status] || status;
        },


    }));
});
</script>

</body>
</html>
عمل الان