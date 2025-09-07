<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$search_results = [];
$error = '';
$search_term = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search_term = trim($_POST['search_term'] ?? '');

    if (empty($search_term)) {
        $error = 'الرجاء إدخال رقم تذكرة أو رقم صنف للبحث.';
    } else {
        // 1. First, try to find a direct match by laptop_id (ticket number)
        $stmt = $pdo->prepare("SELECT laptop_id FROM broken_laptops WHERE laptop_id = ?");
        $stmt->execute([$search_term]);
        $laptop = $stmt->fetch();

        if ($laptop) {
            // Exact match found, redirect immediately to the report
            header("Location: device_report.php?laptop_id=" . urlencode($laptop['laptop_id']));
            exit;
        }

        // 2. If not found, search by item_number
        $stmt = $pdo->prepare("SELECT laptop_id, serial_number, specs, employee_name FROM broken_laptops WHERE item_number = ? ORDER BY laptop_id DESC");
        $stmt->execute([$search_term]);
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($search_results) === 1) {
            // Only one result found for this item number, redirect to its report
            header("Location: device_report.php?laptop_id=" . urlencode($search_results[0]['laptop_id']));
            exit;
        } elseif (count($search_results) === 0) {
            $error = "لم يتم العثور على أي جهاز مطابق للرقم المدخل.";
        }
        // If multiple results are found, the page will display them below.
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>البحث عن سجل جهاز</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Cairo', sans-serif; } </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4 sm:p-6 lg:p-8 max-w-4xl">
    
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">البحث عن سجل جهاز</h1>
            <p class="text-gray-500">أدخل رقم التذكرة أو رقم الصنف لعرض مسيرته الكاملة.</p>
        </div>
        <a href="index.php" class="text-sm text-blue-600 hover:underline">العودة للرئيسية</a>
    </div>

    <div class="bg-white p-8 rounded-2xl shadow-lg">
        <form method="POST">
            <label for="search_term" class="block text-lg font-semibold text-gray-700 mb-2">رقم التذكرة / رقم الصنف</label>
            <div class="flex items-center gap-3">
                <input type="text" id="search_term" name="search_term" value="<?= htmlspecialchars($search_term) ?>" required
                       class="flex-1 w-full p-4 border border-gray-300 rounded-lg text-xl font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-blue-500" 
                       placeholder="e.g., 2508123 or 22-123-533">
                <button type="submit" class="px-8 py-4 bg-blue-600 text-white font-bold text-lg rounded-lg hover:bg-blue-700">
                    بحث
                </button>
            </div>
        </form>

        <?php if (!empty($error)): ?>
            <div class="mt-6 bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg">
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <?php if (count($search_results) > 1): ?>
            <div class="mt-8">
                <h2 class="text-xl font-bold mb-4">تم العثور على عدة أجهزة بنفس رقم الصنف. الرجاء اختيار الجهاز المطلوب:</h2>
                <div class="space-y-3">
                    <?php foreach ($search_results as $result): ?>
                        <a href="device_report.php?laptop_id=<?= urlencode($result['laptop_id']) ?>" class="block p-4 border rounded-lg hover:bg-gray-50 transition">
                            <p class="font-bold font-mono text-blue-600">رقم التذكرة: <?= htmlspecialchars($result['laptop_id']) ?></p>
                            <p class="text-sm text-gray-700"><?= htmlspecialchars($result['specs']) ?></p>
                            <p class="text-xs text-gray-500">مدخل بواسطة: <?= htmlspecialchars($result['employee_name']) ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
