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
$user_permissions = $_SESSION['permissions'];
if (!in_array($user_permissions, ['admin', 'manager', 'technician'])) {
    header("Location: index.php?error=access_denied");
    exit;
}

// =================================================================================
// FETCH TICKET DATA
// =================================================================================
if (!isset($_GET['id'])) {
    header("Location: manage_tickets.php");
    exit;
}

$ticket_id = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM broken_laptops WHERE laptop_id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    header("Location: manage_tickets.php?error=not_found");
    exit;
}

// =================================================================================
// FETCH USERS FOR ASSIGNMENT
// =================================================================================
$users_query = $pdo->query("SELECT user_id, username, permissions FROM users WHERE permissions IN ('technician', 'admin', 'manager') ORDER BY username");
$assignable_users = $users_query->fetchAll();

// =================================================================================
// FETCH BRANCHES
// =================================================================================
$branches_query = $pdo->query("SELECT DISTINCT branch_name FROM users WHERE branch_name IS NOT NULL AND branch_name != '' ORDER BY branch_name ASC");
$branches = $branches_query->fetchAll(PDO::FETCH_COLUMN);

// =================================================================================
// HANDLE FORM SUBMISSION
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_number = trim($_POST['item_number']);
    $serial_number = trim($_POST['serial_number']) ?: null;
    $specs = trim($_POST['specs']);
    $branch_name = trim($_POST['branch_name']) ?: null;
    $status = $_POST['status'];
    $problem_type = trim($_POST['problem_type']) ?: null;
    $problem_nature = trim($_POST['problem_nature']) ?: null;
    $transfer_ref = trim($_POST['transfer_ref']) ?: null;
    $repeat_problem_count = (int)$_POST['repeat_problem_count'];
    $with_charger = isset($_POST['with_charger']) ? 1 : 0;
    
    $assigned_user_id = null;
    if (in_array($user_permissions, ['admin', 'manager'])) {
        $assigned_user_id = empty($_POST['assigned_user_id']) ? null : (int)$_POST['assigned_user_id'];
    }
    
    try {
        $update_stmt = $pdo->prepare("UPDATE broken_laptops SET 
            item_number = ?, serial_number = ?, specs = ?, branch_name = ?, 
            status = ?, problem_type = ?, problem_nature = ?, transfer_ref = ?, 
            repeat_problem_count = ?, with_charger = ?, assigned_user_id = ?
            WHERE laptop_id = ?");
            
        $update_stmt->execute([
            $item_number, $serial_number, $specs, $branch_name,
            $status, $problem_type, $problem_nature, $transfer_ref,
            $repeat_problem_count, $with_charger, $assigned_user_id,
            $ticket_id
        ]);
        
        $_SESSION['message'] = "تم تحديث التذكرة بنجاح";
        $_SESSION['message_type'] = "success";
        header("Location: manage_tickets.php");
        exit;
        
    } catch (Exception $e) {
        $error = "حدث خطأ أثناء تحديث التذكرة: " . $e->getMessage();
    }
}

// Predefined problem types and natures
$problem_types = ['هاردوير', 'سوفتوير', 'بطارية', 'تغير قطعه', 'حراره', 'لا اعلم'];
$problem_natures = ['سهلة مستعجلة', ' سهلة عادية', 'صعبة مستعجلة', 'صعبة  عادية', ' طارئ'];
$status_options = [
    'entered' => 'تم الإدخال',
    'in_progress' => 'قيد المعالجة',
    'fixed' => 'تم الإصلاح',
    'closed' => 'مغلق'
];
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل تذكرة صيانة</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
    </style>
