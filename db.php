
<?php
// // db.php
// $host = 'localhost';        // اسم السيرفر، غالبًا localhost
// $db   = 'u174966602_mzn'; // اسم قاعدة البيانات
// // $user = '';             // اسم المستخدم لقاعدة البيانات
// $pass = ''; 
// $user = 'root';             // اسم المستخدم لقاعدة البيانات
// // $pass = '';                   // كلمة المرور (ضعها إذا موجودة)
// $charset = 'utf8mb4';       // الترميز لضمان دعم العربية

// $dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// $options = [
//     PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // عرض الأخطاء
//     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // يعيد النتائج كمصفوفة مرتبطة
//     PDO::ATTR_EMULATE_PREPARES   => false,                  // منع هجمات SQL Injection
// ];

// try {
//     $pdo = new PDO($dsn, $user, $pass, $options);
// } catch (\PDOException $e) {
//     die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
// }
?>


<?php
// db.php
$host = 'localhost';        // اسم السيرفر، غالبًا localhost
$db   = 'u174966602_nsm9J'; // اسم قاعدة البيانات
// $user = '';             // اسم المستخدم لقاعدة البيانات
$pass = '#Ammarcom12'; 
$user = 'u174966602_4nAun';             // اسم المستخدم لقاعدة البيانات
// $pass = '';                   // كلمة المرور (ضعها إذا موجودة)
$charset = 'utf8mb4';       // الترميز لضمان دعم العربية

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // عرض الأخطاء
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // يعيد النتائج كمصفوفة مرتبطة
    PDO::ATTR_EMULATE_PREPARES   => false,                  // منع هجمات SQL Injection
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
}
?>

