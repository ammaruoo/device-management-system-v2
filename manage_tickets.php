<?php
// تفعيل عرض الأخطاء للأغراض التطويرية
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// بدء الجلسة
session_start();

// التحقق من وجود الجلسة
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// تحميل اتصال قاعدة البيانات مع معالجة الأخطاء
try {
    require 'db.php';
    
    // اختبار الاتصال
    $pdo->query("SELECT 1");
} catch (PDOException $e) {
    die("فشل في الاتصال بقاعدة البيانات: " . $e->getMessage());
}

// التحقق من وجود الجدول
$tableExists = $pdo->query("SHOW TABLES LIKE 'broken_laptops'")->rowCount() > 0;
if (!$tableExists) {
    die("جدول broken_laptops غير موجود في قاعدة البيانات");
}

// التحقق من الصلاحيات
$user_permissions = $_SESSION['permissions'] ?? '';
$allowed_permissions = ['admin', 'manager', 'technician'];
if (!in_array($user_permissions, $allowed_permissions)) {
    header("Location: index.php?error=access_denied");
    exit;
}

// جلب بيانات التذاكر
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// بناء الاستعلام
$query = "SELECT * FROM broken_laptops WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (item_number LIKE ? OR serial_number LIKE ? OR ticket_number LIKE ? OR employee_name LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
}

if (!empty($status_filter)) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

// استخدام laptop_id للترتيب بدلاً من created_at
$query .= " ORDER BY laptop_id DESC";

// تنفيذ الاستعلام
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("فشل في جلب البيانات: " . $e->getMessage());
}

// معالجة طلب الحذف
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ticket'])) {
    if (in_array($user_permissions, ['admin', 'manager'])) {
        $ticket_id = $_POST['ticket_id'] ?? '';
        
        if (!empty($ticket_id)) {
            try {
                $delete_stmt = $pdo->prepare("DELETE FROM broken_laptops WHERE laptop_id = ?");
                if ($delete_stmt->execute([$ticket_id])) {
                    $_SESSION['message'] = "تم حذف التذكرة بنجاح";
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['message'] = "فشل في حذف التذكرة";
                    $_SESSION['message_type'] = "error";
                }
            } catch (PDOException $e) {
                $_SESSION['message'] = "خطأ في قاعدة البيانات: " . $e->getMessage();
                $_SESSION['message_type'] = "error";
            }
            header("Location: manage_tickets.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة تذاكر الصيانة</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        [x-cloak] { display: none !important; }
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
    </style>
</head>
<body class="bg-gray-50">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8 max-w-7xl">

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">إدارة تذاكر الصيانة</h1>
                <p class="text-gray-500">عرض وتعديل وحذف تذاكر الصيانة</p>
            </div>
            <div class="flex gap-2">
                <a href="add_broken_laptop.php" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 flex items-center">
                    <i class="fas fa-plus ml-2"></i> تذكرة جديدة
                </a>
                <a href="index.php" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 flex items-center">
                    <i class="fas fa-home ml-2"></i> الرئيسية
                </a>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['message'])): ?>
        <div class="mb-6 p-4 rounded-lg <?= $_SESSION['message_type'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
            <?= $_SESSION['message'] ?>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="bg-white p-4 rounded-2xl shadow-lg mb-6">
            <h2 class="text-xl font-bold mb-4">فلاتر البحث</h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-1">بحث</label>
                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ابحث برقم الصنف، التسلسلي، أو التذكرة" class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                    <select id="status" name="status" class="w-full p-3 border border-gray-300 rounded-lg">
                        <option value="">جميع الحالات</option>
                        <option value="entered" <?= $status_filter === 'entered' ? 'selected' : '' ?>>تم الإدخال</option>
                        <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>قيد المعالجة</option>
                        <option value="fixed" <?= $status_filter === 'fixed' ? 'selected' : '' ?>>تم الإصلاح</option>
                        <option value="closed" <?= $status_filter === 'closed' ? 'selected' : '' ?>>مغلق</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="px-4 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 w-full">تطبيق الفلتر</button>
                </div>
            </form>
        </div>

        <!-- Tickets Table -->
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full whitespace-nowrap">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم التذكرة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم الصنف</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الرقم التسلسلي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الموظف</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الفرع</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">التاريخ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (count($tickets) > 0): ?>
                            <?php foreach ($tickets as $ticket): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($ticket['ticket_number'] ?? 'N/A') ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?= htmlspecialchars($ticket['item_number']) ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?= htmlspecialchars($ticket['serial_number'] ?? 'N/A') ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?= htmlspecialchars($ticket['employee_name']) ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?= htmlspecialchars($ticket['branch_name'] ?? 'N/A') ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <?php
                                    $status_class = 'status-entered';
                                    $status_text = 'تم الإدخال';
                                    
                                    if ($ticket['status'] === 'in_progress') {
                                        $status_class = 'status-in_progress';
                                        $status_text = 'قيد المعالجة';
                                    } elseif ($ticket['status'] === 'fixed') {
                                        $status_class = 'status-fixed';
                                        $status_text = 'تم الإصلاح';
                                    } elseif ($ticket['status'] === 'closed') {
                                        $status_class = 'status-closed';
                                        $status_text = 'مغلق';
                                    }
                                    ?>
                                    <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-500">
                                        <?php
                                        // محاولة عرض التاريخ من أي عمود تاريخ متاح
                                        if (!empty($ticket['created_at'])) {
                                            echo date('Y-m-d', strtotime($ticket['created_at']));
                                        } elseif (!empty($ticket['entry_date'])) {
                                            echo date('Y-m-d', strtotime($ticket['entry_date']));
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-end space-x-2 space-x-reverse">
                                        <a href="laptop_chat.php?laptop_id=<?= $ticket['laptop_id'] ?>" class="text-blue-500 hover:text-blue-700" title="عرض التفاصيل">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit_ticket.php?id=<?= $ticket['laptop_id'] ?>" class="text-green-500 hover:text-green-700" title="تعديل">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (in_array($user_permissions, ['admin', 'manager'])): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('هل أنت متأكد من حذف هذه التذكرة؟');">
                                            <input type="hidden" name="ticket_id" value="<?= $ticket['laptop_id'] ?>">
                                            <button type="submit" name="delete_ticket" class="text-red-500 hover:text-red-700" title="حذف">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="px-6 py-4 text-center text-sm text-gray-500">
                                    لا توجد تذاكر متاحة
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination (يمكن إضافتها لاحقا إذا لزم الأمر) -->
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('ticketManager', () => ({
                init() {
                    // أي كود تهيئة إضافي
                },
                // يمكن إضافة دوال إضافية لإدارة التذاكر
            }));
        });
    </script>
</body>
</html>