</head>
<body class="bg-gray-50">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8 max-w-4xl">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">تعديل تذكرة صيانة</h1>
                <p class="text-gray-500">رقم التذكرة: <?= htmlspecialchars($ticket['ticket_number'] ?? 'N/A') ?></p>
            </div>
            <a href="manage_tickets.php" class="text-blue-600 hover:underline">العودة إلى القائمة</a>
        </div>

        <!-- Messages -->
        <?php if (isset($error)): ?>
        <div class="mb-6 p-4 bg-red-100 text-red-700 rounded-lg">
            <?= $error ?>
        </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <form method="POST" class="bg-white p-6 sm:p-8 rounded-2xl shadow-lg">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="item_number" class="block text-sm font-medium text-gray-700 mb-1">رقم الصنف</label>
                    <input type="text" id="item_number" name="item_number" value="<?= htmlspecialchars($ticket['item_number']) ?>" required class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label for="serial_number" class="block text-sm font-medium text-gray-700 mb-1">الرقم التسلسلي (S/N)</label>
                    <input type="text" id="serial_number" name="serial_number" value="<?= htmlspecialchars($ticket['serial_number'] ?? '') ?>" class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                <div class="md:col-span-2">
                    <label for="specs" class="block text-sm font-medium text-gray-700 mb-1">المواصفات</label>
                    <textarea id="specs" name="specs" rows="3" class="w-full p-3 border border-gray-300 rounded-lg"><?= htmlspecialchars($ticket['specs']) ?></textarea>
                </div>
                <div>
                    <label for="branch_name" class="block text-sm font-medium text-gray-700 mb-1">الفرع</label>
                    <select id="branch_name" name="branch_name" class="w-full p-3 border border-gray-300 rounded-lg">
                        <option value="">اختر الفرع</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= htmlspecialchars($branch) ?>" <?= $ticket['branch_name'] === $branch ? 'selected' : '' ?>><?= htmlspecialchars($branch) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                    <select id="status" name="status" class="w-full p-3 border border-gray-300 rounded-lg">
                        <?php foreach ($status_options as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $ticket['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="problem_type" class="block text-sm font-medium text-gray-700 mb-1">نوع المشكلة</label>
                    <select id="problem_type" name="problem_type" class="w-full p-3 border border-gray-300 rounded-lg">
                        <option value="">اختر نوع المشكلة</option>
                        <?php foreach ($problem_types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>" <?= $ticket['problem_type'] === $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="problem_nature" class="block text-sm font-medium text-gray-700 mb-1">طبيعة المشكلة</label>
                    <select id="problem_nature" name="problem_nature" class="w-full p-3 border border-gray-300 rounded-lg">
                        <option value="">اختر طبيعة المشكلة</option>
                        <?php foreach ($problem_natures as $nature): ?>
                            <option value="<?= htmlspecialchars($nature) ?>" <?= $ticket['problem_nature'] === $nature ? 'selected' : '' ?>><?= htmlspecialchars($nature) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="transfer_ref" class="block text-sm font-medium text-gray-700 mb-1">رقم التحويل</label>
                    <input type="text" id="transfer_ref" name="transfer_ref" value="<?= htmlspecialchars($ticket['transfer_ref'] ?? '') ?>" class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label for="repeat_problem_count" class="block text-sm font-medium text-gray-700 mb-1">عدد مرات تكرار المشكلة</label>
                    <input type="number" id="repeat_problem_count" name="repeat_problem_count" value="<?= htmlspecialchars($ticket['repeat_problem_count']) ?>" min="0" class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                <div class="md:col-span-2">
                    <label class="flex items-center">
                        <input type="checkbox" name="with_charger" class="mr-2" <?= $ticket['with_charger'] ? 'checked' : '' ?>>
                        <span class="text-sm font-medium text-gray-700">مع الشاحن</span>
                    </label>
                </div>
                <?php if (in_array($user_permissions, ['admin', 'manager'])): ?>
                <div class="md:col-span-2">
                    <label for="assigned_user_id" class="block text-sm font-medium text-gray-700 mb-1">تعيين للفني</label>
                    <select id="assigned_user_id" name="assigned_user_id" class="w-full p-3 border border-gray-300 rounded-lg">
                        <option value="">لا يوجد تعيين</option>
                        <?php foreach ($assignable_users as $user): ?>
                            <option value="<?= $user['user_id'] ?>" <?= $ticket['assigned_user_id'] == $user['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['permissions']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="flex justify-end mt-8 space-x-4 space-x-reverse">
                <a href="manage_tickets.php" class="px-6 py-3 bg-gray-500 text-white rounded-lg hover:bg-gray-600">إلغاء</a>
                <button type="submit" class="px-6 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600">حفظ التغييرات</button>
            </div>
        </form>
    </div>
</body>
</html>