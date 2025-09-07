<?php
require 'db.php';

echo "<h1>فحص بنية جدول operations</h1>";

try {
    $stmt = $pdo->query('DESCRIBE operations');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>أعمدة جدول operations:</h2>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . $column['Default'] . "</td>";
        echo "<td>" . $column['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // فحص أوامر الشغل الموجودة
    echo "<h2>أوامر الشغل الموجودة:</h2>";
    $stmt = $pdo->query("
        SELECT o.operation_id, o.work_order_ref, o.operation_date, o.repair_result,
               rc.total_cost, rc.cost_items, u.username as engineer_name
        FROM operations o
        LEFT JOIN repair_costs rc ON o.operation_id = rc.operation_id
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE o.repair_result = 'امر شغل'
        ORDER BY o.operation_date DESC
        LIMIT 10
    ");
    $work_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($work_orders) > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Operation ID</th><th>Work Order Ref</th><th>Date</th><th>Engineer</th><th>Total Cost</th></tr>";
        
        foreach ($work_orders as $wo) {
            echo "<tr>";
            echo "<td>" . $wo['operation_id'] . "</td>";
            echo "<td>" . ($wo['work_order_ref'] ?: 'غير محدد') . "</td>";
            echo "<td>" . $wo['operation_date'] . "</td>";
            echo "<td>" . ($wo['engineer_name'] ?: 'غير محدد') . "</td>";
            echo "<td>" . ($wo['total_cost'] ?: '0.00') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>لا توجد أوامر شغل في قاعدة البيانات.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>خطأ: " . $e->getMessage() . "</p>";
}
?>
