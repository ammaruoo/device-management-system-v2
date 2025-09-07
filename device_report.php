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
$laptop_id = $_GET['laptop_id'] ?? 0;
if (empty($laptop_id)) die("معرف الجهاز غير صالح.");

// =================================================================================
// FETCH ALL DATA RELATED TO THIS DEVICE
// =================================================================================
// 1. Fetch main device info, joining all related user data
$stmt = $pdo->prepare("
    SELECT 
        b.*, 
        u_assigned.username as assigned_to,
        u_entered.username as entered_by
    FROM broken_laptops b 
    LEFT JOIN users u_assigned ON b.assigned_user_id = u_assigned.user_id 
    LEFT JOIN users u_entered ON b.entered_by_user_id = u_entered.user_id
    WHERE b.laptop_id = ?
");
$stmt->execute([$laptop_id]);
$laptop = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$laptop) die("الجهاز غير موجود.");

// 2. Fetch UNIFIED timeline data from all relevant tables
$timeline_query = $pdo->prepare("
    (SELECT 'operation' as type, o.operation_date as date, u.username, o.repair_result as title, o.details, o.image_path, o.operation_id FROM operations o JOIN users u ON o.user_id = u.user_id WHERE o.laptop_id = ?)
    UNION ALL
    (SELECT 'complaint' as type, c.complaint_date as date, u.username, c.problem_title as title, c.problem_details as details, c.image_path, NULL as operation_id FROM complaints c JOIN users u ON c.user_id = u.user_id WHERE c.laptop_id = ?)
    UNION ALL
    (SELECT 'lock' as type, l.lock_date as date, u.username, CONCAT('إغلاق تذكرة: ', l.lock_type) as title, l.more_description as details, NULL as image_path, NULL as operation_id FROM locks l JOIN users u ON l.user_id = u.user_id WHERE l.laptop_id = ?)
    UNION ALL
    (SELECT 'discussion' as type, d.created_at as date, u.username, 'رسالة في الدردشة' as title, d.message as details, d.image_path, NULL as operation_id FROM laptop_discussions d JOIN users u ON d.user_id = u.user_id WHERE d.laptop_id = ?)
    ORDER BY date ASC
");
$timeline_query->execute([$laptop_id, $laptop_id, $laptop_id, $laptop_id]);
$timeline_events = $timeline_query->fetchAll(PDO::FETCH_ASSOC);

// 3. Calculate KPIs
$first_event_date = !empty($timeline_events) ? new DateTime($timeline_events[0]['date']) : new DateTime($laptop['creation_date']);
$last_event_date = new DateTime(); // Default to now
$is_closed = in_array($laptop['status'], ['locked', 'مغلق', 'جاهز للبيع', 'لم يتم الإصلاح']);
if ($is_closed) {
    $lock_event = array_filter($timeline_events, fn($e) => $e['type'] === 'lock');
    if (!empty($lock_event)) {
        $last_event_date = new DateTime(end($lock_event)['date']);
    }
}
$duration_interval = $first_event_date->diff($last_event_date);
$duration_string = $duration_interval->format('%a يوم, %h ساعة');

// 4. Fetch and calculate total cost
$costs_query = $pdo->prepare("SELECT operation_id, work_order_ref, total_cost, cost_items FROM repair_costs WHERE laptop_id = ?");
$costs_query->execute([$laptop_id]);
$costs_data = $costs_query->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
$total_repair_cost = array_sum(array_map(fn($cost) => $cost[0]['total_cost'], $costs_data));

// 5. Decode initial problems
$initial_problems = [];
if (!empty($laptop['problem_details'])) {
    $decoded = json_decode($laptop['problem_details'], true);
    if (json_last_error() === JSON_ERROR_NONE) $initial_problems = $decoded;
}

// 6. Helper for status styling
function getStatusDetails($status) {
    $status_map = [
        'entered' => ['name' => 'تم الإدخال', 'color' => 'gray', 'bg' => 'bg-gray-100', 'text' => 'text-gray-800'],
        'assigned' => ['name' => 'مُعيّن', 'color' => 'blue', 'bg' => 'bg-blue-100', 'text' => 'text-blue-800'],
        'في انتظار قطع' => ['name' => 'في انتظار قطع', 'color' => 'yellow', 'bg' => 'bg-yellow-100', 'text' => 'text-yellow-800'],
        'جاهز للبيع' => ['name' => 'جاهز للبيع', 'color' => 'green', 'bg' => 'bg-green-100', 'text' => 'text-green-800'],
        'لم يتم الإصلاح' => ['name' => 'لم يتم الإصلاح', 'color' => 'red', 'bg' => 'bg-red-100', 'text' => 'text-red-800'],
    ];
    return $status_map[$status] ?? ['name' => ucfirst($status), 'color' => 'gray', 'bg' => 'bg-gray-100', 'text' => 'text-gray-800'];
}
$status_details = getStatusDetails($laptop['status']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير الجهاز: <?= htmlspecialchars($laptop['laptop_id']) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; background-color: #F3F4F6; }
        [x-cloak] { display: none !important; }
        .report-container { max-width: 1200px; margin: auto; }
        .timeline-item:not(:last-child)::before {
            content: ''; position: absolute; top: 1.5rem; right: 0.875rem; width: 2px;
            height: calc(100% + 2rem); background-color: #E5E7EB;
        }
        .prose ul > li::before { background-color: #6B7280; }
        @media print {
            body { background-color: #FFF; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none; }
            .report-container { box-shadow: none; border: none; margin: 0; max-width: 100%; }
            .print-bg-color { background-color: var(--print-bg-color) !important; }
        }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="report-container" x-data="{ isImageViewerOpen: false, imageViewerSrc: '' }">
    
    <div class="no-print mb-6 flex justify-between items-center">
        <a href="device_lookup.php" class="text-sm text-blue-600 hover:underline">&larr; بحث عن جهاز آخر</a>
        <button @click="window.print()" class="px-6 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm7-8a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            <span>طباعة التقرير</span>
        </button>
    </div>

    <div class="bg-white p-8 sm:p-10 rounded-2xl shadow-lg border border-gray-200">
        <header class="flex justify-between items-start pb-6 border-b mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">تقرير سجل الجهاز</h1>
                <p class="text-gray-500">رقم التذكرة: <span class="font-mono font-semibold"><?= htmlspecialchars($laptop['laptop_id']) ?></span></p>
            </div>
            <div class="text-left">
                <h2 class="text-xl font-bold text-blue-600">نظام الصيانة</h2>
                <p class="text-sm text-gray-500">تاريخ التقرير: <?= date('Y-m-d') ?></p>
            </div>
        </header>

        <section class="mb-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="p-4 rounded-lg print-bg-color <?= $status_details['bg'] ?>">
                    <p class="text-sm <?= $status_details['text'] ?>">الحالة الحالية</p>
                    <p class="font-bold text-2xl <?= $status_details['text'] ?>"><?= htmlspecialchars($status_details['name']) ?></p>
                </div>
                <div class="bg-green-50 p-4 rounded-lg border border-green-200 print-bg-color" style="--print-bg-color: #F0FDF4;">
                    <p class="text-sm text-green-800">إجمالي التكاليف</p>
                    <p class="font-bold text-2xl text-green-600">$<?= number_format($total_repair_cost, 2) ?></p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg print-bg-color" style="--print-bg-color: #F9FAFB;">
                    <p class="text-sm text-gray-500">عمر التذكرة</p>
                    <p class="font-bold text-xl text-gray-800"><?= $duration_string ?></p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg print-bg-color" style="--print-bg-color: #F9FAFB;">
                    <p class="text-sm text-gray-500">تكرار المشكلة</p>
                    <p class="font-bold text-2xl text-gray-800"><?= htmlspecialchars($laptop['repeat_problem_count'] ?? 0) ?></p>
                </div>
            </div>
        </section>

        <section class="mb-8 grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div>
                <h3 class="text-xl font-bold mb-4 text-gray-800">1. معلومات الجهاز</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between p-2 bg-gray-50 rounded-md"><span>رقم الصنف:</span><strong class="font-mono"><?= htmlspecialchars($laptop['item_number']) ?></strong></div>
                    <div class="flex justify-between p-2"><span>الرقم التسلسلي:</span><strong class="font-mono"><?= htmlspecialchars($laptop['serial_number'] ?: 'N/A') ?></strong></div>
                    <div class="flex justify-between p-2 bg-gray-50 rounded-md"><span>مدخل البيانات:</span><strong><?= htmlspecialchars($laptop['entered_by']) ?></strong></div>
                    <div class="flex justify-between p-2"><span>الفني المسؤول:</span><strong><?= htmlspecialchars($laptop['assigned_to'] ?: 'لم يعين') ?></strong></div>
                    <div class="flex justify-between p-2 bg-gray-50 rounded-md"><span>الفرع:</span><strong><?= htmlspecialchars($laptop['branch_name'] ?: 'غير محدد') ?></strong></div>
                </div>
            </div>
            <div>
                <h3 class="text-xl font-bold mb-4 text-gray-800">2. المواصفات</h3>
                <div class="space-y-3 text-sm bg-gray-800 text-white p-4 rounded-lg font-mono" dir="ltr">
                    <p><?= nl2br(htmlspecialchars($laptop['specs'] ?: 'غير محدد')) ?></p>
                    <?php if(!empty($laptop['specs_difference'])): ?>
                    <div class="border-t border-gray-600 pt-2 mt-2">
                        <p class="text-yellow-400 font-sans">اختلاف المواصفات:</p>
                        <p><?= nl2br(htmlspecialchars($laptop['specs_difference'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="mb-8">
            <h3 class="text-xl font-bold mb-4 text-gray-800">3. المشاكل المسجلة عند الإدخال</h3>
            <div class="prose prose-sm max-w-none text-gray-700 bg-gray-50 p-4 rounded-md">
                <ul>
                    <?php if (empty($initial_problems)): ?>
                        <li>لا توجد مشاكل مفصلة.</li>
                    <?php else: ?>
                        <?php foreach ($initial_problems as $problem): ?>
                            <li><strong><?= htmlspecialchars($problem['title']) ?>:</strong> <?= htmlspecialchars($problem['details'] ?: 'لا توجد تفاصيل') ?></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </section>

        <section>
            <h3 class="text-xl font-bold mb-6 text-gray-800">4. السجل الزمني للأحداث</h3>
            <div class="space-y-8">
                <?php foreach(array_reverse($timeline_events) as $event): 
                    $event_styles = [
                        'operation'  => ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />', 'bg' => 'bg-blue-100', 'iconColor' => 'text-blue-600'],
                        'complaint'  => ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />', 'bg' => 'bg-red-100', 'iconColor' => 'text-red-600'],
                        'lock'       => ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />', 'bg' => 'bg-green-100', 'iconColor' => 'text-green-600'],
                        'discussion' => ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />', 'bg' => 'bg-gray-100', 'iconColor' => 'text-gray-600']
                    ];
                    $style = $event_styles[$event['type']] ?? $event_styles['discussion'];
                ?>
                <div class="flex gap-4 relative timeline-item">
                    <div class="flex-shrink-0 w-14 h-14 rounded-full flex items-center justify-center z-10 <?= $style['bg'] ?> print-bg-color" style="--print-bg-color: <?= str_replace(['bg-', '-100'], ['#', ''], $style['bg']) ?>;">
                        <svg class="w-7 h-7 <?= $style['iconColor'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $style['icon'] ?></svg>
                    </div>
                    <div class="flex-1">
                        <div class="bg-white p-4 rounded-lg border">
                            <div class="flex justify-between items-center mb-2">
                                <p class="font-bold text-gray-800"><?= htmlspecialchars($event['title']) ?></p>
                                <span class="text-xs text-gray-500"><?= date("d M, Y - H:i", strtotime($event['date'])) ?></span>
                            </div>
                            <p class="text-sm text-gray-500 mb-2">بواسطة: <span class="font-semibold"><?= htmlspecialchars($event['username']) ?></span></p>
                            <div class="prose prose-sm max-w-none text-gray-700"><?= $event['details'] ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <!-- Image Viewer Modal -->
    <div x-show="isImageViewerOpen" @keydown.escape.window="isImageViewerOpen = false" class="no-print fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 p-4" x-cloak>
        <div @click.away="isImageViewerOpen = false" class="relative">
            <img :src="imageViewerSrc" class="max-w-full max-h-[90vh] rounded-lg">
            <button @click="isImageViewerOpen = false" class="absolute -top-2 -right-2 text-white bg-gray-800 rounded-full p-1">&times;</button>
        </div>
    </div>
</div>

</body>
</html>
