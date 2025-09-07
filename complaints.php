<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// التأكد من وجود رقم الجهاز
if (!isset($_GET['laptop_id'])) {
    die("رقم الجهاز غير موجود.");
}

$laptop_id = $_GET['laptop_id'];

// إضافة شكوى جديدة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_complaint'])) {
    $problem_title = $_POST['problem_title'];
    $problem_details = $_POST['problem_details'];
    $image_path = null;

    // رفع الصورة إذا كانت موجودة
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image_path = 'uploads/' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], $image_path);
    }

    $stmt = $pdo->prepare("INSERT INTO complaints (laptop_id, problem_title, problem_details, image_path, user_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$laptop_id, $problem_title, $problem_details, $image_path, $user_id]);

    header("Location: complaints.php?laptop_id=$laptop_id&msg=added");
    exit;
}

// جلب كل الشكاوى للجهاز
$stmt = $pdo->prepare("SELECT c.*, u.username FROM complaints c LEFT JOIN users u ON c.user_id=u.user_id WHERE c.laptop_id=? ORDER BY c.complaint_date DESC");
$stmt->execute([$laptop_id]);
$complaints = $stmt->fetchAll();

// جلب بيانات الجهاز
$stmt2 = $pdo->prepare("SELECT * FROM broken_laptops WHERE laptop_id=?");
$stmt2->execute([$laptop_id]);
$laptop = $stmt2->fetch();
?>
<!DOCTYPE html>
<html lang="ar">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>الشكاوى - الجهاز <?= htmlspecialchars($laptop['serial_number']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen p-4">

<div class="max-w-4xl mx-auto">

    <header class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold">الشكاوى - الجهاز: <?= htmlspecialchars($laptop['serial_number']) ?></h1>
        <a href="broken_laptops.php" class="bg-gray-500 text-white px-3 py-1 rounded">رجوع للأجهزة</a>
    </header>

    <!-- رسالة نجاح -->
    <?php if(isset($_GET['msg']) && $_GET['msg']=='added'): ?>
        <div class="bg-green-100 text-green-800 p-2 rounded mb-3">تم إضافة الشكوى بنجاح!</div>
    <?php endif; ?>

    <!-- نموذج إضافة شكوى -->
    <div class="bg-white p-4 rounded shadow mb-6">
        <h2 class="font-bold mb-2">إضافة شكوى جديدة</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="add_complaint" value="1">

            <label>عنوان المشكلة</label>
            <input type="text" name="problem_title" class="w-full p-2 border rounded mb-3" required>

            <label>تفاصيل المشكلة</label>
            <textarea name="problem_details" class="w-full p-2 border rounded mb-3" required></textarea>

            <label>إرفاق صورة (اختياري)</label>
            <input type="file" name="image" class="w-full mb-3">

            <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">إضافة الشكوى</button>
        </form>
    </div>

    <!-- عرض الشكاوى -->
    <div class="bg-white p-4 rounded shadow">
        <h2 class="font-bold mb-4">قائمة الشكاوى</h2>
        <?php if(count($complaints) === 0): ?>
            <p>لا توجد شكاوى لهذا الجهاز.</p>
        <?php else: ?>
            <?php foreach($complaints as $c): ?>
                <div class="border border-gray-300 rounded p-3 mb-3">
                    <div class="flex justify-between mb-1">
                        <span class="font-bold"><?= htmlspecialchars($c['problem_title']) ?></span>
                        <span class="text-gray-500 text-sm"><?= $c['complaint_date'] ?></span>
                    </div>
                    <p class="mb-2"><?= nl2br(htmlspecialchars($c['problem_details'])) ?></p>
                    <?php if($c['image_path']): ?>
                        <img src="<?= htmlspecialchars($c['image_path']) ?>" class="max-w-xs mb-2 rounded border">
                    <?php endif; ?>
                    <div class="text-sm text-gray-700">تم الإضافة بواسطة: <?= htmlspecialchars($c['username']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
