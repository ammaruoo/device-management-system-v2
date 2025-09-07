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
// Only admins and managers can assign tasks
if (!in_array($_SESSION['permissions'], ['admin', 'manager'])) {
    header("Location: index.php?error=access_denied");
    exit;
}

$laptop_id = $_GET['laptop_id'] ?? 0;
if (empty($laptop_id)) {
    die("رقم الجهاز غير محدد.");
}

// =================================================================================
// FETCH DATA FOR THE SPECIFIC LAPTOP
// =================================================================================
$laptop_query = $pdo->prepare("
    SELECT 
        b.laptop_id, b.serial_number, b.specs, b.problem_details, o.entry_date
    FROM broken_laptops b
    LEFT JOIN (
        SELECT laptop_id, MIN(operation_date) as entry_date 
        FROM operations GROUP BY laptop_id
    ) o ON b.laptop_id = o.laptop_id
    WHERE b.laptop_id = ?
");
$laptop_query->execute([$laptop_id]);
$laptop = $laptop_query->fetch(PDO::FETCH_ASSOC);

if (!$laptop) {
    die("الجهاز غير موجود أو تم تعيينه مسبقاً.");
}

// =================================================================================
// FETCH TECHNICIANS WITH THEIR CURRENT WORKLOAD
// =================================================================================
$technicians_query = $pdo->query("
    SELECT 
        u.user_id,
        u.username,
        (SELECT COUNT(*) FROM broken_laptops bl WHERE bl.assigned_user_id = u.user_id AND bl.status NOT IN ('locked', 'مغلق')) as open_tasks
    FROM users u
    WHERE u.permissions IN ('technician', 'prep_engineer', 'admin')
    ORDER BY open_tasks ASC, u.username ASC
");
$technicians = $technicians_query->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تكليف مهمة للجهاز: <?= htmlspecialchars($laptop['laptop_id']) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4 sm:p-6 lg:p-8" x-data="assignTaskPage()">
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">تكليف مهمة</h1>
            <p class="text-gray-500">اختر المهندس المناسب لهذه المهمة.</p>
        </div>
        <a href="broken_laptops.php" class="text-sm text-blue-600 hover:underline">العودة لقائمة الأجهزة</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Device Info Card -->
        <div class="lg:col-span-2 bg-white rounded-2xl shadow-lg p-6">
            <h2 class="text-xl font-bold mb-4 border-b pb-3">تفاصيل الجهاز</h2>
            <div class="space-y-4">
                <div>
                    <p class="text-sm text-gray-500">رقم التذكرة</p>
                    <p class="font-bold text-2xl font-mono text-blue-600"><?= htmlspecialchars($laptop['laptop_id']) ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">المواصفات</p>
                    <p class="font-semibold text-gray-800"><?= htmlspecialchars($laptop['specs'] ?: 'غير محدد') ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">المشاكل المسجلة</p>
                    <div class="prose prose-sm max-w-none text-gray-700 bg-gray-50 p-3 rounded-md">
                        <ul>
                            <?php 
                                $problems = json_decode($laptop['problem_details'], true);
                                if (is_array($problems)) {
                                    foreach($problems as $problem) {
                                        echo '<li><strong>' . htmlspecialchars($problem['title']) . ':</strong> ' . htmlspecialchars($problem['details']) . '</li>';
                                    }
                                }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Technicians List -->
        <div class="lg:col-span-1">
            <div class="bg-white p-6 rounded-2xl shadow-lg">
                <h2 class="text-xl font-bold mb-4">اختر المهندس المسؤول</h2>
                <div class="space-y-3">
                    <template x-for="tech in technicians" :key="tech.user_id">
                        <button @click="selectedTechnician = tech" 
                                class="w-full text-right p-4 border rounded-lg flex justify-between items-center transition"
                                :class="selectedTechnician && selectedTechnician.user_id === tech.user_id ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-200' : 'border-gray-200 hover:bg-gray-50'">
                            <span class="font-semibold" x-text="tech.username"></span>
                            <span class="text-sm text-gray-500 bg-gray-200 px-2 py-1 rounded-full" x-text="`${tech.open_tasks} مهام مفتوحة`"></span>
                        </button>
                    </template>
                </div>
                <div class="mt-6">
                    <button @click="confirmAssignment()" :disabled="!selectedTechnician" class="w-full px-6 py-3 bg-green-600 text-white font-bold rounded-lg hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed">
                        تأكيد التكليف
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('assignTaskPage', () => ({
        technicians: <?= json_encode($technicians, JSON_UNESCAPED_UNICODE) ?>,
        selectedTechnician: null,
        api_url: 'api_handler.php',

        confirmAssignment() {
            if (!this.selectedTechnician) return;

            Swal.fire({
                title: 'هل أنت متأكد؟',
                html: `سيتم تكليف الجهاز للمهندس: <br><strong>${this.selectedTechnician.username}</strong>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'نعم، قم بالتكليف!',
                cancelButtonText: 'إلغاء'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.assignTask();
                }
            });
        },

        async assignTask() {
            const formData = new FormData();
            formData.append('laptop_id', <?= json_encode($laptop['laptop_id']) ?>);
            formData.append('assign_to_user_id', this.selectedTechnician.user_id);

            const response = await fetch(`${this.api_url}?action=assign_ticket`, {
                method: 'POST',
                body: formData
            }).then(res => res.json());

            if (response.success) {
                await Swal.fire({
                    icon: 'success',
                    title: 'تم بنجاح!',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                });
                window.location.href = 'broken_laptops.php';
            } else {
                Swal.fire('خطأ!', response.message, 'error');
            }
        }
    }));
});
</script>

</body>
</html>
