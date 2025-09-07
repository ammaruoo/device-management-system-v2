<?php
ob_start();
session_start();
require 'db.php';

// =================================================================================
// الحماية والصلاحيات
// =================================================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_permissions = $_SESSION['permissions'];
if (!in_array($user_permissions, ['technician', 'admin', 'manager', 'storekeeper', 'sales', 'prep_engineer'])) {
    header("Location: index.php?error=access_denied");
    exit;
}

// =================================================================================
// إنشاء الفئة الافتراضية "لابتوبات" إذا لم تكن موجودة
// =================================================================================
$default_category_name = 'لابتوبات';
$stmt_cat = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = ?");
$stmt_cat->execute([$default_category_name]);
$default_category_id = $stmt_cat->fetchColumn();
if (!$default_category_id) {
    $pdo->prepare("INSERT INTO categories (category_name) VALUES (?)")->execute([$default_category_name]);
    $default_category_id = $pdo->lastInsertId();
}

// =================================================================================
// التوقع المبدئي للـ laptop_id القادم (يستخدم فقط للعرض)
// =================================================================================
$stmt_status = $pdo->query("SHOW TABLE STATUS LIKE 'broken_laptops'");
$table_status = $stmt_status->fetch(PDO::FETCH_ASSOC);
$next_laptop_id = $table_status['Auto_increment'];
$predicted_ticket_number = $next_laptop_id;

// =================================================================================
// تحميل البيانات المبدئية للنموذج
// =================================================================================
$users_query = $pdo->query("SELECT user_id, username, permissions FROM users WHERE permissions IN ('technician', 'admin', 'manager') ORDER BY username");
$assignable_users = $users_query->fetchAll();

$branches_query = $pdo->query("SELECT branch_id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name ASC");
$branche1 = $branches_query->fetchAll(PDO::FETCH_ASSOC);

$item_numbers_query = $pdo->query("SELECT item_number FROM item_specifications ORDER BY item_number");
$existing_item_numbers = $item_numbers_query->fetchAll(PDO::FETCH_COLUMN);

$problem_types = ['كيبورد','هاردوير', 'سوفتوير', 'بطارية', 'تغير قطعه', 'حراره', 'لا اعلم'];
$problem_natures = ['سهلة مستعجلة', ' سهلة عادية', 'صعبة مستعجلة', 'صعبة  عادية', ' طارئ'];

// =================================================================================
// دالة مساعدة لتطبيع الرقم التسلسلي (تحويل الأرقام العربية إلى إنجليزية)
// =================================================================================
if (!function_exists('normalize_serial')) {
    function normalize_serial($s) {
        if ($s === null) return null;
        $s = preg_replace_callback('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', function($m){
            $code = mb_ord($m[0], 'UTF-8');
            if ($code >= 0x0660 && $code <= 0x0669) return chr($code - 0x0660 + 48);
            if ($code >= 0x06F0 && $code <= 0x06F9) return chr($code - 0x06F0 + 48);
            return $m[0];
        }, $s);
        $s = preg_replace_callback('/[a-z]/i', function($m){ return strtoupper($m[0]); }, $s);
        return $s;
    }
}

