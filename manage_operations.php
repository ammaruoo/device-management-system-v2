<?php
session_start();
require 'db.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['permissions'], ['admin', 'manager'])) {
    header("Location: index.php?error=access_denied");
    exit;
}
$ops_query = $pdo->query("SELECT op_id, op_name FROM predefined_operations ORDER BY op_name ASC");
$predefined_ops = $ops_query->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة العمليات الموحدة</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Cairo', sans-serif; } [x-cloak] { display: none !important; } </style>
</head>
<body class="bg-gray-100">
<div class="container mx-auto p-6" x-data="manageOps()">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">إدارة العمليات الموحدة</h1>
            <p class="text-gray-500">أضف أو احذف العمليات التي تظهر للفنيين في قائمة الاختيار.</p>
        </div>
        <a href="index.php" class="text-sm text-blue-600 hover:underline">العودة للرئيسية</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <div class="md:col-span-1">
            <div class="bg-white p-6 rounded-2xl shadow-lg">
                <h2 class="text-xl font-bold mb-4">إضافة عملية جديدة</h2>
                <form @submit.prevent="addOperation">
                    <input type="text" x-model="newOpName" placeholder="اكتب اسم العملية هنا..." required class="w-full p-3 border border-gray-300 rounded-lg">
                    <button type="submit" class="w-full mt-4 px-6 py-3 bg-blue-600 text-white font-bold rounded-lg hover:bg-blue-700">إضافة للقائمة</button>
                </form>
            </div>
        </div>
        <div class="md:col-span-2 bg-white p-6 rounded-2xl shadow-lg">
            <h2 class="text-xl font-bold mb-4">قائمة العمليات الحالية</h2>
            <div class="overflow-y-auto h-96">
                <ul class="space-y-2">
                    <template x-for="op in operations" :key="op.op_id">
                        <li class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <span x-text="op.op_name"></span>
                            <button @click="deleteOperation(op.op_id)" class="text-gray-400 hover:text-red-500">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('manageOps', () => ({
        operations: <?= json_encode($predefined_ops) ?>,
        newOpName: '',
        api_url: 'api_handler.php',
        async addOperation() {
            if (!this.newOpName.trim()) return;
            const formData = new FormData();
            formData.append('op_name', this.newOpName);
            const response = await this.sendRequest('add_predefined_op', formData);
            if (response.success) {
                this.operations.push(response.op);
                this.operations.sort((a, b) => a.op_name.localeCompare(b.op_name));
                this.newOpName = '';
                Swal.fire('تم!', 'تمت إضافة العملية بنجاح.', 'success');
            } else {
                Swal.fire('خطأ!', response.message, 'error');
            }
        },
        deleteOperation(opId) {
            Swal.fire({
                title: 'هل أنت متأكد؟', text: "سيتم حذف هذه العملية من القائمة.", icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#3085d6',
                confirmButtonText: 'نعم، قم بالحذف!', cancelButtonText: 'إلغاء'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('op_id', opId);
                    const response = await this.sendRequest('delete_predefined_op', formData);
                    if (response.success) {
                        this.operations = this.operations.filter(op => op.op_id !== opId);
                        Swal.fire('تم الحذف!', 'تم حذف العملية بنجاح.', 'success');
                    } else {
                        Swal.fire('خطأ!', response.message, 'error');
                    }
                }
            });
        },
        async sendRequest(action, formData) {
            try {
                const response = await fetch(`${this.api_url}?action=${action}`, { method: 'POST', body: formData });
                return await response.json();
            } catch (error) {
                return { success: false, message: 'فشل الاتصال بالخادم.' };
            }
        }
    }));
});
</script>
</body>
</html>
