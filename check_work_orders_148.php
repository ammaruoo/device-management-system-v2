<?php
require 'db.php';

echo "<h1>فحص أوامر الشغل للجهاز 148</h1>";

try {
    $laptop_id = 148;
    
    // فحص العمليات للجهاز
    echo "<h2>جميع العمليات للجهاز $laptop_id:</h2>";
    $stmt = $pdo->prepare("
        SELECT o.operation_id, o.repair_result, o.operation_date, o.details,
               u.username as engineer_name
        FROM operations o
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE o.laptop_id = ?
        ORDER BY o.operation_date DESC
    ");
    $stmt->execute([$laptop_id]);
    $operations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($operations) > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Operation ID</th><th>Type</th><th>Date</th><th>Engineer</th><th>Details</th></tr>";
        
        foreach ($operations as $op) {
            echo "<tr>";
            echo "<td>" . $op['operation_id'] . "</td>";
            echo "<td>" . $op['repair_result'] . "</td>";
            echo "<td>" . $op['operation_date'] . "</td>";
            echo "<td>" . ($op['engineer_name'] ?: 'غير محدد') . "</td>";
            echo "<td>" . substr($op['details'], 0, 50) . "...</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>لا توجد عمليات للجهاز $laptop_id.</p>";
    }
    
    // فحص أوامر الشغل تحديداً
    echo "<h2>أوامر الشغل للجهاز $laptop_id:</h2>";
    $stmt = $pdo->prepare("
        SELECT o.operation_id, o.work_order_ref, o.operation_date,
               rc.total_cost, rc.cost_items, u.username as engineer_name
        FROM operations o
        LEFT JOIN repair_costs rc ON o.operation_id = rc.operation_id
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE o.laptop_id = ? 
        AND o.repair_result = 'امر شغل'
        ORDER BY o.operation_date DESC
    ");
    $stmt->execute([$laptop_id]);
    $work_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($work_orders) > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Operation ID</th><th>Work Order Ref</th><th>Date</th><th>Engineer</th><th>Total Cost</th><th>Cost Items</th></tr>";
        
        foreach ($work_orders as $wo) {
            echo "<tr>";
            echo "<td>" . $wo['operation_id'] . "</td>";
            echo "<td>" . ($wo['work_order_ref'] ?: 'غير محدد') . "</td>";
            echo "<td>" . $wo['operation_date'] . "</td>";
            echo "<td>" . ($wo['engineer_name'] ?: 'غير محدد') . "</td>";
            echo "<td>" . ($wo['total_cost'] ?: '0.00') . "</td>";
            echo "<td>" . substr($wo['cost_items'], 0, 100) . "...</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>لا توجد أوامر شغل للجهاز $laptop_id.</p>";
    }
    
    // فحص جدول invoices
    echo "<h2>فحص جدول invoices:</h2>";
    $table_exists = $pdo->query("SHOW TABLES LIKE 'invoices'")->rowCount() > 0;
    
    if ($table_exists) {
        echo "<p style='color: green;'>✅ جدول invoices موجود</p>";
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM invoices");
        $count = $stmt->fetchColumn();
        echo "<p>عدد الفواتير: $count</p>";
        
        if ($count > 0) {
            $stmt = $pdo->query("SELECT * FROM invoices LIMIT 5");
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Invoice ID</th><th>Laptop ID</th><th>Operation ID</th><th>Invoice Number</th><th>Total Amount</th></tr>";
            
            foreach ($invoices as $inv) {
                echo "<tr>";
                echo "<td>" . $inv['invoice_id'] . "</td>";
                echo "<td>" . $inv['laptop_id'] . "</td>";
                echo "<td>" . $inv['operation_id'] . "</td>";
                echo "<td>" . $inv['invoice_number'] . "</td>";
                echo "<td>" . $inv['total_amount'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<p style='color: red;'>❌ جدول invoices غير موجود</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>خطأ: " . $e->getMessage() . "</p>";
}
?>