// =================================================================================
// عند إرسال النموذج (إضافة جهاز جديد)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'errors' => [], 'laptop_id' => null, 'ticket_display_number' => null];
    
    try {
        $pdo->beginTransaction();

        // التحقق من الحقول المطلوبة
        $employee_name = $_SESSION['username']; 
        $item_number = trim($_POST['item_number']) ?: null;
        if (empty($item_number)) $response['errors'][] = "رقم الصنف مطلوب.";

        $serial_number = null;
        if (isset($_POST['serial_number'])) {
            $tmp = trim($_POST['serial_number']);
            $serial_number = $tmp !== '' ? normalize_serial($tmp) : null;
        }

        $specs = trim($_POST['specs']) ?: null;
        if (empty($specs)) $response['errors'][] = "المواصفات حقل مطلوب.";

        $problems = isset($_POST['problems']) ? $_POST['problems'] : [];
        if (empty($problems) || !is_array($problems)) $response['errors'][] = "يجب إضافة مشكلة واحدة على الأقل.";
        $problem_details_json = json_encode($problems, JSON_UNESCAPED_UNICODE);

        // التعامل مع الفرع (إما اختيار موجود أو إضافة جديد)
        $branch_name = null;
        if (isset($_POST['branch_name'])) {
            if ($_POST['branch_name'] === '__other__') {
                $branch_name = trim($_POST['branch_new'] ?? '') ?: null;
            } else {
                $branch_name = trim($_POST['branch_name']) ?: null;
            }
        }

        $problem_nature = isset($_POST['problem_nature']) ? trim($_POST['problem_nature']) : null;

        if (!empty($response['errors'])) throw new Exception("Validation failed");

        $assigned_user_id = null;
        if (in_array($user_permissions, ['admin', 'manager'])) {
            $assigned_user_id = empty($_POST['assigned_user_id']) ? null : (int)$_POST['assigned_user_id'];
        }
        
        $warehouse_id = isset($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null;
        $repeat_problem_count = isset($_POST['repeat_problem_count']) ? (int)$_POST['repeat_problem_count'] : 0;
        
        // إدخال الجهاز الجديد
        $sql = "INSERT INTO broken_laptops (
                    employee_name, item_number, category_id, device_category_number, serial_number, specs, 
                    with_charger, branch_name, problem_details, entered_by_user_id, 
                    assigned_user_id, problem_type, problem_nature, status, repeat_problem_count, warehouse_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $employee_name, $item_number, (int)$default_category_id,
            null, $serial_number, $specs,
            isset($_POST['with_charger']) ? 1 : 0, $branch_name,
            $problem_details_json, (int)$_SESSION['user_id'], $assigned_user_id,
            trim($_POST['problem_type']) ?: null, $problem_nature,
            'entered', $repeat_problem_count, $warehouse_id
        ]);

        // الحصول على رقم الجهاز (Auto Increment)
        $laptop_id = $pdo->lastInsertId();

        // جعل ticket_number نفس قيمة laptop_id
        $ticket_number = $laptop_id;
        $update = $pdo->prepare("UPDATE broken_laptops SET ticket_number = ? WHERE laptop_id = ?");
        $update->execute([$ticket_number, $laptop_id]);

        $response['laptop_id'] = $laptop_id;
        $response['ticket_display_number'] = $ticket_number;

        // إضافة شكوى أولية
        $first_problem = ($problems[0]['title'] ?? 'N/A') . ' - ' . ($problems[0]['details'] ?? '');
        $cstm = $pdo->prepare("INSERT INTO complaints (laptop_id, problem_title, problem_details, user_id) VALUES (?, ?, ?, ?)");
        $cstm->execute([$laptop_id, 'مشكلة أولية عند الإدخال', $first_problem, (int)$_SESSION['user_id']]);

        // إضافة سجل عملية
        $log = $pdo->prepare("INSERT INTO operations (laptop_id, user_id, repair_result, details) VALUES (?, ?, ?, ?)");
        $log->execute([$laptop_id, (int)$_SESSION['user_id'], 'تم إدخال الجهاز للنظام', 'تم إنشاء تذكرة صيانة برقم ' . $ticket_number]);
        
        // إرسال إشعار للفني إذا تم تعيينه
        if ($assigned_user_id) {
            $notification_message = "تم تعيين جهاز جديد لك (" . htmlspecialchars($serial_number) . ") برقم تذكرة " . $ticket_number;
            $notification_link = "laptop_chat.php?laptop_id=" . $laptop_id;
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
            $notif_stmt->execute([$assigned_user_id, $notification_message, $notification_link]);
        }

        $pdo->commit();
        $response['success'] = true;

    } catch (Exception $e) {
        $pdo->rollBack();
        if(empty($response['errors'])) {
            error_log('Add Laptop Error: ' . $e->getMessage());
            $response['errors'][] = 'حدث خطأ غير متوقع في الخادم.';
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة تذكرة صيانة جديدة</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link href="https://unpkg.com/filepond/dist/filepond.css" rel="stylesheet">
    <link href="https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css"/>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        .choices__input { background-color: transparent !important; }
        [x-cloak] { display: none !important; }
        .step-indicator { transition: all 0.3s ease-in-out; }
        
        /* تنسيق نافذة الكاميرا */
        .qr-scanner-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .qr-scanner-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            max-width: 500px;
            width: 90%;
            text-align: center;
        }
        
        #qr-reader {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }
        
        .qr-close-btn {
            background: #ef4444;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            margin-top: 15px;
            cursor: pointer;
            font-family: 'Cairo', sans-serif;
        }
        
        .qr-close-btn:hover {
            background: #dc2626;
        }
    </style>
</head>
<body class="bg-gray-50">

    <div x-data="ticketForm()" x-cloak class="container mx-auto p-4 sm:p-6 lg:p-8 max-w-5xl">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">إنشاء تذكرة صيانة جديدة</h1>
                <p class="text-gray-500">سيتم توليد رقم تذكرة فريد بعد الحفظ.</p>
            </div>
            <a href="index.php" class="text-sm text-blue-600 hover:underline">العودة للرئيسية</a>
        </div>

        <div class="flex items-center justify-between mb-8 p-2 bg-gray-100 rounded-lg">
            <template x-for="(step, index) in steps" :key="index">
                <div class="flex-1 text-center">
                    <button @click="currentStep = index + 1" :disabled="index >= currentStep" 
                            class="step-indicator w-full py-2 px-4 rounded-md text-sm font-semibold"
                            :class="{
                                'bg-blue-500 text-white shadow': currentStep === index + 1,
                                'bg-white text-blue-500 hover:bg-blue-50': currentStep > index + 1,
                                'bg-transparent text-gray-400 cursor-not-allowed': currentStep < index + 1
                            }">
                        <span x-text="step"></span>
                    </button>
                </div>
            </template>
        </div>

        <!-- نافذة مسح QR -->
        <div x-show="showScanner" class="qr-scanner-modal" x-cloak>
            <div class="qr-scanner-container">
                <h3 class="text-xl font-bold mb-4">مسح رمز QR</h3>
                <p class="text-gray-600 mb-4">وجه الكاميرا نحو رمز QR لقراءته</p>
                <div id="qr-reader"></div>
                <button @click="closeQRScanner()" class="qr-close-btn">إغلاق الكاميرا</button>
            </div>
        </div>

        <form @submit.prevent="submitForm" id="add-ticket-form" method="POST" enctype="multipart/form-data" class="bg-white p-6 sm:p-8 rounded-2xl shadow-lg">
            
            <div class="mb-6 bg-yellow-50 border-l-4 border-yellow-500 text-yellow-800 p-4 rounded-r-lg">
                <p class="font-bold">رقم تذكرة الصيانة:<?= htmlspecialchars($predicted_ticket_number) ?></p>
                <p class="font-bold text-2xl tracking-widest"></p>
                <p class="text-xs mt-1">هذا رقم يتم كتابته فوق الجهاز.</p>
            </div>
            
            <!-- ======================= STEP 1: ITEM & DEVICE INFO ======================= -->
            <section x-show="currentStep === 1">
                <h2 class="text-xl font-bold mb-4 border-b pb-2">1. بيانات الجهاز الأساسية</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="item_number" class="block text-sm font-medium text-gray-700 mb-1">رقم الصنف</label>
                        <div class="flex gap-2 items-center">
                            <input type="text" id="item_number" name="item_number" x-model="device.item_number" @blur="fetchSpecs()" list="item_numbers" placeholder="أدخل رقم الصنف لجلب المواصفات" required class="flex-1 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <button type="button" @click="openQRScanner()" title="مسح QR" class="p-2 bg-blue-500 text-white rounded-md hover:bg-blue-600" style="min-width:44px;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h4M3 11h4M3 15h4M17 7h4M17 11h4M17 15h4M7 3v4M11 3v4M7 17v4M11 17v4M13 7h-2v2h2V7z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label for="serial_number" class="block text-sm font-medium text-gray-700 mb-1"> (S/N)</label>
                        <div class="flex gap-2 items-center">
                            <input type="text" id="serial_number" name="serial_number" class="flex-1 p-3 border border-gray-300 rounded-lg">
                            <button type="button" @click="openSerialQRScanner()" title="مسح QR للرقم التسلسلي" class="p-2 bg-blue-500 text-white rounded-md hover:bg-blue-600" style="min-width:44px;">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h4M3 11h4M3 15h4M17 7h4M17 11h4M17 15h4M7 3v4M11 3v4M7 17v4M11 17v4M13 7h-2v2h2V7z"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label for="specs" class="block text-sm font-medium text-gray-700 mb-1">المواصفات</label>
                        <textarea id="specs" name="specs" x-model="device.specs" rows="3" placeholder="سيتم ملء هذا الحقل تلقائياً بعد إدخال رقم الصنف..." class="w-full p-3 border border-gray-300 rounded-lg bg-gray-100 cursor-not-allowed text-left" required readonly style="direction: ltr;"></textarea>
                    </div>
                </div>
            </section>

            <!-- ======================= STEP 2: PROBLEM DESCRIPTION ======================= -->
            <section x-show="currentStep === 2" style="display: none;">
                <h2 class="text-xl font-bold mb-4 border-b pb-2">2. وصف المشاكل</h2>
                <div id="problems-container" class="space-y-4">
                    <template x-for="(problem, index) in problems" :key="index">
                        <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                            <div class="flex justify-between items-center mb-3">
                                <h3 class="font-semibold text-gray-700" x-text="'المشكلة ' + (index + 1)"></h3>
                                <button type="button" @click="removeProblem(index)" x-show="problems.length > 1" class="text-red-500 hover:text-red-700 text-sm">حذف</button>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">عنوان المشكلة</label>
                                    <input type="text" x-model="problem.title" :name="'problems[' + index + '][title]'" placeholder="مثال: لا يعمل الجهاز" required class="w-full p-3 border border-gray-300 rounded-lg">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">تفاصيل المشكلة</label>
                                    <textarea x-model="problem.details" :name="'problems[' + index + '][details]'" rows="2" placeholder="وصف تفصيلي للمشكلة..." class="w-full p-3 border border-gray-300 rounded-lg"></textarea>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <button type="button" @click="addProblem()" class="mt-4 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">إضافة مشكلة أخرى</button>
            </section>

            <!-- ======================= STEP 3: ADDITIONAL INFO ======================= -->
            <section x-show="currentStep === 3" style="display: none;">
                <h2 class="text-xl font-bold mb-4 border-b pb-2">3. معلومات إضافية</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="problem_type" class="block text-sm font-medium text-gray-700 mb-1">نوع المشكلة</label>
                        <select id="problem_type" name="problem_type" class="w-full p-3 border border-gray-300 rounded-lg">
                            <option value="">اختر نوع المشكلة</option>
                            <?php foreach ($problem_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="problem_nature" class="block text-sm font-medium text-gray-700 mb-1">طبيعة المشكلة</label>
                        <select id="problem_nature" name="problem_nature" class="w-full p-3 border border-gray-300 rounded-lg">
                            <option value="">اختر طبيعة المشكلة</option>
                            <?php foreach ($problem_natures as $nature): ?>
                                <option value="<?= htmlspecialchars($nature) ?>"><?= htmlspecialchars($nature) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="warehouse_id" class="block text-sm font-medium text-gray-700 mb-1">المخزن المقصود</label>
                        <select id="warehouse_id" name="warehouse_id" class="w-full p-3 border border-gray-300 rounded-lg">
                            <option value="">اختر المخزن</option>
                        </select>
                    </div>
                    <div>
                        <label for="repeat_problem_count" class="block text-sm font-medium text-gray-700 mb-1">عدد مرات تكرار المشكلة</label>
                        <input type="number" id="repeat_problem_count" name="repeat_problem_count" min="0" value="0" class="w-full p-3 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label for="branch_name" class="block text-sm font-medium text-gray-700 mb-1">الفرع</label>
                        <select id="branch_name" name="branch_name" x-model="branchName" @change="loadWarehousesForBranch()" class="w-full p-3 border border-gray-300 rounded-lg">
                            <option value="">اختر الفرع</option>
                            <?php foreach ($branche1 as $branch): ?>
                              <option value="<?= htmlspecialchars($branch['branch_name']) ?>" data-branch-id="<?= $branch['branch_id'] ?>"><?= htmlspecialchars($branch['branch_name']) ?></option>
                            <?php endforeach; ?>
                            <!--<option value="__other__">فرع جديد</option>-->
                        </select>
                    </div>
                    <div id="new_branch_container" style="display: none;">
                        <label for="branch_new" class="block text-sm font-medium text-gray-700 mb-1">اسم الفرع الجديد</label>
                        <input type="text" id="branch_new" name="branch_new" placeholder="أدخل اسم الفرع الجديد" class="w-full p-3 border border-gray-300 rounded-lg">
                    </div>
                    <div class="md:col-span-2">
                        <label class="flex items-center">
                            <input type="checkbox" name="with_charger" class="mr-2">
                            <span class="text-sm font-medium text-gray-700">مع الشاحن</span>
                        </label>
                    </div>
                    <?php if (in_array($user_permissions, ['admin', 'manager'])): ?>
                    <!--<div class="md:col-span-2">-->
                    <!--    <label for="assigned_user_id" class="block text-sm font-medium text-gray-700 mb-1">تعيين للفني</label>-->
                    <!--    <select id="assigned_user_id" name="assigned_user_id" class="w-full p-3 border border-gray-300 rounded-lg">-->
                    <!--        <option value="">لا يوجد تعيين</option>-->
                    <!--        <?php foreach ($assignable_users as $user): ?>-->
                    <!--            <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['permissions']) ?>)</option>-->
                    <!--        <?php endforeach; ?>-->
                    <!--    </select>-->
                    <!--</div>-->
                    <?php endif; ?>
                </div>
            </section>

            <!-- Navigation buttons -->
            <div class="flex justify-between mt-8">
                <button type="button" @click="prevStep()" x-show="currentStep > 1" class="px-6 py-3 bg-gray-500 text-white rounded-lg hover:bg-gray-600">السابق</button>
                <button type="button" @click="nextStep()" x-show="currentStep < steps.length" class="px-6 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600">التالي</button>
                <button type="submit" x-show="currentStep === steps.length" :disabled="isSubmitting" class="px-6 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50">
                    <span x-show="!isSubmitting">حفظ التذكرة</span>
                    <span x-show="isSubmitting">جاري الحفظ...</span>
                </button>
            </div>
        </form>

        <!-- Hidden datalist for item numbers -->
        <datalist id="item_numbers">
            <?php foreach ($existing_item_numbers as $item_number): ?>
                <option value="<?= htmlspecialchars($item_number) ?>">
            <?php endforeach; ?>
        </datalist>
    </div>

    <script src="https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.js"></script>
    <script src="https://unpkg.com/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.js"></script>
    <script src="https://unpkg.com/filepond/dist/filepond.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
    
    <script>
        // Normalize Arabic-Indic digits to ASCII and uppercase Latin letters in JS
        function normalizeSerialJS(s) {
            if (!s) return s;
            // Replace Arabic-Indic (0660-0669) and Eastern Arabic-Indic (06F0-06F9)
            s = s.replace(/[\u0660-\u0669]/g, function(ch){ return String.fromCharCode(48 + ch.charCodeAt(0) - 0x0660); });
            s = s.replace(/[\u06F0-\u06F9]/g, function(ch){ return String.fromCharCode(48 + ch.charCodeAt(0) - 0x06F0); });
            // Uppercase English letters
            s = s.replace(/[a-z]/g, function(ch){ return ch.toUpperCase(); });
            return s;
        }

        document.addEventListener('DOMContentLoaded', function(){
            var el = document.getElementById('serial_number');
            if (el) {
                el.addEventListener('input', function(e){
                    var pos = el.selectionStart;
                    el.value = normalizeSerialJS(el.value);
                    try { el.setSelectionRange(pos, pos); } catch(e){}
                });
                // normalize once on page load
                el.value = normalizeSerialJS(el.value);
                // ensure normalization before form submit
                var form = el.form;
                if (form) form.addEventListener('submit', function(){ el.value = normalizeSerialJS(el.value); });
            }
            
            // Toggle new branch input
            document.getElementById('branch_name').addEventListener('change', function() {
                if (this.value === '__other__') {
                    document.getElementById('new_branch_container').style.display = 'block';
                } else {
                    document.getElementById('new_branch_container').style.display = 'none';
                }
            });
        });

        document.addEventListener('alpine:init', () => {
            Alpine.data('ticketForm', () => ({
                // QR scanner state
                showScanner: false,
                qrScannerInstance: null,
                currentStep: 1,
                steps: ['بيانات الجهاز', 'وصف المشاكل', 'المرفقات والتعيين'],
                isSubmitting: false,
                device: {
                    item_number: '',
                    specs: ''
                },
                problems: [{ title: '', details: '' }],
                branchName: '',
                assigneeSelect: null,
                init() {
                    if (this.$refs.assigneeSelect) {
                        this.assigneeSelect = new Choices(this.$refs.assigneeSelect, { searchEnabled: true, itemSelectText: 'اختر', placeholder: true, placeholderValue: '-- بحث عن فني --' });
                    }
                    FilePond.registerPlugin(FilePondPluginImagePreview, FilePondPluginFileValidateSize);
                    FilePond.create(document.querySelector('.filepond'), { labelIdle: `اسحب وأفلت الملفات هنا أو <span class="filepond--label-action">تصفح</span>`, credits: false });
                    
                    // تحميل المخازن المتاحة
                    this.loadWarehouses();
                },
                
                loadWarehouses() {
                    // لا نحمل المخازن تلقائياً، بل نحملها عند اختيار الفرع
                    const warehouseSelect = document.getElementById('warehouse_id');
                    warehouseSelect.innerHTML = '<option value="">اختر الفرع أولاً</option>';
                },
                
                loadWarehousesForBranch() {
                    const branchSelect = document.getElementById('branch_name');
                    const warehouseSelect = document.getElementById('warehouse_id');
                    
                    if (!branchSelect.value) {
                        warehouseSelect.innerHTML = '<option value="">اختر الفرع أولاً</option>';
                        return;
                    }
                    
                    // الحصول على branch_id من الخيار المحدد
                    const selectedOption = branchSelect.options[branchSelect.selectedIndex];
                    const branchId = selectedOption.getAttribute('data-branch-id');
                    
                    if (!branchId) return;
                    
                    // تنظيف قائمة المخازن وإظهار رسالة تحميل
                    warehouseSelect.innerHTML = '<option value="">جاري تحميل المخازن...</option>';
                    
                    // جلب المخازن للفرع المحدد
                    fetch(`warehouse_api.php?action=get_warehouse_by_branch&branch_id=${branchId}`)
                        .then(response => response.json())
                        .then(data => {
                            warehouseSelect.innerHTML = '<option value="">اختر المخزن</option>';
                            
                            if (data.success && data.warehouses.length > 0) {
                                data.warehouses.forEach(warehouse => {
                                    const option = document.createElement('option');
                                    option.value = warehouse.warehouse_id;
                                    option.textContent = warehouse.warehouse_name;
                                    warehouseSelect.appendChild(option);
                                });
                            } else {
                                warehouseSelect.innerHTML = '<option value="">لا توجد مخازن متاحة</option>';
                            }
                        })
                        .catch(error => {
                            console.error('خطأ في تحميل المخازن:', error);
                            warehouseSelect.innerHTML = '<option value="">خطأ في تحميل المخازن</option>';
                        });
                },
                nextStep() { if (this.currentStep < this.steps.length) this.currentStep++; },
                prevStep() { if (this.currentStep > 1) this.currentStep--; },
                fetchSpecs() {
                    if (!this.device.item_number) return;
                    const itemNum = this.device.item_number.trim();
                    fetch(`api_handler.php?action=get_specs&item_number=${encodeURIComponent(itemNum)}`)
                        .then(res => res.json())
                        .then(data => {
                            if (data && data.specs) {
                                this.device.specs = data.specs;
                            } else {
                                this.device.specs = ''; // Clear if not found
                                // Ask user to add specs for this item number
                                Swal.fire({
                                    title: 'لم يتم العثور على رقم الصنف',
                                    html: `<p>رقم الصنف <strong>${itemNum}</strong> غير موجود. هل تريد إضافة مواصفات لهذا الرقم الآن؟</p>`,
                                    showCancelButton: true,
                                    confirmButtonText: 'إضافة مواصفات',
                                    cancelButtonText: 'إلغاء',
                                    input: 'textarea',
                                    inputPlaceholder: 'أدخل مواصفات الصنف هنا...',
                                    inputAttributes: { 'aria-label': 'مواصفات الصنف' },
                                    preConfirm: (specs) => {
                                        if (!specs || specs.trim() === '') {
                                            Swal.showValidationMessage('المواصفات لا يمكن أن تكون فارغة');
                                            return false;
                                        }
                                        return specs.trim();
                                    }
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        const specsText = result.value;
                                        // send to server to save the spec
                                        const fd = new FormData();
                                        fd.append('item_number', itemNum);
                                        fd.append('specs', specsText);
                                        fetch('api_handler.php?action=add_spec', { method: 'POST', body: fd })
                                            .then(r => r.json())
                                            .then(resp => {
                                                if (resp && resp.success) {
                                                    Swal.fire({ icon: 'success', title: 'تم الحفظ', text: 'تم إضافة مواصفات الصنف بنجاح.' });
                                                    this.device.specs = specsText;
                                                    // add new item number to datalist so it is available next time without reload
                                                    try {
                                                        const dl = document.getElementById('item_numbers');
                                                        if (dl) {
                                                            const exists = Array.from(dl.options).some(o => o.value === itemNum);
                                                            if (!exists) {
                                                                const opt = document.createElement('option');
                                                                opt.value = itemNum;
                                                                dl.appendChild(opt);
                                                            }
                                                        }
                                                    } catch (e) { console.warn('Failed to update datalist:', e); }
                                                    // ensure item_number normalized/trimmed
                                                    this.device.item_number = itemNum;
                                                    // move to next step automatically
                                                    if (typeof this.nextStep === 'function') this.nextStep();
                                                } else {
                                                    Swal.fire({ icon: 'error', title: 'فشل الحفظ', text: (resp.message || 'تعذر حفظ المواصفات.') });
                                                }
                                            })
                                            .catch(err => {
                                                console.error('Add spec error:', err);
                                                Swal.fire({ icon: 'error', title: 'خطأ', text: 'تعذر الاتصال بالخادم.' });
                                            });
                                    }
                                });
                            }
                        }).catch(err => {
                            console.error('get_specs error:', err);
                        });
                },
                addProblem() { this.problems.push({ title: '', details: '' }); },
                removeProblem(index) { this.problems.splice(index, 1); },
                // دالة toggleNewBranchInput لم تعد مطلوبة لأننا نستخدم الفروع من قاعدة البيانات
               
                openQRScanner() {
                    // إظهار النافذة المنبثقة ثم تشغيل الكاميرا
                    this.showScanner = true;
                    this.$nextTick(() => {
                        try {
                            if (this.qrScannerInstance) return; // تم التشغيل بالفعل
                            
                            const config = { 
                                fps: 10, 
                                qrbox: { width: 250, height: 250 },
                                aspectRatio: 1.0
                            };
                            
                            this.qrScannerInstance = new Html5Qrcode("qr-reader");
                            
                            // الحصول على قائمة الكاميرات المتاحة
                            Html5Qrcode.getCameras().then(cameras => {
                                if (cameras && cameras.length) {
                                    // استخدام الكاميرا الخلفية إذا كانت متاحة
                                    let cameraId = cameras[0].id;
                                    for (let camera of cameras) {
                                        if (camera.label.toLowerCase().includes('back') || 
                                            camera.label.toLowerCase().includes('rear')) {
                                            cameraId = camera.id;
                                            break;
                                        }
                                    }
                                    
                                    // تشغيل الكاميرا
                                    this.qrScannerInstance.start(
                                        cameraId,
                                        config,
                                        (decodedText, decodedResult) => {
                                            // عند قراءة QR بنجاح، ملء الحقل وإيقاف الكاميرا
                                            console.log('تم قراءة QR:', decodedText);
                                            
                                            // ملء حقل رقم الصنف
                                            this.device.item_number = decodedText.trim();
                                            
                                            // إغلاق ماسح QR
                                            this.closeQRScanner();
                                            
                                            // جلب المواصفات
                                            this.fetchSpecs();
                                            
                                            // نقل التركيز إلى حقل الرقم التسلسلي
                                            this.$nextTick(() => {
                                                const serialField = document.getElementById('serial_number');
                                                if (serialField) {
                                                    serialField.focus();
                                                }
                                            });
                                            
                                            // إظهار رسالة نجاح
                                            Swal.fire({
                                                icon: 'success',
                                                title: 'تم قراءة الرمز بنجاح',
                                                text: 'تم إدخال رقم الصنف: ' + decodedText.trim(),
                                                timer: 2000,
                                                showConfirmButton: false
                                            });
                                        },
                                        (errorMessage) => {
                                            // تجاهل أخطاء المسح العادية (عدم وجود QR في الإطار)
                                            // console.log('خطأ في المسح:', errorMessage);
                                        }
                                    ).catch(err => {
                                        console.error('خطأ في تشغيل QR:', err);
                                        this.closeQRScanner();
                                        Swal.fire({ 
                                            icon: 'error', 
                                            title: 'خطأ في الكاميرا', 
                                            text: 'تعذر تشغيل الكاميرا. تحقق من أذونات المتصفح.' 
                                        });
                                    });
                                } else {
                                    this.closeQRScanner();
                                    Swal.fire({ 
                                        icon: 'error', 
                                        title: 'لا توجد كاميرا', 
                                        text: 'لم يتم العثور على كاميرا متاحة.' 
                                    });
                                }
                            }).catch(err => {
                                console.error('خطأ في الحصول على الكاميرات:', err);
                                this.closeQRScanner();
                                Swal.fire({ 
                                    icon: 'error', 
                                    title: 'خطأ في الوصول للكاميرا', 
                                    text: 'تعذر الوصول إلى الكاميرا. تأكد من منح الأذونات اللازمة.' 
                                });
                            });
                        } catch (e) { 
                            console.error('خطأ عام في QR:', e);
                            this.closeQRScanner();
                            Swal.fire({ 
                                icon: 'error', 
                                title: 'خطأ تقني', 
                                text: 'حدث خطأ تقني في تشغيل ماسح QR.' 
                            });
                        }
                    });
                },

                closeQRScanner() {
                    try {
                        if (this.qrScannerInstance) {
                            this.qrScannerInstance.stop().then(() => {
                                this.qrScannerInstance.clear();
                                this.qrScannerInstance = null;
                                console.log('تم إيقاف ماسح QR بنجاح');
                            }).catch(e => { 
                                console.warn('خطأ في إيقاف QR:', e); 
                                this.qrScannerInstance = null; 
                            });
                        }
                    } catch (e) { 
                        console.warn('خطأ في إغلاق QR:', e); 
                        this.qrScannerInstance = null;
                    }
                    this.showScanner = false;
                },
                
                openSerialQRScanner() {
                    this.showScanner = true;
                    this.$nextTick(() => {
                        try {
                            if (this.qrScannerInstance) return;

                            const config = { fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 };
                            this.qrScannerInstance = new Html5Qrcode("qr-reader");

                            Html5Qrcode.getCameras().then(cameras => {
                                if (cameras && cameras.length) {
                                    let cameraId = cameras[0].id;
                                    for (let camera of cameras) {
                                        if (camera.label.toLowerCase().includes('back') || camera.label.toLowerCase().includes('rear')) {
                                            cameraId = camera.id;
                                            break;
                                        }
                                    }
                                    this.qrScannerInstance.start(cameraId, config, (decodedText) => {
                                        console.log('تم قراءة QR للرقم التسلسلي:', decodedText);
                                        // ملء حقل الرقم التسلسلي
                                        const serialField = document.getElementById('serial_number');
                                        if (serialField) serialField.value = normalizeSerialJS(decodedText.trim());
                                        this.closeQRScanner();
                                        Swal.fire({
                                            icon: 'success',
                                            title: 'تم قراءة الرمز التسلسلي بنجاح',
                                            text: 'تم إدخال الرقم التسلسلي: ' + decodedText.trim(),
                                            timer: 2000,
                                            showConfirmButton: false
                                        });
                                    }, (errorMessage) => { /* تجاهل أخطاء المسح العادية */ })
                                    .catch(err => { console.error(err); this.closeQRScanner(); });
                                } else { this.closeQRScanner(); Swal.fire({icon:'error', title:'لا توجد كاميرا', text:'لم يتم العثور على كاميرا'}); }
                            }).catch(err => { console.error(err); this.closeQRScanner(); });
                        } catch (e) { console.error(e); this.closeQRScanner(); }
                    });
                },
                
                submitForm() {
                    this.isSubmitting = true;
                    const formData = new FormData(document.getElementById('add-ticket-form'));
                    
                    // إضافة جميع الحقول يدوياً للتأكد من تضمينها جميعاً
                    formData.append('problem_nature', document.getElementById('problem_nature').value);
                    formData.append('warehouse_id', document.getElementById('warehouse_id').value);
                    formData.append('repeat_problem_count', document.getElementById('repeat_problem_count').value);
                    
                    // إضافة المشاكل يدوياً
                    this.problems.forEach((problem, index) => {
                        formData.append(`problems[${index}][title]`, problem.title);
                        formData.append(`problems[${index}][details]`, problem.details);
                    });
                    
                    fetch('add_broken_laptop.php', { method: 'POST', body: formData })
                    .then(response => response.text())
                    .then(text => {
                        try {
                            const data = JSON.parse(text);
                            if (data.success) {
                                Swal.fire({
                                    icon: 'success', 
                                    title: 'تم الحفظ بنجاح!', 
                                    html: `تم إنشاء تذكرة الصيانة بنجاح. <br><br> <strong>رقم التذكرة: ${data.ticket_display_number}</strong>`,
                                    showCancelButton: true, 
                                    confirmButtonText: 'عرض التذكرة', 
                                    cancelButtonText: 'إضافة تذكرة أخرى',
                                    confirmButtonColor: '#3B82F6', 
                                    cancelButtonColor: '#10B981',
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        window.location.href = `laptop_chat.php?laptop_id=${data.laptop_id}`;
                                    } else {
                                        window.location.reload();
                                    }
                                });
                            } else {
                                Swal.fire({ icon: 'error', title: 'خطأ في الإدخال', html: data.errors.join('<br>') });
                            }
                        } catch (e) {
                            console.error("Failed to parse JSON:", e);
                            console.error("Server response:", text);
                            Swal.fire({
                                icon: 'error', title: 'خطأ فني',
                                html: `حدث خطأ غير متوقع من الخادم. <br><br><b>استجابة الخادم:</b><pre class="text-left text-xs bg-gray-100 p-2 rounded">${text.replace(/</g, "&lt;").replace(/>/g, "&gt;")}</pre>`
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({ icon: 'error', title: 'خطأ فني', text: 'فشل الاتصال بالخادم: ' + error.message });
                        console.error('Fetch Error:', error);
                    })
                    .finally(() => {
                        this.isSubmitting = false;
                    });
                }
            }));
        });
    </script>
</body>
</html>