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

// =================================================================================
// GET & VALIDATE LAPTOP ID FROM URL
// =================================================================================
if (!isset($_GET['laptop_id']) || empty($_GET['laptop_id'])) {
    // إذا لم يتم توفير رقم الجهاز في الرابط، أوقف التنفيذ
    die("خطأ: لم يتم تحديد رقم الجهاز للتحويل. يرجى العودة والمحاولة مرة أخرى.");
}
$laptop_id_to_transfer = $_GET['laptop_id'];

// التحقق من أن الجهاز موجود وحالته تسمح بالتحويل
$laptop_query = $pdo->prepare("
    SELECT laptop_id, specs 
    FROM broken_laptops 
    WHERE laptop_id = ? 
    AND status NOT IN ('locked', 'مغلق', 'جاهز للبيع', 'لم يتم الإصلاح') 
    LIMIT 1
");
$laptop_query->execute([$laptop_id_to_transfer]);
$laptop = $laptop_query->fetch(PDO::FETCH_ASSOC);

if (!$laptop) {
    // إذا كان الجهاز غير موجود أو غير قابل للتحويل، أوقف التنفيذ
    die("خطأ: الجهاز المطلوب غير موجود أو حالته الحالية لا تسمح بالتحويل.");
}


// =================================================================================
// FETCH USERS FOR THE DROPDOWN
// =================================================================================
// جلب كل المستخدمين ما عدا المستخدم الحالي
$users_query = $pdo->prepare("SELECT user_id, username FROM users WHERE user_id != ? ORDER BY username ASC");
$users_query->execute([$user_id]);
$all_users = $users_query->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحويل الجهاز: <?= htmlspecialchars($laptop['laptop_id']) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">

<div class="container mx-auto p-4 max-w-lg" x-data="transferPage()">
    
    <div class="bg-white rounded-xl shadow-lg p-6 sm:p-8">
        <div class="text-center">
            <h1 class="text-2xl font-bold text-gray-800">إتمام عملية التحويل</h1>
            <p class="text-gray-500 mt-2">أنت على وشك تحويل الجهاز التالي:</p>
            <div class="mt-4 bg-blue-50 border-r-4 border-blue-500 p-4 rounded-lg text-right">
                <p class="font-mono text-lg font-semibold text-blue-800"><?= htmlspecialchars($laptop['laptop_id']) ?></p>
                <p class="text-sm text-gray-700 truncate"><?= htmlspecialchars($laptop['specs'] ?: 'لا توجد مواصفات مسجلة') ?></p>
            </div>
        </div>

        <div class="mt-8 space-y-4">
            <div>
                <label for="recipient_user" class="block text-sm font-medium text-gray-700 mb-1">اختر المستلم</label>
                <select id="recipient_user" x-model="recipientUserId" class="block w-full px-4 py-3 border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="">-- من فضلك اختر موظف --</option>
                    <?php foreach ($all_users as $user): ?>
                        <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="transfer_ref" class="block text-sm font-medium text-gray-700 mb-1">مرجع التحويل (اختياري)</label>
                <input type="text" id="transfer_ref" x-model="transferRef" class="block w-full px-4 py-3 border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="مثال: بناء على طلب قسم...">
            </div>
            
            <button @click="executeTransfer()" :disabled="isLoading || !recipientUserId" class="w-full flex justify-center items-center gap-3 px-4 py-3 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:bg-gray-400 transition-colors">
                 <svg x-show="isLoading" class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <span x-text="isLoading ? 'جاري التحويل...' : 'إتمام التحويل'"></span>
            </button>
        </div>
         <div class="mt-6 text-center">
            <a href="javascript:history.back()" class="text-sm text-blue-600 hover:underline">العودة للخلف</a>
        </div>
    </div>

</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('transferPage', () => ({
        // رقم الجهاز ثابت ويأتي من PHP
        laptopId: '<?= htmlspecialchars($laptop['laptop_id']) ?>',
        recipientUserId: '',
        transferRef: '',
        isLoading: false,
        api_url: 'api_handler.php',

        async executeTransfer() {
            if (!this.recipientUserId) {
                Swal.fire('تنبيه', 'يجب اختيار الموظف المستلم أولاً.', 'warning');
                return;
            }
            this.isLoading = true;

            const formData = new FormData();
            formData.append('laptop_id', this.laptopId);
            formData.append('receive_user_id', this.recipientUserId);
            formData.append('transfer_ref', this.transferRef);
            
            try {
                const response = await fetch(`${this.api_url}?action=create_transfer`, {
                    method: 'POST',
                    body: formData
                }).then(res => res.json());

                if (response.success) {
                    await Swal.fire({
                        title: 'تم التحويل بنجاح!',
                        text: response.message,
                        icon: 'success',
                        confirmButtonText: 'موافق'
                    });
                    // يمكنك توجيه المستخدم لصفحة أخرى بعد النجاح
                    // window.location.href = 'inventory_list.php'; 
                } else {
                    Swal.fire('فشل التحويل!', response.message, 'error');
                }
            } catch (error) {
                 console.error('Fetch Error:', error);
                 Swal.fire('خطأ فني!', 'حدث خطأ أثناء الاتصال بالخادم.', 'error');
            } finally {
                this.isLoading = false;
            }
        }
    }));
});
</script>

</body>
</html>