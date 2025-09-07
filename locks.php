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
$laptop_id = $_GET['laptop_id'] ?? 0;
if (empty($laptop_id)) die("جهاز غير صالح");

// Only admins and managers can lock tickets
if (!in_array($_SESSION['permissions'], ['admin', 'manager'])) {
    header("Location: index.php?error=access_denied");
    exit;
}

// =================================================================================
// HANDLE AJAX FORM SUBMISSION
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'errors' => []];

    try {
        $pdo->beginTransaction();
        
        $lock_type = $_POST['lock_type'] ?? '';
        $solution_percentage = (int)($_POST['solution_percentage'] ?? 0);
        $more_description = $_POST['more_description'] ?? '';
        $final_status = $_POST['final_status'] ?? '';
        $hold_duration = $_POST['hold_duration'] ?? null;
        $hold_reason = $_POST['hold_reason'] ?? null;

        // Validation
        if (empty($lock_type) || empty($final_status)) {
            $response['errors'][] = 'يجب اختيار نوع الإقفال.';
        }
        if ($lock_type === 'مؤقت' && (empty($hold_duration) || empty($hold_reason))) {
            $response['errors'][] = 'عند الإقفال المؤقت، يجب تحديد المدة والسبب.';
        }

        if (!empty($response['errors'])) {
            throw new Exception("Validation failed");
        }

        // Insert into locks table
        $stmt = $pdo->prepare("
            INSERT INTO locks 
                (laptop_id, user_id, lock_type, solution_percentage, more_description, final_status, hold_duration, hold_reason) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$laptop_id, $user_id, $lock_type, $solution_percentage, $more_description, $final_status, $hold_duration, $hold_reason]);

        // Update status in broken_laptops table
        $update_stmt = $pdo->prepare("UPDATE broken_laptops SET status = ? WHERE laptop_id = ?");
        $update_stmt->execute([$final_status, $laptop_id]);

        $pdo->commit();
        $response['success'] = true;

    } catch (Exception $e) {
        $pdo->rollBack();
        if(empty($response['errors'])) {
            error_log('Lock Laptop Error: ' . $e->getMessage());
            $response['errors'][] = 'حدث خطأ غير متوقع في الخادم.';
        }
    }
    
    ob_end_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// =================================================================================
// FETCH DATA FOR PAGE DISPLAY
// =================================================================================
// Fetch device info
$stmt = $pdo->prepare("SELECT * FROM broken_laptops WHERE laptop_id = ?");
$stmt->execute([$laptop_id]);
$laptop = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$laptop) die("الجهاز غير موجود");

// Fetch device history summary
$operations_count = $pdo->prepare("SELECT COUNT(*) FROM operations WHERE laptop_id = ?");
$operations_count->execute([$laptop_id]);
$op_count = $operations_count->fetchColumn();

$complaints_count = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE laptop_id = ?");
$complaints_count->execute([$laptop_id]);
$comp_count = $complaints_count->fetchColumn();

