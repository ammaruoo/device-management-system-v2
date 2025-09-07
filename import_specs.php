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
// Only admins and managers can import specs
if (!in_array($_SESSION['permissions'], ['admin', 'manager'])) {
    header("Location: index.php?error=access_denied");
    exit;
}

// =================================================================================
// HANDLE FILE UPLOAD AND IMPORT
// =================================================================================
$upload_feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['specs_file'])) {
    $file = $_FILES['specs_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_feedback = ['success' => false, 'message' => 'حدث خطأ أثناء رفع الملف.'];
    } else {
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'csv') {
            $upload_feedback = ['success' => false, 'message' => 'خطأ: الملف يجب أن يكون بصيغة CSV.'];
        } else {
            $file_path = $file['tmp_name'];
            
            // **THE DEFINITIVE FIX: Handle file encoding issues from Excel**
            // Read the file content
            $file_content = file_get_contents($file_path);
            // Convert the content from Windows-1256 (common for Arabic Excel) to UTF-8
            $utf8_content = mb_convert_encoding($file_content, 'UTF-8', 'Windows-1256');
            // Create a temporary in-memory stream to read the converted content
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $utf8_content);
            rewind($stream);

            if ($stream !== FALSE) {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO item_specifications (item_number, specs) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE specs = VALUES(specs)
                    ");

                    $added_count = 0;
                    $updated_count = 0;
                    
                    // Skip the header row
                    fgetcsv($stream, 1000, ","); 

                    while (($data = fgetcsv($stream, 1000, ",")) !== FALSE) {
                        if (count($data) >= 2) {
                            $item_number = trim($data[0]);
                            $specs = trim($data[1]);

                            if (!empty($item_number) && !empty($specs)) {
                                $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM item_specifications WHERE item_number = ?");
                                $check_stmt->execute([$item_number]);
                                $exists = $check_stmt->fetchColumn() > 0;

                                $stmt->execute([$item_number, $specs]);

                                if ($exists) {
                                    $updated_count++;
                                } else {
                                    $added_count++;
                                }
                            }
                        }
                    }
                    fclose($stream);
                    $pdo->commit();
                    $upload_feedback = ['success' => true, 'message' => "تمت العملية بنجاح! أضيف: $added_count صنف. تم تحديث: $updated_count صنف."];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $upload_feedback = ['success' => false, 'message' => 'فشل في معالجة الملف: ' . $e->getMessage()];
                }
            } else {
                $upload_feedback = ['success' => false, 'message' => 'فشل في فتح الملف المرفوع.'];
            }
        }
    }
}

// =================================================================================
// FETCH CURRENT SPECS FOR DISPLAY
// =================================================================================
$current_specs_query = $pdo->query("SELECT item_number, specs FROM item_specifications ORDER BY item_number");
$current_specs = $current_specs_query->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة مواصفات الأصناف</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://unpkg.com/filepond/dist/filepond.css" rel="stylesheet">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4 sm:p-6 lg:p-8" x-data="specsPage()">
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">إدارة مواصفات الأصناف</h1>
            <p class="text-gray-500">رفع وتحديث قائمة الأصناف والمواصفات من ملف CSV.</p>
        </div>
        <a href="index.php" class="text-sm text-blue-600 hover:underline">العودة للرئيسية</a>
    </div>

    <!-- Upload Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
        <div class="lg:col-span-1 bg-white p-6 rounded-2xl shadow-lg">
            <h2 class="text-xl font-bold mb-4">رفع ملف جديد</h2>
            <form method="POST" enctype="multipart/form-data" x-ref="uploadForm">
                <input type="file" name="specs_file" class="filepond" required>
                <button type="submit" class="w-full mt-4 px-6 py-3 bg-blue-600 text-white font-bold rounded-lg hover:bg-blue-700 flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    <span>بدء الاستيراد</span>
                </button>
            </form>
        </div>
        <div class="lg:col-span-2 bg-blue-50 border-r-4 border-blue-400 text-blue-800 rounded-r-lg p-6">
            <h3 class="font-bold text-lg mb-2">تعليمات هامة</h3>
            <ul class="list-disc pr-5 space-y-2 text-sm">
                <li>يجب أن يكون الملف بصيغة **CSV** حصرًا.</li>
                <li>يجب أن يحتوي الملف على عمودين فقط بالترتيب: **العمود الأول لرقم الصنف**، و**العمود الثاني للمواصفات**.</li>
                <li>لا مشكلة في وجود صف عناوين (مثل "رقم الصنف", "اسم الصنف")، سيتم تجاهله تلقائياً.</li>
                <li>عند رفع ملف جديد، سيتم **إضافة الأصناف الجديدة** و **تحديث مواصفات الأصناف الموجودة** تلقائياً.</li>
                <li>ينصح بحفظ الملف بترميز **UTF-8** لضمان دعم اللغة العربية، ولكن النظام سيحاول معالجة الترميزات الأخرى.</li>
            </ul>
        </div>
    </div>

    <!-- Current Data Section -->
    <div class="bg-white p-6 sm:p-8 rounded-2xl shadow-lg">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold">قائمة الأصناف الحالية (<span x-text="filteredSpecs.length"></span>)</h2>
            <input type="text" x-model="search" placeholder="بحث في الأصناف..." class="w-1/3 p-2 border border-gray-300 rounded-lg">
        </div>
        <div class="overflow-y-auto h-96">
            <table class="w-full text-right">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="p-3 text-sm font-semibold text-gray-600">رقم الصنف</th>
                        <th class="p-3 text-sm font-semibold text-gray-600">المواصفات</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="spec in filteredSpecs" :key="spec.item_number">
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3 font-mono font-semibold" x-text="spec.item_number"></td>
                            <td class="p-3" x-text="spec.specs"></td>
                        </tr>
                    </template>
                    <tr x-show="filteredSpecs.length === 0">
                        <td colspan="2" class="text-center p-8 text-gray-500">لا توجد نتائج مطابقة للبحث.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://unpkg.com/filepond/dist/filepond.js"></script>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('specsPage', () => ({
        search: '',
        allSpecs: <?= json_encode($current_specs, JSON_UNESCAPED_UNICODE) ?>,
        
        get filteredSpecs() {
            if (this.search === '') {
                return this.allSpecs;
            }
            return this.allSpecs.filter(spec => {
                const searchTerm = this.search.toLowerCase();
                return spec.item_number.toLowerCase().includes(searchTerm) ||
                       spec.specs.toLowerCase().includes(searchTerm);
            });
        },

        init() {
            // Initialize FilePond
            const pond = FilePond.create(document.querySelector('.filepond'), {
                labelIdle: `اسحب وأفلت ملف CSV هنا أو <span class="filepond--label-action">تصفح</span>`,
                acceptedFileTypes: ['text/csv', 'application/vnd.ms-excel'],
                labelFileTypeNotAllowed: 'ملف غير صالح',
                fileValidateTypeLabelExpectedTypes: 'توقع ملف CSV',
                credits: false,
            });

            // Handle PHP feedback after form submission
            <?php if ($upload_feedback): ?>
                Swal.fire({
                    icon: '<?= $upload_feedback['success'] ? 'success' : 'error' ?>',
                    title: '<?= $upload_feedback['success'] ? 'تمت العملية!' : 'حدث خطأ!' ?>',
                    text: '<?= addslashes($upload_feedback['message']) ?>',
                });
            <?php endif; ?>
        }
    }));
});
</script>

</body>
</html>
