<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// فلاتر
$filter_branch = $_GET['branch'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_user = $_GET['user'] ?? '';

// جلب كل المستخدمين والفروع
$users = $pdo->query("SELECT * FROM users")->fetchAll();
$branches = $pdo->query("SELECT DISTINCT branch_name FROM broken_laptops")->fetchAll(PDO::FETCH_COLUMN);

// استعلام الأجهزة المغلقة
$query = "SELECT l.*, b.serial_number, b.branch_name, u.username AS closed_by
          FROM locks l
          LEFT JOIN broken_laptops b ON l.laptop_id=b.laptop_id
          LEFT JOIN users u ON l.user_id=u.user_id
          WHERE 1";
$params = [];
if($filter_branch){ $query.=" AND b.branch_name=?"; $params[]=$filter_branch; }
if($filter_status){ $query.=" AND l.final_status=?"; $params[]=$filter_status; }
if($filter_user){ $query.=" AND l.user_id=?"; $params[]=$filter_user; }

$query .= " ORDER BY l.lock_date DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$locks = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ملخص الأقفال</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen p-4">

<div class="max-w-7xl mx-auto">

    <header class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold">ملخص الأقفال</h1>
        <a href="index.php" class="bg-gray-500 text-white px-3 py-1 rounded">العودة للرئيسية</a>
    </header>

    <!-- فلترة -->
    <div class="bg-white p-4 rounded shadow mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <select name="branch" class="p-2 border rounded">
                <option value="">كل الفروع</option>
                <?php foreach($branches as $b): ?>
                    <option value="<?= $b ?>" <?= $filter_branch==$b?'selected':'' ?>><?= $b ?></option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="p-2 border rounded">
                <option value="">كل الحالات</option>
                <option value="مغلق" <?= $filter_status=='مغلق'?'selected':'' ?>>مغلق</option>
                <option value="قيد الإغلاق" <?= $filter_status=='قيد الإغلاق'?'selected':'' ?>>قيد الإغلاق</option>
            </select>

            <select name="user" class="p-2 border rounded">
                <option value="">كل المستخدمين</option>
                <?php foreach($users as $u): ?>
                    <option value="<?= $u['user_id'] ?>" <?= $filter_user==$u['user_id']?'selected':'' ?>><?= $u['username'] ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="bg-gray-500 text-white px-4 py-2 rounded">تصفية</button>
        </form>
    </div>

    <!-- جدول الأقفال -->
    <div class="bg-white p-4 rounded shadow">
        <?php if(count($locks)===0): ?>
            <p>لا توجد بيانات للأقفال.</p>
        <?php else: ?>
            <table class="w-full table-auto border-collapse border border-gray-300 text-right">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="border border-gray-300 p-2">سيريال الجهاز</th>
                        <th class="border border-gray-300 p-2">نوع القفل</th>
                        <th class="border border-gray-300 p-2">نسبة الحل %</th>
                        <th class="border border-gray-300 p-2">وصف إضافي</th>
                        <th class="border border-gray-300 p-2">الحالة النهائية</th>
                        <th class="border border-gray-300 p-2">الفرع</th>
                        <th class="border border-gray-300 p-2">تم بواسطة</th>
                        <th class="border border-gray-300 p-2">تاريخ الإغلاق</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($locks as $l): ?>
                        <tr>
                            <td class="border border-gray-300 p-2"><?= $l['serial_number'] ?></td>
                            <td class="border border-gray-300 p-2"><?= $l['lock_type'] ?></td>
                            <td class="border border-gray-300 p-2"><?= $l['solution_percentage'] ?></td>
                            <td class="border border-gray-300 p-2"><?= $l['more_description'] ?></td>
                            <td class="border border-gray-300 p-2"><?= $l['final_status'] ?></td>
                            <td class="border border-gray-300 p-2"><?= $l['branch_name'] ?></td>
                            <td class="border border-gray-300 p-2"><?= $l['closed_by'] ?></td>
                            <td class="border border-gray-300 p-2"><?= $l['lock_date'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
