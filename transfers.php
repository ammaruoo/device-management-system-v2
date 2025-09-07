<?php
session_start();
require 'db.php';

// =================================================================================
// SECURITY & PERMISSIONS - Added Logic
// =================================================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];

// قائمة بمعرفات المستخدمين (user_id) المسموح لهم بإنشاء التحويلات
// يمكنك تعديل هذه الأرقام حسب المستخدمين الذين تريد السماح لهم بالتحويل
// مثال: [1, 5, 8]
$allowed_transfer_users = [5, 8, 12,11,1,2,3,4,5,6,7,9,20]; 

// تحقق مما إذا كان المستخدم الحالي لديه الصلاحية
$can_create_transfer = in_array($user_id, $allowed_transfer_users);

// =================================================================================
// FETCH DATA FOR THE PAGE
// =================================================================================
// 1. Fetch transfers pending receipt BY the current user
$pending_receipts_query = $pdo->prepare("
    SELECT t.transfer_id, t.laptop_id, t.transfer_ref, t.transfer_date, sender.username as sender_name, bl.specs
    FROM inventory_transfers t
    JOIN users sender ON t.transfer_user_id = sender.user_id
    LEFT JOIN broken_laptops bl ON t.laptop_id = bl.laptop_id
    WHERE t.receive_user_id = ? AND t.is_received = 0
    ORDER BY t.transfer_date DESC
");
$pending_receipts_query->execute([$user_id]);
$pending_receipts = $pending_receipts_query->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch transfers initiated BY the current user
$outgoing_transfers_query = $pdo->prepare("
    SELECT t.transfer_id, t.laptop_id, t.transfer_ref, t.transfer_date, receiver.username as receiver_name, t.is_received, t.receive_date, bl.specs
    FROM inventory_transfers t
    JOIN users receiver ON t.receive_user_id = receiver.user_id
    LEFT JOIN broken_laptops bl ON t.laptop_id = bl.laptop_id
    WHERE t.transfer_user_id = ?
    ORDER BY t.transfer_date DESC
");
$outgoing_transfers_query->execute([$user_id]);
$outgoing_transfers = $outgoing_transfers_query->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch data for the "Create Transfer" modal
$transferable_laptops_query = $pdo->query("SELECT laptop_id, specs FROM broken_laptops WHERE status NOT IN ('locked', 'مغلق', 'جاهز للبيع', 'لم يتم الإصلاح') ORDER BY laptop_id DESC");
$transferable_laptops = $transferable_laptops_query->fetchAll(PDO::FETCH_ASSOC);

$all_users_query = $pdo->query("SELECT user_id, username FROM users ORDER BY username ASC");
$all_users = $all_users_query->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة التحويلات المخزنية</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        [x-cloak] { display: none !important; }
        .tab-button.active { border-color: #3B82F6; color: #3B82F6; background-color: #EFF6FF; }
        .swal2-input, .swal2-select {
            border: 1px solid #D1D5DB !important;
            border-radius: 0.5rem !important;
            padding: 0.75rem !important;
            width: 90% !important;
            margin: 0.5rem auto !important;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4 sm:p-6 lg:p-8" x-data="transfersPage()">
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">التحويلات المخزنية</h1>
            <p class="text-gray-500">تتبع حركة الأجهزة بين الموظفين والأقسام.</p>
        </div>
        <div class="flex items-center gap-4">
            <!-- Added x-show to hide/show the button based on permission -->
           
            <button @click="openCreateTransferModal()" class="px-4 py-2 bg-blue-600 text-white font-semibold rounded-lg shadow-md hover:bg-blue-700 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                <span>إنشاء تحويل جديد</span>
            </button>
           
            <a href="index.php" class="text-sm text-blue-600 hover:underline">العودة للرئيسية</a>
        </div>
    </div>

    <!-- Tabs -->
    <div class="mb-6">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex gap-6" aria-label="Tabs">
                <button @click="activeTab = 'pending'" :class="{ 'active': activeTab === 'pending' }" class="tab-button shrink-0 border-b-2 px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700">
                    بانتظار استلامي <span x-text="`(${pendingReceipts.length})`" class="ml-1 rounded-full bg-orange-200 text-orange-700 px-2 py-0.5 text-xs"></span>
                </button>
                <button @click="activeTab = 'outgoing'" :class="{ 'active': activeTab === 'outgoing' }" class="tab-button shrink-0 border-b-2 px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700">
                    تحويلاتي الصادرة
                </button>
            </nav>
        </div>
    </div>

    <!-- Pending Receipts Tab Content -->
    <div x-show="activeTab === 'pending'" x-cloak>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <template x-for="transfer in pendingReceipts" :key="transfer.transfer_id">
                <div class="bg-white rounded-xl shadow-lg p-5 flex flex-col border-t-4 border-orange-400">
                    <div class="flex-1">
                        <p class="font-bold text-lg font-mono text-blue-600" x-text="transfer.laptop_id"></p>
                        <p class="text-sm text-gray-700 truncate" x-text="transfer.specs || 'لا توجد مواصفات'"></p>
                        <div class="mt-4 border-t pt-3 text-sm space-y-1 text-gray-600">
                            <p><strong>مرجع التحويل:</strong> <span x-text="transfer.transfer_ref || 'لا يوجد'"></span></p>
                            <p><strong>محول من:</strong> <span x-text="transfer.sender_name"></span></p>
                            <p><strong>تاريخ التحويل:</strong> <span x-text="new Date(transfer.transfer_date).toLocaleDateString('ar-EG')"></span></p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button @click="confirmReceipt(transfer.transfer_id)" class="w-full px-4 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700">
                            تأكيد الاستلام
                        </button>
                    </div>
                </div>
            </template>
            <div x-show="pendingReceipts.length === 0" class="md:col-span-2 lg:col-span-3 text-center py-16 bg-white rounded-xl shadow-md">
                <p class="text-gray-500">لا توجد تحويلات بانتظار استلامك حالياً.</p>
            </div>
        </div>
    </div>

    <!-- Outgoing Transfers Tab Content -->
    <div x-show="activeTab === 'outgoing'" x-cloak>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <template x-for="transfer in outgoingTransfers" :key="transfer.transfer_id">
                <div class="bg-white rounded-xl shadow-lg p-5 flex flex-col" :class="transfer.is_received == 1 ? 'border-t-4 border-green-400' : 'border-t-4 border-gray-300'">
                    <p class="font-bold text-lg font-mono text-blue-600" x-text="transfer.laptop_id"></p>
                    <p class="text-sm text-gray-700 truncate" x-text="transfer.specs || 'لا توجد مواصفات'"></p>
                    <div class="mt-4 border-t pt-3 text-sm space-y-1 text-gray-600">
                        <p><strong>محول إلى:</strong> <span x-text="transfer.receiver_name"></span></p>
                        <p><strong>تاريخ التحويل:</strong> <span x-text="new Date(transfer.transfer_date).toLocaleDateString('ar-EG')"></span></p>
                        <div class="flex items-center gap-2">
                            <strong>الحالة:</strong>
                            <span x-show="transfer.is_received == 1" class="px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-700">تم الاستلام</span>
                            <span x-show="transfer.is_received == 0" class="px-2 py-0.5 text-xs font-semibold rounded-full bg-orange-100 text-orange-700">بانتظار الاستلام</span>
                        </div>
                        <p x-show="transfer.is_received == 1"><strong>تاريخ الاستلام:</strong> <span x-text="new Date(transfer.receive_date).toLocaleDateString('ar-EG')"></span></p>
                    </div>
                </div>
            </template>
            <div x-show="outgoingTransfers.length === 0" class="md:col-span-2 lg:col-span-3 text-center py-16 bg-white rounded-xl shadow-md">
                <p class="text-gray-500">لم تقم بإنشاء أي تحويلات بعد.</p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('transfersPage', () => ({
        activeTab: 'pending',
        // Make sure to match the variable name used in the PHP part
        canCreateTransfer: <?= json_encode($can_create_transfer) ?>,
        pendingReceipts: <?= json_encode($pending_receipts, JSON_UNESCAPED_UNICODE) ?>,
        outgoingTransfers: <?= json_encode($outgoing_transfers, JSON_UNESCAPED_UNICODE) ?>,
        transferableLaptops: <?= json_encode($transferable_laptops, JSON_UNESCAPED_UNICODE) ?>,
        allUsers: <?= json_encode($all_users, JSON_UNESCAPED_UNICODE) ?>,
        api_url: 'api_handler.php',

        async openCreateTransferModal() {
            // Check if the user has permission from the PHP variable
            if (!this.canCreateTransfer) {
                Swal.fire('خطأ!', 'ليس لديك صلاحية لإنشاء تحويلات.', 'error');
                return;
            }

            const laptopOptions = this.transferableLaptops.reduce((acc, laptop) => {
                acc[laptop.laptop_id] = `${laptop.laptop_id} - ${laptop.specs || 'بدون مواصفات'}`;
                return acc;
            }, {});
            const userOptions = this.allUsers.reduce((acc, user) => {
                acc[user.user_id] = user.username;
                return acc;
            }, {});

            const { value: formValues } = await Swal.fire({
                title: 'إنشاء تحويل مخزني جديد',
                html: `
                    <select id="swal-laptop" class="swal2-select" placeholder="اختر الجهاز">
                        <option value="" disabled selected>-- اختر الجهاز المراد تحويله --</option>
                        ${Object.keys(laptopOptions).map(key => `<option value="${key}">${laptopOptions[key]}</option>`).join('')}
                    </select>
                    <input id="swal-ref" class="swal2-input" placeholder="رقم مرجع التحويل (اختياري)">
                    <select id="swal-user" class="swal2-select" placeholder="اختر المستلم">
                        <option value="" disabled selected>-- اختر الموظف المستلم --</option>
                        ${Object.keys(userOptions).map(key => `<option value="${key}">${userOptions[key]}</option>`).join('')}
                    </select>
                `,
                focusConfirm: false,
                preConfirm: () => {
                    return {
                        laptop_id: document.getElementById('swal-laptop').value,
                        transfer_ref: document.getElementById('swal-ref').value,
                        receive_user_id: document.getElementById('swal-user').value
                    }
                }
            });

            if (formValues && formValues.laptop_id && formValues.receive_user_id) {
                this.createTransfer(formValues);
            }
        },

        async createTransfer(data) {
            const formData = new FormData();
            formData.append('laptop_id', data.laptop_id);
            formData.append('transfer_ref', data.transfer_ref);
            formData.append('receive_user_id', data.receive_user_id);

            const response = await fetch(`${this.api_url}?action=create_transfer`, {
                method: 'POST',
                body: formData
            }).then(res => res.json());

            if (response.success) {
                this.outgoingTransfers.unshift(response.transfer); // Add to the top of the list
                Swal.fire('تم!', response.message, 'success');
            } else {
                Swal.fire('خطأ!', response.message, 'error');
            }
        },

        confirmReceipt(transferId) {
            Swal.fire({
                title: 'هل أنت متأكد؟',
                text: "سيتم تسجيل استلامك لهذا الجهاز.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'نعم، تأكيد!',
                cancelButtonText: 'إلغاء'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('transfer_id', transferId);
                    const response = await fetch(`${this.api_url}?action=confirm_receipt`, {
                        method: 'POST',
                        body: formData
                    }).then(res => res.json());

                    if (response.success) {
                        this.pendingReceipts = this.pendingReceipts.filter(t => t.transfer_id !== transferId);
                        Swal.fire('تم!', response.message, 'success');
                    } else {
                        Swal.fire('خطأ!', response.message, 'error');
                    }
                }
            });
        }
    }));
});
</script>

</body>
</html>
