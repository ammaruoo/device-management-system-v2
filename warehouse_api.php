<?php
session_start();
require 'db.php';

// =================================================================================
// الحماية والصلاحيات
// =================================================================================
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'غير مصرح']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_permissions = $_SESSION['permissions'];

// =================================================================================
// معالجة الطلبات
// =================================================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'get_warehouse_by_branch':
        getWarehousesByBranch($pdo, $user_permissions);
        break;
        
    case 'get_warehouse_stats':
        getWarehouseStats($pdo, $user_id);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'إجراء غير معروف']);
        break;
}

// =================================================================================
// دوال العمليات
// =================================================================================

/**
 * جلب المخازن حسب الفرع
 */
function getWarehousesByBranch($pdo, $user_permissions) {
    try {
        $branch_id = $_GET['branch_id'] ?? null;
        
        if (!$branch_id) {
            throw new Exception('معرف الفرع مطلوب');
        }
        
        $query = $pdo->prepare("
            SELECT warehouse_id, warehouse_name, warehouse_type 
            FROM warehouses 
            WHERE branch_id = ? AND status = 'active'
            ORDER BY warehouse_name
        ");
        $query->execute([$branch_id]);
        $warehouses = $query->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'warehouses' => $warehouses
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * جلب إحصائيات المخازن
 */
function getWarehouseStats($pdo, $user_id) {
    try {
        // إجمالي عدد المخازن
        $total_warehouses = $pdo->query("SELECT COUNT(*) FROM warehouses WHERE status = 'active'")->fetchColumn();
        
        // عدد المخازن حسب النوع
        $warehouses_by_type = $pdo->query("
            SELECT warehouse_type, COUNT(*) as count 
            FROM warehouses 
            WHERE status = 'active' 
            GROUP BY warehouse_type
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        // عدد المخازن حسب الفرع
        $warehouses_by_branch = $pdo->query("
            SELECT b.branch_name, COUNT(w.warehouse_id) as count 
            FROM branches b
            LEFT JOIN warehouses w ON b.branch_id = w.branch_id AND w.status = 'active'
            WHERE b.status = 'active'
            GROUP BY b.branch_id, b.branch_name
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'stats' => [
                'total_warehouses' => $total_warehouses,
                'warehouses_by_type' => $warehouses_by_type,
                'warehouses_by_branch' => $warehouses_by_branch
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
?>