// Decode problem details
$problems = [];
if (!empty($laptop['problem_details'])) {
    $decoded = json_decode($laptop['problem_details'], true);
    if (json_last_error() === JSON_ERROR_NONE) $problems = $decoded;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إغلاق تذكرة: <?= htmlspecialchars($laptop['laptop_id']) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Quill.js Rich Text Editor -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        [x-cloak] { display: none !important; }
        .ql-editor { min-height: 150px; font-size: 1rem; }
    </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4 sm:p-6 lg:p-8" x-data="lockForm()">
    
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">إغلاق تذكرة الصيانة</h1>
            <p class="text-gray-500">رقم التذكرة: <span class="font-semibold font-mono"><?= htmlspecialchars($laptop['laptop_id']) ?></span></p>
        </div>
        <a href="broken_laptops.php" class="text-sm text-blue-600 hover:underline">العودة لقائمة الأجهزة</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Right Column: Device Summary -->
        <div class="lg:col-span-1 bg-white p-6 rounded-2xl shadow-lg h-fit">
            <h2 class="text-xl font-bold mb-4 border-b pb-3">ملخص تاريخ الجهاز</h2>
            <div class="space-y-4">
                <div>
                    <p class="text-sm text-gray-500">المواصفات</p>
                    <p class="font-semibold text-gray-800"><?= htmlspecialchars($laptop['specs'] ?: 'غير محدد') ?></p>
                </div>
                <div class="flex justify-between items-center bg-gray-50 p-3 rounded-lg">
                    <div><p class="text-sm text-gray-500">عدد العمليات</p><p class="font-bold text-2xl text-blue-600"><?= $op_count ?></p></div>
                    <div><p class="text-sm text-gray-500">عدد الشكاوى</p><p class="font-bold text-2xl text-red-600"><?= $comp_count ?></p></div>
                </div>
            </div>
        </div>

        <!-- Left Column: Lock Form -->
        <div class="lg:col-span-2">
            <form @submit.prevent="submitLockForm" class="bg-white p-6 sm:p-8 rounded-2xl shadow-lg">
                <h2 class="text-xl font-bold mb-6">إعداد التقرير النهائي</h2>
                
                <div class="space-y-6">
                    <!-- Lock Type -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">اختر نوع الإقفال</label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <button type="button" @click="setLockType('repaired')" :class="{'bg-green-600 text-white': formData.final_status === 'جاهز للبيع', 'bg-gray-200 hover:bg-gray-300': formData.final_status !== 'جاهز للبيع'}" class="w-full p-3 rounded-lg font-semibold transition">نهائي: تم الإصلاح</button>
                            <button type="button" @click="setLockType('not_repaired')" :class="{'bg-red-600 text-white': formData.final_status === 'لم يتم الإصلاح', 'bg-gray-200 hover:bg-gray-300': formData.final_status !== 'لم يتم الإصلاح'}" class="w-full p-3 rounded-lg font-semibold transition">نهائي: لم يتم الإصلاح</button>
                            <button type="button" @click="setLockType('pending_parts')" :class="{'bg-yellow-500 text-white': formData.lock_type === 'مؤقت', 'bg-gray-200 hover:bg-gray-300': formData.lock_type !== 'مؤقت'}" class="w-full p-3 rounded-lg font-semibold transition">إقفال مؤقت</button>
                        </div>
                    </div>

                    <!-- Conditional Fields for Temporary Lock -->
                    <div x-show="formData.lock_type === 'مؤقت'" x-transition class="space-y-4 border-t pt-4 mt-4">
                        <div>
                            <label for="hold_duration" class="block text-sm font-medium text-gray-700 mb-1">المدة المتوقعة</label>
                            <input type="text" id="hold_duration" x-model="formData.hold_duration" placeholder="مثال: 3 أيام، أسبوع واحد" class="w-full p-3 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label for="hold_reason" class="block text-sm font-medium text-gray-700 mb-1">سبب التأجيل</label>
                            <input type="text" id="hold_reason" x-model="formData.hold_reason" placeholder="مثال: في انتظار وصول قطعة غيار" class="w-full p-3 border border-gray-300 rounded-lg">
                        </div>
                    </div>

                    <!-- Solution Percentage -->
                    <div>
                        <label for="solution_percentage" class="block text-sm font-medium text-gray-700 mb-2">نسبة حل المشكلة: <span x-text="formData.solution_percentage" class="font-bold text-blue-600"></span>%</label>
                        <input type="range" id="solution_percentage" x-model="formData.solution_percentage" min="0" max="100" class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer">
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">التقرير النهائي / شرح إضافي</label>
                        <div x-ref="quillEditor"></div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="mt-8 pt-6 border-t">
                    <button type="submit" class="w-full px-8 py-3 bg-green-600 text-white font-bold text-lg rounded-lg hover:bg-green-700 flex items-center justify-center gap-2 disabled:bg-gray-400" :disabled="isSubmitting || !formData.lock_type">
                        <span x-show="!isSubmitting">تأكيد وحفظ الإجراء</span>
                        <span x-show="isSubmitting">جاري الحفظ...</span>
                        <div x-show="isSubmitting" class="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                    </button>
                </div>
            </form>
        </div>
    </div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('lockForm', () => ({
        isSubmitting: false,
        quill: null,
        formData: {
            lock_type: '',
            solution_percentage: 100,
            final_status: '',
            more_description: '',
            hold_duration: '',
            hold_reason: ''
        },

        init() {
            this.quill = new Quill(this.$refs.quillEditor, {
                theme: 'snow',
                modules: { toolbar: [['bold', 'italic', 'underline'], [{ 'list': 'ordered'}, { 'list': 'bullet' }], ['clean']] },
                placeholder: 'اكتب تفاصيل عملية الإغلاق، الحلول التي تم تطبيقها، والملاحظات النهائية...'
            });

            this.quill.on('text-change', () => {
                this.formData.more_description = this.quill.root.innerHTML;
            });
        },

        setLockType(type) {
            if (type === 'repaired') {
                this.formData.lock_type = 'نهائي';
                this.formData.final_status = 'جاهز للبيع';
                this.formData.solution_percentage = 100;
            } else if (type === 'not_repaired') {
                this.formData.lock_type = 'نهائي';
                this.formData.final_status = 'لم يتم الإصلاح';
                this.formData.solution_percentage = 0;
            } else if (type === 'pending_parts') {
                this.formData.lock_type = 'مؤقت';
                this.formData.final_status = 'في انتظار قطع';
                this.formData.solution_percentage = 50; // Or any default
            }
        },

        submitLockForm() {
            let confirmationHtml = `سيتم تسجيل الإجراء التالي: <br><strong>${this.formData.lock_type}: ${this.formData.final_status}</strong>`;
            if (this.formData.lock_type === 'مؤقت') {
                confirmationHtml += `<br><br><strong>المدة:</strong> ${this.formData.hold_duration}<br><strong>السبب:</strong> ${this.formData.hold_reason}`;
            }

            Swal.fire({
                title: 'هل أنت متأكد؟',
                html: confirmationHtml,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'نعم، قم بالحفظ!',
                cancelButtonText: 'إلغاء'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.sendData();
                }
            });
        },
        
        sendData() {
            this.isSubmitting = true;
            
            const postData = new FormData();
            for (const key in this.formData) {
                postData.append(key, this.formData[key]);
            }

            fetch(`locks.php?laptop_id=<?= $laptop_id ?>`, {
                method: 'POST',
                body: postData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'تم بنجاح!',
                        text: 'تم تسجيل الإجراء وحفظ التقرير النهائي.',
                        confirmButtonText: 'العودة لقائمة الأجهزة'
                    }).then(() => {
                        window.location.href = 'broken_laptops.php';
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'خطأ', text: data.errors.join(', ') });
                }
            })
            .catch(error => {
                Swal.fire({ icon: 'error', title: 'خطأ فني', text: 'فشل الاتصال بالخادم.' });
                console.error('Error:', error);
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
