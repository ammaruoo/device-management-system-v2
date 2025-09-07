<?php


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'db.php';

// Set header to return JSON
header('Content-Type: application/json');

// Basic security check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

switch ($action) {
    // ========================================================================
    // Case 1: Fetch notifications for the logged-in user
    // ========================================================================
    case 'get_notifications':
        $stmt_unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt_unread->execute([$user_id]);
        $unread_count = $stmt_unread->fetchColumn();

        $stmt_notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
        $stmt_notifs->execute([$user_id]);
        $notifications = $stmt_notifs->fetchAll();
        
        echo json_encode(['unread_count' => $unread_count, 'notifications' => $notifications]);
        break;

    // ========================================================================
    // Case 2: Mark all user notifications as read
    // ========================================================================
    case 'mark_notifications_read':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true]);
        }
        break;

    // ========================================================================
    // Case 3: Live search for laptops by serial number
    // ========================================================================
    case 'search':
        $query = $_GET['query'] ?? '';
        if (strlen($query) >= 2) {
            $stmt = $pdo->prepare("SELECT laptop_id, serial_number, status FROM broken_laptops WHERE serial_number LIKE ? LIMIT 5");
            $stmt->execute(["%$query%"]);
            $results = $stmt->fetchAll();
            echo json_encode($results);
        } else {
            echo json_encode([]);
        }
        break;

    // ========================================================================
    // Case 4: Get HTML for the "Recent Activity" widget
    // ========================================================================
    case 'get_activity':
        $query = $pdo->query("
            (SELECT o.laptop_id, b.serial_number, u.username, 'تم تسجيل عملية جديدة' as action, o.operation_date as activity_date FROM operations o JOIN users u ON o.user_id = u.user_id JOIN broken_laptops b ON o.laptop_id = b.laptop_id ORDER BY o.operation_date DESC LIMIT 5)
            UNION
            (SELECT l.laptop_id, b.serial_number, u.username, 'تم إغلاق تذكرة' as action, l.lock_date as activity_date FROM locks l JOIN users u ON l.user_id = u.user_id JOIN broken_laptops b ON l.laptop_id = b.laptop_id ORDER BY l.lock_date DESC LIMIT 5)
            ORDER BY activity_date DESC LIMIT 10
        ");
        $activities = $query->fetchAll();
        $html = '';
        if (empty($activities)) {
            $html = '<p class="text-center text-gray-500 py-8">لا يوجد نشاط حديث.</p>';
        } else {
            foreach ($activities as $activity) {
                $icon = $activity['action'] == 'تم إغلاق تذكرة' ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />' : '<path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />';
                $html .= '<div class="flex items-start gap-3">
                            <div class="w-10 h-10 flex-shrink-0 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center">
                                <svg class="w-5 h-5 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">' . $icon . '</svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold">' . htmlspecialchars($activity['username']) . ' <span class="font-normal text-gray-500 dark:text-gray-400">' . htmlspecialchars($activity['action']) . '</span></p>
                                <p class="text-xs text-gray-400 dark:text-gray-500">لجهاز <a href="laptop_chat.php?laptop_id=' . $activity['laptop_id'] . '" class="text-blue-500 hover:underline">' . htmlspecialchars($activity['serial_number']) . '</a></p>
                                <p class="text-xs text-gray-400 dark:text-gray-500">' . date("d M, Y H:i", strtotime($activity['activity_date'])) . '</p>
                            </div>
                          </div>';
            }
        }
        echo json_encode(['html' => $html]);
        break;

    // ========================================================================
    // Case 5: Get HTML for "Laptops Requiring Attention" widget
    // ========================================================================
    case 'get_attention_laptops':
        $stmt = $pdo->prepare("
            SELECT b.laptop_id, b.serial_number, b.problem_details, DATEDIFF(NOW(), op.first_date) as days_pending
            FROM broken_laptops b
            JOIN (SELECT laptop_id, MIN(operation_date) as first_date FROM operations GROUP BY laptop_id) op ON b.laptop_id = op.laptop_id
            WHERE b.status = 'review_pending' AND DATEDIFF(NOW(), op.first_date) > 3
            ORDER BY days_pending DESC
        ");
        $stmt->execute();
        $laptops = $stmt->fetchAll();
        $html = '';
        if (empty($laptops)) {
            $html = '<p class="text-center text-gray-500 py-8">لا توجد أجهزة متأخرة حالياً.</p>';
        } else {
            foreach ($laptops as $laptop) {
                $html .= '<div class="bg-red-50 dark:bg-red-500/10 p-4 rounded-lg flex justify-between items-center">
                            <div>
                                <p class="font-bold">' . htmlspecialchars($laptop['serial_number']) . '</p>
                                <p class="text-sm text-gray-600 dark:text-gray-300 truncate w-64">' . htmlspecialchars($laptop['problem_details']) . '</p>
                                <p class="text-xs text-red-500 font-semibold">متأخر ' . $laptop['days_pending'] . ' أيام</p>
                            </div>
                            <a href="laptop_chat.php?laptop_id=' . $laptop['laptop_id'] . '" class="px-3 py-1 bg-red-500 text-white text-sm rounded-md hover:bg-red-600">متابعة</a>
                          </div>';
            }
        }
        echo json_encode(['html' => $html]);
        break;

    // ========================================================================
    // Case 6: Get HTML for "Technician Leaderboard" widget
    // ========================================================================
    case 'get_leaderboard':
        $stmt = $pdo->query("
            SELECT u.username, COUNT(l.lock_id) as closed_count
            FROM locks l
            JOIN users u ON l.user_id = u.user_id
            GROUP BY u.user_id, u.username
            ORDER BY closed_count DESC
            LIMIT 5
        ");
        $techs = $stmt->fetchAll();
        $html = '';
        if (empty($techs)) {
            $html = '<p class="text-center text-gray-500 py-8">لا توجد بيانات لعرضها.</p>';
        } else {
            foreach ($techs as $index => $tech) {
                $html .= '<li class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="font-bold text-lg text-gray-400">' . ($index + 1) . '</span>
                                <img src="https://placehold.co/40x40/E2E8F0/4A5568?text=' . strtoupper(substr($tech['username'], 0, 1)) . '" class="w-10 h-10 rounded-full" alt="">
                                <p class="font-semibold">' . htmlspecialchars($tech['username']) . '</p>
                            </div>
                            <div class="text-left">
                                <p class="font-bold text-lg">' . $tech['closed_count'] . '</p>
                                <p class="text-xs text-gray-500">جهاز مغلق</p>
                            </div>
                          </li>';
            }
        }
        echo json_encode(['html' => $html]);
        break;
    // ========================================================================
    // Case: Get chat messages for a specific laptop
    // ========================================================================
    case 'get_chat_messages':
        $laptop_id = (int)($_GET['laptop_id'] ?? 0);
        if ($laptop_id > 0) {
            $stmt = $pdo->prepare("
                SELECT d.message, d.image_path, d.created_at, u.username, u.user_id
                FROM laptop_discussions d
                JOIN users u ON d.user_id = u.user_id
                WHERE d.laptop_id = ?
                ORDER BY d.created_at ASC
            ");
            $stmt->execute([$laptop_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($messages);
        } else {
            echo json_encode([]);
        }
        break;
         // ========================================================================
    // Case: Fetch comprehensive data for the reports dashboard
    // ========================================================================
    case 'get_report_data':
        $start_date = $_GET['start_date'] ?? date('Y-m-01');
        $end_date = $_GET['end_date'] ?? date('Y-m-d');
        $status = $_GET['status'] ?? '';
        $assigned_user_id = $_GET['assigned_user_id'] ?? '';

        $base_query = "FROM broken_laptops b 
                       LEFT JOIN users u ON b.assigned_user_id = u.user_id 
                       LEFT JOIN operations o ON b.laptop_id = o.laptop_id AND o.operation_id = (SELECT MIN(op.operation_id) FROM operations op WHERE op.laptop_id = b.laptop_id)";
        
        $where_clauses = ["DATE(o.operation_date) BETWEEN ? AND ?"];
        $params = [$start_date, $end_date];

        if (!empty($status)) {
            $where_clauses[] = "b.status = ?";
            $params[] = $status;
        }
        if (!empty($assigned_user_id)) {
            $where_clauses[] = "b.assigned_user_id = ?";
            $params[] = $assigned_user_id;
        }

        $where_sql = "WHERE " . implode(" AND ", $where_clauses);

        // KPIs
        $total_tickets_stmt = $pdo->prepare("SELECT COUNT(DISTINCT b.laptop_id) $base_query $where_sql");
        $total_tickets_stmt->execute($params);
        $total_tickets = $total_tickets_stmt->fetchColumn();

        $closed_tickets_stmt = $pdo->prepare("SELECT COUNT(DISTINCT b.laptop_id) $base_query $where_sql AND b.status IN ('locked', 'مغلق')");
        $closed_tickets_stmt->execute($params);
        $closed_tickets = $closed_tickets_stmt->fetchColumn();
        
        $avg_time_stmt = $pdo->prepare("SELECT AVG(DATEDIFF(l.lock_date, o.operation_date)) FROM locks l JOIN operations o ON l.laptop_id = o.laptop_id WHERE l.lock_id IN (SELECT ll.lock_id FROM locks ll JOIN broken_laptops bb ON ll.laptop_id = bb.laptop_id JOIN operations oo ON bb.laptop_id = oo.laptop_id $where_sql)");
        $avg_time_stmt->execute($params);
        $avg_repair_time = round((float)$avg_time_stmt->fetchColumn(), 1);

        // Charts Data
        $tech_performance_stmt = $pdo->prepare("SELECT u.username, COUNT(b.laptop_id) as count $base_query $where_sql AND b.status IN ('locked', 'مغلق') GROUP BY u.username ORDER BY count DESC");
        $tech_performance_stmt->execute($params);
        $tech_performance = $tech_performance_stmt->fetchAll(PDO::FETCH_ASSOC);

        $problem_types_stmt = $pdo->prepare("SELECT b.problem_type, COUNT(b.laptop_id) as count $base_query $where_sql AND b.problem_type IS NOT NULL AND b.problem_type != '' GROUP BY b.problem_type ORDER BY count DESC LIMIT 5");
        $problem_types_stmt->execute($params);
        $problem_types = $problem_types_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Table Data
        $table_data_stmt = $pdo->prepare("SELECT b.laptop_id, b.serial_number, b.specs, b.employee_name, b.status, u.username as assigned_to, o.operation_date as entry_date $base_query $where_sql ORDER BY b.laptop_id DESC");
        $table_data_stmt->execute($params);
        $table_data = $table_data_stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'kpis' => [
                'total_tickets' => $total_tickets,
                'closed_tickets' => $closed_tickets,
                'completion_rate' => ($total_tickets > 0) ? round(($closed_tickets / $total_tickets) * 100) : 0,
                'avg_repair_time' => $avg_repair_time,
            ],
            'charts' => [
                'tech_performance' => $tech_performance,
                'problem_types' => $problem_types,
            ],
            'table_data' => $table_data
        ]);
        break;
    // ========================================================================
    // Case: Add a new user
    // ========================================================================
    case 'add_user':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $permissions = $_POST['permissions'] ?? '';

            if (empty($username) || empty($password) || empty($permissions)) {
                echo json_encode(['success' => false, 'message' => 'جميع الحقول مطلوبة.']);
                exit;
            }

            // Check if username already exists
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'اسم المستخدم موجود مسبقاً.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO users (username, password, permissions) VALUES (?, ?, ?)");
            if ($stmt->execute([$username, $password, $permissions])) {
                $new_user_id = $pdo->lastInsertId();
                // Fetch the newly created user's full data to return to the frontend
                $stmt = $pdo->prepare("SELECT user_id, username, permissions FROM users WHERE user_id = ?");
                $stmt->execute([$new_user_id]);
                $new_user = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'user' => $new_user]);
            } else {
                echo json_encode(['success' => false, 'message' => 'فشل إضافة المستخدم.']);
            }
        }
        break;

    // ========================================================================
    // Case: Update an existing user
    // ========================================================================
    case 'update_user':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            $user_id = $_POST['user_id'] ?? 0;
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? ''; // Password is optional
            $permissions = $_POST['permissions'] ?? '';

            if (empty($username) || empty($permissions) || empty($user_id)) {
                echo json_encode(['success' => false, 'message' => 'البيانات الأساسية للمستخدم مطلوبة.']);
                exit;
            }

            // Check for duplicate username (excluding the current user)
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
            $stmt->execute([$username, $user_id]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'اسم المستخدم موجود مسبقاً.']);
                exit;
            }

            if (!empty($password)) {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, permissions = ? WHERE user_id = ?");
                $success = $stmt->execute([$username, $password, $permissions, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, permissions = ? WHERE user_id = ?");
                $success = $stmt->execute([$username, $permissions, $user_id]);
            }

            if ($success) {
                echo json_encode(['success' => true, 'message' => 'تم تحديث المستخدم بنجاح.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'فشل تحديث المستخدم.']);
            }
        }
        break;

    // ========================================================================
    // Case: Delete a user
    // ========================================================================
    case 'delete_user':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            $user_id = $_POST['user_id'] ?? 0;

            if (empty($user_id)) {
                echo json_encode(['success' => false, 'message' => 'معرف المستخدم غير صحيح.']);
                exit;
            }

            // Prevent deleting the currently logged-in user
            if ($user_id == $_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => 'لا يمكنك حذف حسابك الخاص.']);
                exit;
            }

            // Check for dependencies (assigned tickets, etc.)
            $stmt = $pdo->prepare("SELECT laptop_id FROM broken_laptops WHERE assigned_user_id = ? OR entered_by_user_id = ?");
            $stmt->execute([$user_id, $user_id]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'لا يمكن حذف المستخدم لارتباطه بتذاكر. يرجى إعادة تعيين التذاكر أولاً.']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            if ($stmt->execute([$user_id])) {
                echo json_encode(['success' => true, 'message' => 'تم حذف المستخدم بنجاح.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'فشل حذف المستخدم.']);
            }
        }
        break;
            case 'get_specs':

        $item_number = $_GET['item_number'] ?? '';

        if (!empty($item_number)) {

            $stmt = $pdo->prepare("SELECT specs FROM item_specifications WHERE item_number = ?");

            $stmt->execute([$item_number]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode($result); // Will return {"specs": "..."} or null

        } else {

            echo json_encode(null);

        }

        break;



// ... (قبل default case) ...

    // ========================================================================
    // Case: Add a new user
    // ========================================================================
    case 'add_user':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $permissions = $_POST['permissions'] ?? '';
            $branch_name = trim($_POST['branch_name']) ?: null; // Get branch name

            if (empty($username) || empty($password) || empty($permissions)) {
                echo json_encode(['success' => false, 'message' => 'جميع الحقول مطلوبة.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'اسم المستخدم موجود مسبقاً.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO users (username, password, permissions, branch_name) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$username, $password, $permissions, $branch_name])) {
                $new_user_id = $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT user_id, username, permissions, branch_name FROM users WHERE user_id = ?");
                $stmt->execute([$new_user_id]);
                $new_user = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'user' => $new_user]);
            } else {
                echo json_encode(['success' => false, 'message' => 'فشل إضافة المستخدم.']);
            }
        }
        break;

    // ========================================================================
    // Case: Update an existing user
    // ========================================================================
    case 'update_user':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            $user_id = $_POST['user_id'] ?? 0;
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $permissions = $_POST['permissions'] ?? '';
            $branch_name = trim($_POST['branch_name']) ?: null; // Get branch name

            if (empty($username) || empty($permissions) || empty($user_id)) {
                echo json_encode(['success' => false, 'message' => 'البيانات الأساسية للمستخدم مطلوبة.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
            $stmt->execute([$username, $user_id]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'اسم المستخدم موجود مسبقاً.']);
                exit;
            }

            if (!empty($password)) {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, permissions = ?, branch_name = ? WHERE user_id = ?");
                $success = $stmt->execute([$username, $password, $permissions, $branch_name, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, permissions = ?, branch_name = ? WHERE user_id = ?");
                $success = $stmt->execute([$username, $permissions, $branch_name, $user_id]);
            }

            if ($success) {
                echo json_encode(['success' => true, 'message' => 'تم تحديث المستخدم بنجاح.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'فشل تحديث المستخدم.']);
            }
        }
        break;
        // ... (الكود السابق في الملف) ...

    // ========================================================================
    // Case: Assign a ticket to a technician
    // ========================================================================
    case 'assign_ticket':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            $laptop_id = $_POST['laptop_id'] ?? 0;
            $assign_to_user_id = $_POST['assign_to_user_id'] ?? 0;

            if (empty($laptop_id) || empty($assign_to_user_id)) {
                echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // 1. Update the laptop record
                $stmt = $pdo->prepare("UPDATE broken_laptops SET assigned_user_id = ?, status = 'assigned' WHERE laptop_id = ?");
                $stmt->execute([$assign_to_user_id, $laptop_id]);

                // 2. Get info for notification
                $info_stmt = $pdo->prepare("SELECT serial_number FROM broken_laptops WHERE laptop_id = ?");
                $info_stmt->execute([$laptop_id]);
                $laptop_info = $info_stmt->fetch();
                $ticket_ref = $laptop_info['serial_number'] ?: ('جهاز رقم ' . $laptop_id);

                $assigner_username = $_SESSION['username'];

                // 3. Create an operation log
                $log_stmt = $pdo->prepare("INSERT INTO operations (laptop_id, user_id, repair_result, details) VALUES (?, ?, ?, ?)");
                $log_details = "تم تعيين الجهاز للفني بواسطة " . $assigner_username;
                $log_stmt->execute([$laptop_id, $_SESSION['user_id'], 'تم تعيين المهمة', $log_details]);

                // 4. Send notification to the technician
                $notification_message = "مهمة جديدة: تم تعيين الجهاز " . htmlspecialchars($ticket_ref) . " لك.";
                $notification_link = "laptop_chat.php?laptop_id=" . $laptop_id;
                $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                $notif_stmt->execute([$assign_to_user_id, $notification_message, $notification_link]);
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'تم تعيين الجهاز بنجاح.']);

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Assign task error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم.']);
            }
        }
        break;
      

        // ========================================================================
        // Case: Add a new predefined operation
        // ========================================================================
        case 'add_predefined_op':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
                $op_name = trim($_POST['op_name'] ?? '');
                if (empty($op_name)) {
                    echo json_encode(['success' => false, 'message' => 'اسم العملية مطلوب.']); exit;
                }
                try {
                    $stmt = $pdo->prepare("INSERT INTO predefined_operations (op_name) VALUES (?)");
                    $stmt->execute([$op_name]);
                    $new_id = $pdo->lastInsertId();
                    echo json_encode(['success' => true, 'op' => ['op_id' => $new_id, 'op_name' => $op_name]]);
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'message' => 'اسم العملية موجود مسبقاً.']);
                }
            }
            break;
    
        // ========================================================================
        // Case: Delete a predefined operation
        // ========================================================================
        case 'delete_predefined_op':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
                $op_id = (int)($_POST['op_id'] ?? 0);
                if ($op_id > 0) {
                    $stmt = $pdo->prepare("DELETE FROM predefined_operations WHERE op_id = ?");
                    if ($stmt->execute([$op_id])) {
                        echo json_encode(['success' => true]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'فشل حذف العملية.']);
                    }
                }
            }
            break;
    
    // ========================================================================
    // Case: Add a new operation, potentially with costs
    // ========================================================================
    case 'add_operation':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
            $laptop_id = $_POST['laptop_id'] ?? 0;
            $user_id = $_SESSION['user_id']; // استخدام user_id من الجلسة فقط
            $repair_result = $_POST['repair_result'] ?? '';
            $details = $_POST['details'] ?? '';
            $work_order_ref = $_POST['work_order_ref'] ?? null;
            $cost_items_json = $_POST['cost_items'] ?? '[]';
            $receipt_number = $_POST['receipt_number'] ?? null;
            $receipt_date = $_POST['receipt_date'] ?? null;
            
            if (empty($laptop_id) || empty($repair_result)) {
                echo json_encode(['success' => false, 'message' => 'بيانات العملية غير مكتملة.']); exit;
            }
            
            // تسجيل البيانات للتحقق
            error_log("Add Operation Debug - laptop_id: $laptop_id, user_id: $user_id, repair_result: $repair_result");

            $pdo->beginTransaction();
            try {
                // Insert into operations table first with receipt_number, work_order_ref, and receipt_date
                $stmt = $pdo->prepare("INSERT INTO operations (laptop_id, user_id, repair_result, details, receipt_number, work_order_ref, receipt_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$laptop_id, $user_id, $repair_result, $details, $receipt_number, $work_order_ref, $receipt_date]);
                $operation_id = $pdo->lastInsertId();

                // If it's a work order, process costs
                if ($repair_result === 'امر شغل' && $work_order_ref) {
                    $cost_items = json_decode($cost_items_json, true);
                    $total_cost = 0;
                    if (is_array($cost_items)) {
                        foreach ($cost_items as $item) {
                            $total_cost += (float)($item['cost'] ?? 0);
                        }
                    }

                    $cost_stmt = $pdo->prepare(
                        "INSERT INTO repair_costs (laptop_id, operation_id, work_order_ref, cost_items, total_cost, user_id) VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $cost_stmt->execute([$laptop_id, $operation_id, $work_order_ref, $cost_items_json, $total_cost, $user_id]);
                    
                    // تسجيل نجاح إدراج التكاليف
                    error_log("Repair Costs Inserted - operation_id: $operation_id, user_id: $user_id, total_cost: $total_cost");
                }

                // If it's creating an invoice, process invoice creation
                if ($repair_result === 'إنشاء فاتورة') {
                    $work_order_id = $_POST['work_order_id'] ?? 0;
                    $invoice_number = $_POST['invoice_number'] ?? '';
                    $invoice_date = $_POST['invoice_date'] ?? '';
                    $notes = $_POST['notes'] ?? '';
                    
                    if (empty($work_order_id) || empty($invoice_number) || empty($invoice_date)) {
                        throw new Exception('بيانات الفاتورة غير مكتملة.');
                    }

                    // Get work order costs
                    $cost_query = $pdo->prepare("SELECT cost_items, total_cost FROM repair_costs WHERE operation_id = ?");
                    $cost_query->execute([$work_order_id]);
                    $cost_data = $cost_query->fetch(PDO::FETCH_ASSOC);
                    
                    if ($cost_data) {
                        // Create invoice
                        $invoice_stmt = $pdo->prepare("
                            INSERT INTO invoices (laptop_id, operation_id, invoice_number, invoice_date, total_amount, created_by_user_id, approval_notes) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $invoice_stmt->execute([$laptop_id, $work_order_id, $invoice_number, $invoice_date, $cost_data['total_cost'], $user_id, $notes]);
                        $invoice_id = $pdo->lastInsertId();

                        // Create invoice items
                        $cost_items = json_decode($cost_data['cost_items'], true);
                        $item_stmt = $pdo->prepare("
                            INSERT INTO invoice_items (invoice_id, description, original_cost, final_cost) 
                            VALUES (?, ?, ?, ?)
                        ");
                        
                        foreach ($cost_items as $item) {
                            $item_stmt->execute([
                                $invoice_id, 
                                $item['description'], 
                                (float)($item['cost']), 
                                (float)($item['cost'])
                            ]);
                        }

                        // Log approval action
                        $log_stmt = $pdo->prepare("
                            INSERT INTO approval_logs (invoice_id, user_id, action, new_amount, notes) 
                            VALUES (?, ?, 'created', ?, ?)
                        ");
                        $log_stmt->execute([$invoice_id, $user_id, $cost_data['total_cost'], 'تم إنشاء الفاتورة']);
                    }
                }

                // If it's approving an invoice, process approval
                if ($repair_result === 'اعتماد فاتورة') {
                    $invoice_id = $_POST['invoice_id'] ?? 0;
                    $approved_amount = $_POST['approved_amount'] ?? 0;
                    $approval_notes = $_POST['approval_notes'] ?? '';
                    
                    if (empty($invoice_id) || empty($approved_amount)) {
                        throw new Exception('بيانات الاعتماد غير مكتملة.');
                    }

                    // Update invoice status
                    $update_stmt = $pdo->prepare("
                        UPDATE invoices 
                        SET status = 'approved', approved_amount = ?, approved_by_user_id = ?, approval_date = NOW(), approval_notes = ?
                        WHERE invoice_id = ?
                    ");
                    $update_stmt->execute([$approved_amount, $user_id, $approval_notes, $invoice_id]);

                    // Log approval action
                    $log_stmt = $pdo->prepare("
                        INSERT INTO approval_logs (invoice_id, user_id, action, old_amount, new_amount, notes) 
                        VALUES (?, ?, 'approved', ?, ?, ?)
                    ");
                    
                    // Get old amount for comparison
                    $old_amount_query = $pdo->prepare("SELECT total_amount FROM invoices WHERE invoice_id = ?");
                    $old_amount_query->execute([$invoice_id]);
                    $old_amount = $old_amount_query->fetchColumn();
                    
                    $log_stmt->execute([$invoice_id, $user_id, $old_amount, $approved_amount, $approval_notes]);
                }

                // Handle image upload if present
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = 'uploads/operations/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $file_name = 'operation_' . $operation_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $file_path)) {
                        // Update operation with image path
                        $update_stmt = $pdo->prepare("UPDATE operations SET image_path = ? WHERE operation_id = ?");
                        $update_stmt->execute([$file_path, $operation_id]);
                    }
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'تم تسجيل العملية بنجاح.']);

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Add Operation/Cost Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم.']);
            }
        }
        break;

    // ========================================================================
    case 'create_invoice':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
            $laptop_id = $_POST['laptop_id'] ?? 0;
            $operation_id = $_POST['operation_id'] ?? 0;
            $invoice_number = $_POST['invoice_number'] ?? '';
            $invoice_date = $_POST['invoice_date'] ?? '';
            $items_json = $_POST['items'] ?? '[]';
            $notes = $_POST['notes'] ?? '';
            $user_id = $_SESSION['user_id'];
            
            if (empty($laptop_id) || empty($operation_id) || empty($invoice_number) || empty($invoice_date)) {
                echo json_encode(['success' => false, 'message' => 'بيانات الفاتورة غير مكتملة.']); exit;
            }

            $pdo->beginTransaction();
            try {
                // إنشاء الفاتورة
                $invoice_stmt = $pdo->prepare("
                    INSERT INTO invoices (laptop_id, operation_id, invoice_number, invoice_date, total_amount, created_by_user_id, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $items = json_decode($items_json, true);
                $total_amount = 0;
                foreach ($items as $item) {
                    $cost = parseFloat($item['modified_cost']) > 0 ? parseFloat($item['modified_cost']) : parseFloat($item['original_cost']);
                    $total_amount += $cost;
                }
                
                $invoice_stmt->execute([$laptop_id, $operation_id, $invoice_number, $invoice_date, $total_amount, $user_id, $notes]);
                $invoice_id = $pdo->lastInsertId();

                // إضافة بنود الفاتورة
                $item_stmt = $pdo->prepare("
                    INSERT INTO invoice_items (invoice_id, description, original_cost, modified_cost, final_cost, notes) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($items as $item) {
                    $original_cost = parseFloat($item['original_cost']);
                    $modified_cost = parseFloat($item['modified_cost']) > 0 ? parseFloat($item['modified_cost']) : null;
                    $final_cost = $modified_cost ?: $original_cost;
                    
                    $item_stmt->execute([
                        $invoice_id, 
                        $item['description'], 
                        $original_cost, 
                        $modified_cost, 
                        $final_cost,
                        $notes
                    ]);
                }

                // تسجيل العملية في سجل الاعتمادات
                $log_stmt = $pdo->prepare("
                    INSERT INTO approval_logs (invoice_id, user_id, action, new_amount, notes) 
                    VALUES (?, ?, 'created', ?, ?)
                ");
                $log_stmt->execute([$invoice_id, $user_id, $total_amount, 'تم إنشاء الفاتورة']);

                $pdo->commit();
                echo json_encode([
                    'success' => true, 
                    'message' => 'تم إنشاء الفاتورة بنجاح.',
                    'invoice_id' => $invoice_id,
                    'total_amount' => $total_amount
                ]);

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Create Invoice Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم: ' . $e->getMessage()]);
            }
        }
        break;

    // ========================================================================
    case 'search_work_order':
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['user_id'])) {
            $search = $_GET['search'] ?? '';
            
            if (empty($search)) {
                echo json_encode(['success' => false, 'message' => 'نص البحث مطلوب.']); exit;
            }

            try {
                $query = $pdo->prepare("
                    SELECT o.operation_id, o.work_order_ref, o.operation_date, o.status,
                           rc.total_cost, rc.cost_items
                    FROM operations o
                    LEFT JOIN repair_costs rc ON o.operation_id = rc.operation_id
                    WHERE o.repair_result = 'امر شغل' 
                    AND (o.work_order_ref LIKE ? OR o.operation_id IN (
                        SELECT DISTINCT o2.operation_id 
                        FROM operations o2 
                        JOIN broken_laptops bl ON o2.laptop_id = bl.laptop_id
                        WHERE bl.serial_number LIKE ? OR bl.employee_name LIKE ?
                    ))
                    ORDER BY o.operation_date DESC
                    LIMIT 1
                ");
                
                $search_term = '%' . $search . '%';
                $query->execute([$search_term, $search_term, $search_term]);
                $work_order = $query->fetch(PDO::FETCH_ASSOC);
                
                if ($work_order) {
                    // تحويل cost_items من JSON إلى مصفوفة
                    $cost_items = json_decode($work_order['cost_items'], true) ?: [];
                    $work_order['cost_items'] = $cost_items;
                    
                    echo json_encode([
                        'success' => true,
                        'work_order' => $work_order
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'لم يتم العثور على أمر شغل.']);
                }
                
            } catch (Exception $e) {
                error_log("Search Work Order Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم.']);
            }
        }
        break;

    // ========================================================================
    case 'search_invoice':
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['user_id'])) {
            $search = $_GET['search'] ?? '';
            
            if (empty($search)) {
                echo json_encode(['success' => false, 'message' => 'نص البحث مطلوب.']); exit;
            }

            try {
                $query = $pdo->prepare("
                    SELECT i.*, o.work_order_ref, bl.serial_number, bl.employee_name
                    FROM invoices i
                    JOIN operations o ON i.operation_id = o.operation_id
                    JOIN broken_laptops bl ON i.laptop_id = bl.laptop_id
                    WHERE i.invoice_number LIKE ? OR o.work_order_ref LIKE ?
                    ORDER BY i.created_at DESC
                    LIMIT 1
                ");
                
                $search_term = '%' . $search . '%';
                $query->execute([$search_term, $search_term]);
                $invoice = $query->fetch(PDO::FETCH_ASSOC);
                
                if ($invoice) {
                    // جلب بنود الفاتورة
                    $items_query = $pdo->prepare("
                        SELECT item_id, description, original_cost, modified_cost, final_cost
                        FROM invoice_items
                        WHERE invoice_id = ?
                    ");
                    $items_query->execute([$invoice['invoice_id']]);
                    $invoice['items'] = $items_query->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'success' => true,
                        'invoice' => $invoice
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'لم يتم العثور على فاتورة.']);
                }
                
            } catch (Exception $e) {
                error_log("Search Invoice Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم.']);
            }
        }
        break;

    // ========================================================================
    case 'get_available_work_orders':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $laptop_id = $_GET['laptop_id'] ?? 0;
            
            if (empty($laptop_id)) {
                echo json_encode(['success' => false, 'message' => 'معرف الجهاز مطلوب.']); exit;
            }

            try {
                // أولاً، نتحقق من وجود جدول invoices
                $table_exists = $pdo->query("SHOW TABLES LIKE 'invoices'")->rowCount() > 0;
                
                if ($table_exists) {
                    $query = $pdo->prepare("
                        SELECT o.operation_id, o.work_order_ref, o.operation_date,
                               rc.total_cost, rc.cost_items, u.username as engineer_name
                        FROM operations o
                        LEFT JOIN repair_costs rc ON o.operation_id = rc.operation_id
                        LEFT JOIN users u ON o.user_id = u.user_id
                        WHERE o.laptop_id = ? 
                        AND o.repair_result = 'امر شغل'
                        AND o.operation_id NOT IN (
                            SELECT DISTINCT operation_id FROM invoices WHERE operation_id IS NOT NULL
                        )
                        ORDER BY o.operation_date DESC
                    ");
                } else {
                    // إذا لم يكن جدول invoices موجود، نعرض جميع أوامر الشغل
                    $query = $pdo->prepare("
                        SELECT o.operation_id, o.work_order_ref, o.operation_date,
                               rc.total_cost, rc.cost_items, u.username as engineer_name
                        FROM operations o
                        LEFT JOIN repair_costs rc ON o.operation_id = rc.operation_id
                        LEFT JOIN users u ON o.user_id = u.user_id
                        WHERE o.laptop_id = ? 
                        AND o.repair_result = 'امر شغل'
                        ORDER BY o.operation_date DESC
                    ");
                }
                
                $query->execute([$laptop_id]);
                $work_orders = $query->fetchAll(PDO::FETCH_ASSOC);
                
                // تسجيل للتحقق
                error_log("Found " . count($work_orders) . " work orders for laptop_id: $laptop_id");
                
                // تحويل cost_items من JSON إلى مصفوفة
                foreach ($work_orders as &$work_order) {
                    $work_order['cost_items'] = json_decode($work_order['cost_items'] ?? '[]', true) ?: [];
                }
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'work_orders' => $work_orders
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                
            } catch (Exception $e) {
                error_log("Get Available Work Orders Error: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم: ' . $e->getMessage()]);
            }
        }
        break;

    // ========================================================================
    case 'get_device_invoices':
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['user_id'])) {
            $laptop_id = $_GET['laptop_id'] ?? 0;
            
            if (empty($laptop_id)) {
                echo json_encode(['success' => false, 'message' => 'معرف الجهاز مطلوب.']); exit;
            }

            try {
                $query = $pdo->prepare("
                    SELECT i.*, o.work_order_ref, u1.username as created_by_name, u2.username as approved_by_name
                    FROM invoices i
                    JOIN operations o ON i.operation_id = o.operation_id
                    LEFT JOIN users u1 ON i.created_by_user_id = u1.user_id
                    LEFT JOIN users u2 ON i.approved_by_user_id = u2.user_id
                    WHERE i.laptop_id = ?
                    ORDER BY i.created_at DESC
                ");
                
                $query->execute([$laptop_id]);
                $invoices = $query->fetchAll(PDO::FETCH_ASSOC);
                
                // جلب بنود كل فاتورة
                foreach ($invoices as &$invoice) {
                    $items_query = $pdo->prepare("
                        SELECT item_id, description, original_cost, modified_cost, final_cost
                        FROM invoice_items
                        WHERE invoice_id = ?
                    ");
                    $items_query->execute([$invoice['invoice_id']]);
                    $invoice['items'] = $items_query->fetchAll(PDO::FETCH_ASSOC);
                }
                
                echo json_encode([
                    'success' => true,
                    'invoices' => $invoices
                ]);
                
            } catch (Exception $e) {
                error_log("Get Device Invoices Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم.']);
            }
        }
        break;

        // ... (الكود السابق في الملف) ...

    // = a======================================================================
    // Case: Fetch, filter, and paginate laptops for the main view
    // ========================================================================
    case 'get_laptops':
        $page = (int)($_GET['page'] ?? 1);
        $limit = 20; // Number of items per page
        $offset = ($page - 1) * $limit;

        $filters = [
            'search' => $_GET['search'] ?? '',
            'status' => $_GET['status'] ?? '',
            'assigned_user_id' => $_GET['assigned_user_id'] ?? ''
        ];

        $base_query = "FROM broken_laptops b 
                       LEFT JOIN users u_assigned ON b.assigned_user_id = u_assigned.user_id
                       LEFT JOIN categories c ON b.category_id = c.category_id
                       LEFT JOIN (SELECT laptop_id, MIN(operation_date) as entry_date FROM operations GROUP BY laptop_id) o ON b.laptop_id = o.laptop_id";
        
        $where_clauses = ["1=1"];
        $params = [];

        if (!empty($filters['search'])) {
            $search_term = '%' . $filters['search'] . '%';
            $where_clauses[] = "(b.laptop_id LIKE ? OR b.serial_number LIKE ? OR b.specs LIKE ? OR b.employee_name LIKE ?)";
            array_push($params, $search_term, $search_term, $search_term, $search_term);
        }
        if (!empty($filters['status'])) {
            $where_clauses[] = "b.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['assigned_user_id'])) {
            $where_clauses[] = "b.assigned_user_id = ?";
            $params[] = $filters['assigned_user_id'];
        }

        $where_sql = "WHERE " . implode(" AND ", $where_clauses);

        // Get total count for pagination
        $count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT b.laptop_id) $base_query $where_sql");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();

        // Get paginated data
        $data_stmt = $pdo->prepare("
            SELECT 
                b.laptop_id, b.serial_number, b.specs, b.employee_name, b.status, b.problem_details,
                u_assigned.username AS assigned_to,
                c.category_name,
                o.entry_date,
                (SELECT COUNT(*) FROM operations op WHERE op.laptop_id = b.laptop_id) as operations_count,
                (SELECT COUNT(*) FROM complaints co WHERE co.laptop_id = b.laptop_id) as complaints_count
            $base_query $where_sql 
            ORDER BY b.laptop_id DESC 
            LIMIT ? OFFSET ?
        ");
        
        $data_params = array_merge($params, [$limit, $offset]);
        $data_stmt->execute($data_params);
        $laptops = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'laptops' => $laptops,
            'total_records' => $total_records,
            'has_more' => ($offset + count($laptops)) < $total_records
        ]);
        break;

// ... (الكود السابق في الملف) ...

    // ========================================================================
    // Case: Assign a ticket to a technician
    // ========================================================================
    case 'assign_ticket':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            $laptop_id = $_POST['laptop_id'] ?? 0;
            $assign_to_user_id = $_POST['assign_to_user_id'] ?? 0;

            if (empty($laptop_id) || empty($assign_to_user_id)) {
                echo json_encode(['success' => false, 'message' => 'بيانات غير مكتملة.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // 1. Update the laptop record
                $stmt = $pdo->prepare("UPDATE broken_laptops SET assigned_user_id = ?, status = 'assigned' WHERE laptop_id = ?");
                $stmt->execute([$assign_to_user_id, $laptop_id]);

                // 2. Get info for notification
                $info_stmt = $pdo->prepare("SELECT serial_number FROM broken_laptops WHERE laptop_id = ?");
                $info_stmt->execute([$laptop_id]);
                $laptop_info = $info_stmt->fetch();
                $ticket_ref = $laptop_info['serial_number'] ?: ('جهاز رقم ' . $laptop_id);

                $assigner_username = $_SESSION['username'];

                // 3. Create an operation log
                $log_stmt = $pdo->prepare("INSERT INTO operations (laptop_id, user_id, repair_result, details) VALUES (?, ?, ?, ?)");
                $log_details = "تم تعيين الجهاز للفني بواسطة " . $assigner_username;
                $log_stmt->execute([$laptop_id, $_SESSION['user_id'], 'تم تعيين المهمة', $log_details]);

                // 4. Send notification to the technician
                $notification_message = "مهمة جديدة: تم تعيين الجهاز " . htmlspecialchars($ticket_ref) . " لك.";
                $notification_link = "laptop_chat.php?laptop_id=" . $laptop_id;
                $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                $notif_stmt->execute([$assign_to_user_id, $notification_message, $notification_link]);
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'تم تعيين الجهاز بنجاح.']);

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Assign task error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم.']);
            }
        }
        break;

     

    // ========================================================================
    // Case: Save a user's Firebase Cloud Messaging (FCM) token
    // ========================================================================
    case 'save_fcm_token':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Support both application/json and form-encoded POST
            $token = '';
            if (!empty($_POST['token'])) {
                $token = $_POST['token'];
            } else {
                $raw = file_get_contents('php://input');
                if ($raw) {
                    $json = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && !empty($json['token'])) {
                        $token = $json['token'];
                    }
                }
            }

            if (!empty($token)) {
                // Check if token already exists for this user to avoid duplicates
                $stmt = $pdo->prepare("SELECT token_id FROM fcm_tokens WHERE user_id = ? AND token = ?");
                $stmt->execute([$_SESSION['user_id'], $token]);
                if (!$stmt->fetch()) {
                    // Insert the new token
                    $insert_stmt = $pdo->prepare("INSERT INTO fcm_tokens (user_id, token) VALUES (?, ?)");
                    $insert_stmt->execute([$_SESSION['user_id'], $token]);
                }
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Token is empty.']);
            }
        }
        break;
// ... (الكود السابق في الملف) ...

    // ========================================================================
    // Case: Create a new inventory transfer from the transfers page
    // ========================================================================
    case 'create_transfer':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            $laptop_id = $_POST['laptop_id'] ?? '';
            $receive_user_id = (int)($_POST['receive_user_id'] ?? 0);
            $transfer_ref = trim($_POST['transfer_ref']) ?: null;
            $user_id = $_SESSION['user_id'];

            if (empty($laptop_id) || empty($receive_user_id)) {
                echo json_encode(['success' => false, 'message' => 'رقم التذكرة والمستلم حقول مطلوبة.']); exit;
            }

            // Check if laptop exists and is not locked
            $laptop_check_stmt = $pdo->prepare("SELECT status FROM broken_laptops WHERE laptop_id = ?");
            $laptop_check_stmt->execute([$laptop_id]);
            $laptop_status = $laptop_check_stmt->fetchColumn();

            if (!$laptop_status) {
                echo json_encode(['success' => false, 'message' => 'رقم التذكرة غير موجود.']); exit;
            }
            if (in_array($laptop_status, ['locked', 'مغلق', 'جاهز للبيع', 'لم يتم الإصلاح'])) {
                echo json_encode(['success' => false, 'message' => 'لا يمكن تحويل جهاز مغلق.']); exit;
            }

            $pdo->beginTransaction();
            try {
                // 1. Create the transfer record
                $transfer_stmt = $pdo->prepare(
                    "INSERT INTO inventory_transfers (laptop_id, transfer_ref, transfer_user_id, receive_user_id) VALUES (?, ?, ?, ?)"
                );
                $transfer_stmt->execute([$laptop_id, $transfer_ref, $user_id, $receive_user_id]);
                $transfer_id = $pdo->lastInsertId();

                // 2. Create an operation log
                $log_stmt = $pdo->prepare("INSERT INTO operations (laptop_id, user_id, repair_result, details) VALUES (?, ?, ?, ?)");
                $receiver_info = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
                $receiver_info->execute([$receive_user_id]);
                $receiver_name = $receiver_info->fetchColumn();
                $log_details = "تم تحويل الجهاز إلى: " . $receiver_name;
                $log_stmt->execute([$laptop_id, $user_id, 'تحويل مخزني', $log_details]);

                // 3. Send notification
                // require_once 'notifications_helper.php';
                $notification_message = "لديك جهاز بانتظار تأكيد الاستلام برقم تذكرة: " . $laptop_id;
                $notification_link = "transfers.php";
                // send_fcm_notification($pdo, $receive_user_id, 'تحويل مخزني جديد', $notification_message, $notification_link);

                $pdo->commit();
                
                // 4. Fetch the newly created transfer to return to frontend
                $new_transfer_stmt = $pdo->prepare("
                    SELECT t.transfer_id, t.laptop_id, t.transfer_ref, t.transfer_date, 
                    receiver.username as receiver_name, t.is_received, t.receive_date, bl.specs
                    FROM inventory_transfers t
                    JOIN users receiver ON t.receive_user_id = receiver.user_id
                    LEFT JOIN broken_laptops bl ON t.laptop_id = bl.laptop_id
                    WHERE t.transfer_id = ?
                ");
                $new_transfer_stmt->execute([$transfer_id]);
                $new_transfer = $new_transfer_stmt->fetch(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'message' => 'تم إنشاء التحويل بنجاح.', 'transfer' => $new_transfer]);

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Create Transfer Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم.']);
            }
        }
        break;
        // ... (الكود السابق في الملف) ...
// ... (الكود السابق في الملف) ...
// ... (الكود السابق في الملف) ...
// ... (الكود السابق في الملف) ...

    // ========================================================================
    // Case: Confirm receipt of a transfer (FIXED & IMPROVED)
    // ========================================================================
    case 'confirm_receipt':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
            $transfer_id = (int)($_POST['transfer_id'] ?? 0);
            $receiver_id = $_SESSION['user_id'];

            if ($transfer_id > 0) {
                $pdo->beginTransaction();
                try {
                    // First, get transfer info to ensure the current user is the intended receiver
                    $info_stmt = $pdo->prepare("SELECT laptop_id, transfer_user_id FROM inventory_transfers WHERE transfer_id = ? AND receive_user_id = ? AND is_received = 0");
                    $info_stmt->execute([$transfer_id, $receiver_id]);
                    $transfer_info = $info_stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$transfer_info) {
                        throw new Exception('فشل تأكيد الاستلام. قد تكون لا تملك الصلاحية أو أن الجهاز تم استلامه مسبقاً.');
                    }

                    // 1. Update the transfer record
                    $update_stmt = $pdo->prepare("
                        UPDATE inventory_transfers 
                        SET is_received = 1, receive_date = CURRENT_TIMESTAMP 
                        WHERE transfer_id = ?
                    ");
                    $update_stmt->execute([$transfer_id]);

                    // 2. Create an operation log for the receipt
                    $log_stmt = $pdo->prepare("INSERT INTO operations (laptop_id, user_id, repair_result, details) VALUES (?, ?, ?, ?)");
                    $log_stmt->execute([$transfer_info['laptop_id'], $receiver_id, 'استلام مخزني', 'تم تأكيد استلام الجهاز المحول.']);
                    
                    // 3. [IMPROVED] Safely attempt to send a notification back to the original sender
                    if (file_exists('notifications_helper.php')) {
                        require_once 'notifications_helper.php';
                        try {
                            $sender_id = $transfer_info['transfer_user_id'];
                            $notification_message = "تم تأكيد استلام الجهاز برقم تذكرة " . $transfer_info['laptop_id'] . " الذي قمت بتحويله.";
                            $notification_link = "operations.php?laptop_id=" . $transfer_info['laptop_id'];
                            send_fcm_notification($pdo, $sender_id, 'تم استلام التحويل', $notification_message, $notification_link);
                        } catch (Exception $e) {
                            // Log the notification error but don't stop the main process
                            error_log("FCM Notification failed but receipt was confirmed: " . $e->getMessage());
                        }
                    }

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'تم تأكيد الاستلام بنجاح.']);

                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'معرف التحويل غير صالح.']);
            }
        }
        break;

// ... (الكود المتبقي في الملف) ...
// ... (الكود السابق في الملف) ...

    // ========================================================================
    // Case: Create a new inventory transfer from the transfers page (FIXED & IMPROVED)
    // ========================================================================
    case 'create_transfer':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            $laptop_id = $_POST['laptop_id'] ?? '';
            $receive_user_id = (int)($_POST['receive_user_id'] ?? 0);
            $transfer_ref = trim($_POST['transfer_ref']) ?: null;
            $user_id = $_SESSION['user_id'];

            if (empty($laptop_id) || empty($receive_user_id)) {
                echo json_encode(['success' => false, 'message' => 'رقم التذكرة والمستلم حقول مطلوبة.']); exit;
            }

            // Check if laptop exists and is not locked
            $laptop_check_stmt = $pdo->prepare("SELECT status FROM broken_laptops WHERE laptop_id = ?");
            $laptop_check_stmt->execute([$laptop_id]);
            $laptop_status = $laptop_check_stmt->fetchColumn();

            if (!$laptop_status) {
                echo json_encode(['success' => false, 'message' => 'رقم التذكرة غير موجود.']); exit;
            }
            if (in_array($laptop_status, ['locked', 'مغلق', 'جاهز للبيع', 'لم يتم الإصلاح'])) {
                echo json_encode(['success' => false, 'message' => 'لا يمكن تحويل جهاز مغلق.']); exit;
            }

            $pdo->beginTransaction();
            try {
                // 1. Create the transfer record
                $transfer_stmt = $pdo->prepare(
                    "INSERT INTO inventory_transfers (laptop_id, transfer_ref, transfer_user_id, receive_user_id) VALUES (?, ?, ?, ?)"
                );
                $transfer_stmt->execute([$laptop_id, $transfer_ref, $user_id, $receive_user_id]);
                $transfer_id = $pdo->lastInsertId();

                // 2. Create an operation log
                $log_stmt = $pdo->prepare("INSERT INTO operations (laptop_id, user_id, repair_result, details) VALUES (?, ?, ?, ?)");
                $receiver_info = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
                $receiver_info->execute([$receive_user_id]);
                $receiver_name = $receiver_info->fetchColumn();
                $log_details = "تم تحويل الجهاز إلى: " . $receiver_name;
                $log_stmt->execute([$laptop_id, $user_id, 'تحويل مخزني', $log_details]);

                // 3. [IMPROVED] Safely attempt to send a notification
                if (file_exists('notifications_helper.php')) {
                    require_once 'notifications_helper.php';
                    try {
                        $notification_message = "لديك جهاز بانتظار تأكيد الاستلام برقم تذكرة: " . $laptop_id;
                        $notification_link = "transfers.php";
                        send_fcm_notification($pdo, $receive_user_id, 'تحويل مخزني جديد', $notification_message, $notification_link);
                    } catch (Exception $e) {
                        error_log("FCM Notification failed during transfer creation: " . $e->getMessage());
                    }
                }

                $pdo->commit();
                
                // 4. Fetch the newly created transfer to return to frontend
                $new_transfer_stmt = $pdo->prepare("
                    SELECT t.transfer_id, t.laptop_id, t.transfer_ref, t.transfer_date, 
                    receiver.username as receiver_name, t.is_received, t.receive_date, bl.specs
                    FROM inventory_transfers t
                    JOIN users receiver ON t.receive_user_id = receiver.user_id
                    LEFT JOIN broken_laptops bl ON t.laptop_id = bl.laptop_id
                    WHERE t.transfer_id = ?
                ");
                $new_transfer_stmt->execute([$transfer_id]);
                $new_transfer = $new_transfer_stmt->fetch(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'message' => 'تم إنشاء التحويل بنجاح.', 'transfer' => $new_transfer]);

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Create Transfer Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم.']);
            }
        }
        break;
 
    // ========================================================================
    // Case: Get device location tracking report data (ADVANCED & SAFER VERSION)
    // ========================================================================
    case 'get_device_locations':
        if (!in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            echo json_encode(['error' => 'Access Denied']); exit;
        }

        $branch = $_GET['branch'] ?? '';
        $current_holder_id = $_GET['user_id'] ?? '';

        // This query is complex but more compatible. It determines the current and previous holder of each device.
        $sql = "
            WITH ranked_events AS (
                SELECT
                    e.laptop_id,
                    e.event_date,
                    e.holder_id,
                    u.username AS holder_name,
                    u.branch_name,
                    ROW_NUMBER() OVER(PARTITION BY e.laptop_id ORDER BY e.event_date DESC, e.event_id DESC) as rn
                FROM (
                    -- Event 1: Initial Entry (from first operation)
                    SELECT 
                        o.laptop_id, o.operation_date AS event_date, b.entered_by_user_id AS holder_id, o.operation_id as event_id
                    FROM operations o
                    JOIN broken_laptops b ON o.laptop_id = b.laptop_id
                    WHERE o.operation_id = (SELECT MIN(op.operation_id) FROM operations op WHERE op.laptop_id = o.laptop_id)

                    UNION ALL

                    -- Event 2: Assignment
                    SELECT 
                        o.laptop_id, o.operation_date AS event_date, b.assigned_user_id AS holder_id, o.operation_id as event_id
                    FROM operations o
                    JOIN broken_laptops b ON o.laptop_id = b.laptop_id
                    WHERE o.repair_result = 'تم تعيين المهمة' AND b.assigned_user_id IS NOT NULL

                    UNION ALL

                    -- Event 3: Confirmed Transfer
                    SELECT 
                        t.laptop_id, t.receive_date AS event_date, t.receive_user_id AS holder_id, t.transfer_id as event_id
                    FROM inventory_transfers t
                    WHERE t.is_received = 1 AND t.receive_date IS NOT NULL
                ) e
                JOIN users u ON e.holder_id = u.user_id
            ),
            current_holders AS (
                SELECT * FROM ranked_events WHERE rn = 1
            ),
            previous_holders AS (
                SELECT * FROM ranked_events WHERE rn = 2
            )
            SELECT 
                b.laptop_id, b.specs, b.status,
                ch.holder_name AS current_holder,
                ch.branch_name,
                ch.event_date AS date_with_current,
                ph.holder_name AS previous_holder,
                ph.event_date AS date_with_previous,
                (SELECT MAX(op.operation_date) FROM operations op WHERE op.laptop_id = b.laptop_id) as last_event_date
            FROM broken_laptops b
            LEFT JOIN current_holders ch ON b.laptop_id = ch.laptop_id
            LEFT JOIN previous_holders ph ON b.laptop_id = ph.laptop_id
            WHERE ch.holder_name IS NOT NULL -- Ensure we only get devices with a determined holder
        ";

        $params = [];
        $where_clauses = [];

        if (!empty($branch)) {
            $where_clauses[] = "ch.branch_name = ?";
            $params[] = $branch;
        }
        if (!empty($current_holder_id)) {
            $where_clauses[] = "ch.holder_id = ?";
            $params[] = $current_holder_id;
        }

        if (!empty($where_clauses)) {
            $sql .= " AND " . implode(" AND ", $where_clauses);
        }
        
        $sql .= " ORDER BY ch.event_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $laptops = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate durations in PHP for better formatting
        $results = array_map(function($laptop) {
            $now = new DateTime();
            $date_with_current = new DateTime($laptop['date_with_current']);
            $interval_current = $now->diff($date_with_current);
            $laptop['time_with_current'] = $interval_current->format('%a يوم, %h س');

            if ($laptop['date_with_previous']) {
                $date_with_previous = new DateTime($laptop['date_with_previous']);
                $interval_previous = $date_with_current->diff($date_with_previous);
                $laptop['time_with_previous'] = $interval_previous->format('%a يوم, %h س');
            } else {
                $laptop['time_with_previous'] = 'لا يوجد سجل سابق';
            }
            return $laptop;
        }, $laptops);

        echo json_encode($results);
        break;

// ... (قبل default case) ...

    // ========================================================================
    // Case: Get device location tracking report data (ADVANCED VERSION)
    // ========================================================================
    case 'get_device_locations':
        if (!in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            echo json_encode(['error' => 'Access Denied']); exit;
        }

        $branch = $_GET['branch'] ?? '';
        $current_holder_id = $_GET['user_id'] ?? '';

        // This is a complex query that builds a full event history for all devices
        // to accurately determine the current and previous holder, and the duration with each.
        $sql = "
            WITH ranked_events AS (
                SELECT
                    e.laptop_id,
                    e.event_date,
                    e.holder_id,
                    u.username AS holder_name,
                    u.branch_name,
                    ROW_NUMBER() OVER(PARTITION BY e.laptop_id ORDER BY e.event_date DESC) as rn
                FROM (
                    -- Event Type 1: Initial Entry
                    SELECT 
                        b.laptop_id,
                        b.creation_date AS event_date, 
                        b.entered_by_user_id AS holder_id
                    FROM broken_laptops b

                    UNION ALL

                    -- Event Type 2: Assignment
                    SELECT 
                        o.laptop_id,
                        o.operation_date AS event_date,
                        b.assigned_user_id AS holder_id
                    FROM operations o
                    JOIN broken_laptops b ON o.laptop_id = b.laptop_id
                    WHERE o.repair_result = 'تم تعيين المهمة' AND b.assigned_user_id IS NOT NULL

                    UNION ALL

                    -- Event Type 3: Confirmed Transfer
                    SELECT 
                        t.laptop_id,
                        t.receive_date AS event_date,
                        t.receive_user_id AS holder_id
                    FROM inventory_transfers t
                    WHERE t.is_received = 1
                ) e
                JOIN users u ON e.holder_id = u.user_id
            ),
            current_holders AS (
                SELECT * FROM ranked_events WHERE rn = 1
            ),
            previous_holders AS (
                SELECT * FROM ranked_events WHERE rn = 2
            )
            SELECT 
                b.laptop_id,
                b.specs,
                b.status,
                ch.holder_name AS current_holder,
                ch.branch_name,
                ch.event_date AS date_with_current,
                ph.event_date AS date_with_previous
            FROM broken_laptops b
            JOIN current_holders ch ON b.laptop_id = ch.laptop_id
            LEFT JOIN previous_holders ph ON b.laptop_id = ph.laptop_id
        ";

        $params = [];
        $where_clauses = [];

        if (!empty($branch)) {
            $where_clauses[] = "ch.branch_name = ?";
            $params[] = $branch;
        }
        if (!empty($current_holder_id)) {
            $where_clauses[] = "ch.holder_id = ?";
            $params[] = $current_holder_id;
        }

        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(" AND ", $where_clauses);
        }
        
        $sql .= " ORDER BY ch.event_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $laptops = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate durations in PHP for better formatting
        $results = array_map(function($laptop) {
            $now = new DateTime();
            $date_with_current = new DateTime($laptop['date_with_current']);
            $interval_current = $now->diff($date_with_current);
            $laptop['time_with_current'] = $interval_current->format('%a يوم, %h س');

            if ($laptop['date_with_previous']) {
                $date_with_previous = new DateTime($laptop['date_with_previous']);
                $interval_previous = $date_with_current->diff($date_with_previous);
                $laptop['time_with_previous'] = $interval_previous->format('%a يوم, %h س');
            } else {
                $laptop['time_with_previous'] = 'لا يوجد سجل سابق';
            }
            return $laptop;
        }, $laptops);

        echo json_encode($results);
        break;

// ... (قبل default case) ...

// ... (قبل default case) ...

// ... (الكود السابق في الملف) ...

    // ========================================================================
    // Case: Add a new item specification on-the-fly
    // ========================================================================
    case 'add_new_spec':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager', 'storekeeper', 'sales', 'prep_engineer'])) {
            $item_number = trim($_POST['item_number'] ?? '');
            $specs = trim($_POST['specs'] ?? '');

            if (empty($item_number) || empty($specs)) {
                echo json_encode(['success' => false, 'message' => 'رقم الصنف والمواصفات حقول مطلوبة.']);
                exit;
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO item_specifications (item_number, specs) VALUES (?, ?)");
                $stmt->execute([$item_number, $specs]);
                echo json_encode(['success' => true, 'message' => 'تم حفظ الصنف الجديد بنجاح.']);
            } catch (PDOException $e) {
                // Error code 23000 is for integrity constraint violation (like a duplicate key)
                if ($e->getCode() == 23000) {
                    echo json_encode(['success' => false, 'message' => 'هذا الصنف موجود مسبقاً.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'حدث خطأ في قاعدة البيانات.']);
                }
            }
        }
        break;

// ... (قبل default case) ...


    // ========================================================================
    // Case: Add or update item specification (called from add_broken_laptop when a new item number is entered)
    // ========================================================================
    case 'add_spec':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Support form-encoded POST or JSON body
            $item_number = trim($_POST['item_number'] ?? '');
            $specs = trim($_POST['specs'] ?? '');
            if ($item_number === '' || $specs === '') {
                // Try JSON body as fallback
                $raw = file_get_contents('php://input');
                if ($raw) {
                    $json = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $item_number = trim($json['item_number'] ?? $item_number);
                        $specs = trim($json['specs'] ?? $specs);
                    }
                }
            }

            if ($item_number === '' || $specs === '') {
                echo json_encode(['success' => false, 'message' => 'رقم الصنف والمواصفات مطلوبان.']);
                break;
            }

            try {
                // If table has a UNIQUE constraint on item_number this will act like upsert.
                // Otherwise perform a SELECT then INSERT or UPDATE to avoid duplicate rows.
                $check = $pdo->prepare("SELECT COUNT(*) FROM item_specifications WHERE item_number = ?");
                $check->execute([$item_number]);
                $exists = (int)$check->fetchColumn() > 0;

                if ($exists) {
                    $stmt = $pdo->prepare("UPDATE item_specifications SET specs = ? WHERE item_number = ?");
                    $stmt->execute([$specs, $item_number]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO item_specifications (item_number, specs) VALUES (?, ?)");
                    $stmt->execute([$item_number, $specs]);
                }

                echo json_encode(['success' => true, 'item_number' => $item_number, 'specs' => $specs]);
            } catch (Exception $e) {
                error_log('add_spec error: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'فشل حفظ البيانات في قاعدة البيانات.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'الطلب يجب أن يكون بواسطة POST.']);
        }
        break;

    // ========================================================================
    // Case: Test FCM notification
    // ========================================================================
    case 'test_fcm_notification':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_once 'notifications_simple.php';
            
            $input = json_decode(file_get_contents('php://input'), true);
            $title = $input['title'] ?? 'اختبار FCM';
            $message = $input['message'] ?? 'هذا اختبار لـ Firebase Cloud Messaging';
            $user_id = $input['user_id'] ?? $_SESSION['user_id'];
            $priority = $input['priority'] ?? 'normal';
            
            try {
                $success = send_fcm_notification_simple($pdo, $user_id, $title, $message, "notifications/xampp_test.php", [], $priority);
                
                if ($success) {
                    echo json_encode(['success' => true, 'message' => 'تم إرسال إشعار FCM بنجاح']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'فشل في إرسال إشعار FCM - تحقق من FCM Token']);
                }
            } catch (Exception $e) {
                error_log("Test FCM Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'فشل في إرسال إشعار FCM: ' . $e->getMessage()]);
            }
        }
        break;

    // ========================================================================
    // Case: Test broadcast notification
    // ========================================================================
    case 'test_broadcast_notification':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_once 'notifications_simple.php';
            
            $input = json_decode(file_get_contents('php://input'), true);
            $title = $input['title'] ?? 'إشعار للجميع';
            $message = $input['message'] ?? 'هذا إشعار تجريبي لجميع المستخدمين';
            $priority = $input['priority'] ?? 'normal';
            
            try {
                $result = send_broadcast_notification_simple($pdo, $title, $message, "notifications/xampp_test.php", [], $priority);
                
                if ($result && $result['success']) {
                    echo json_encode([
                        'success' => true,
                        'sent_to' => $result['sent_to'],
                        'total_users' => $result['total_users'],
                        'failed' => $result['failed'],
                        'message' => 'تم إرسال الإشعار للجميع بنجاح'
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'فشل في إرسال الإشعار للجميع']);
                }
            } catch (Exception $e) {
                error_log("Test Broadcast Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'فشل في إرسال الإشعار للجميع']);
            }
        }
        break;

    // ========================================================================
    // Case: Send notification to specific users
    // ========================================================================
    case 'send_notification':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['permissions'], ['admin', 'manager'])) {
            require_once 'notifications_helper.php';
            
            $send_type = $_POST['send_type'] ?? '';
            $title = $_POST['title'] ?? '';
            $message = $_POST['message'] ?? '';
            $link = $_POST['link'] ?? null;
            $priority = $_POST['priority'] ?? 'normal';
            
            if (empty($title) || empty($message)) {
                echo json_encode(['success' => false, 'message' => 'العنوان والرسالة مطلوبان']);
                exit;
            }
            
            try {
                $result = null;
                
                switch ($send_type) {
                    case 'broadcast':
                        $result = send_broadcast_notification($pdo, $title, $message, $link, [], $priority);
                        break;
                        
                    case 'branch':
                        $branch = $_POST['branch'] ?? '';
                        if (empty($branch)) {
                            echo json_encode(['success' => false, 'message' => 'الفرع مطلوب']);
                            exit;
                        }
                        $result = send_branch_notification($pdo, $branch, $title, $message, $link, $priority);
                        break;
                        
                    case 'permission':
                        $permissions = json_decode($_POST['permissions'] ?? '[]', true);
                        if (empty($permissions)) {
                            echo json_encode(['success' => false, 'message' => 'الصلاحيات مطلوبة']);
                            exit;
                        }
                        $result = send_permission_notification($pdo, $permissions, $title, $message, $link, $priority);
                        break;
                        
                    case 'individual':
                        $user_ids = json_decode($_POST['user_ids'] ?? '[]', true);
                        if (empty($user_ids)) {
                            echo json_encode(['success' => false, 'message' => 'المستخدمين مطلوبون']);
                            exit;
                        }
                        $result = send_group_notification($pdo, $user_ids, $title, $message, $link, $priority);
                        break;
                        
                    default:
                        echo json_encode(['success' => false, 'message' => 'نوع الإرسال غير صحيح']);
                        exit;
                }
                
                if ($result && $result['success']) {
                    // حفظ في سجل الإشعارات
                    $log_stmt = $pdo->prepare("
                        INSERT INTO notification_logs (sent_by_user_id, send_type, title, message, link, recipients_count, success_count, failed_count, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $log_stmt->execute([
                        $_SESSION['user_id'], 
                        $send_type, 
                        $title, 
                        $message, 
                        $link, 
                        $result['total_users'],
                        $result['sent_to'],
                        $result['failed'] ?? 0
                    ]);
                    
                    echo json_encode([
                        'success' => true,
                        'sent_to' => $result['sent_to'],
                        'total_users' => $result['total_users'],
                        'failed' => $result['failed'] ?? 0
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'فشل في إرسال الإشعار']);
                }
                
            } catch (Exception $e) {
                error_log("Send Notification Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'حدث خطأ في الخادم']);
            }
        }
        break;

    // ========================================================================
    // Case: Get notification statistics
    // ========================================================================
    case 'get_notification_stats':
        require_once 'notifications_helper.php';
        
        $user_id = $_GET['user_id'] ?? $_SESSION['user_id'];
        $stats = get_notification_stats($pdo, $user_id);
        echo json_encode($stats);
        break;

    // ========================================================================
    // Case: Track notification interaction
    // ========================================================================
    case 'track_notification_close':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $notification_id = $input['notification_id'] ?? null;
            $action = $input['action'] ?? 'close';
            
            // يمكن إضافة تتبع الإحصائيات هنا
            error_log("Notification $action: $notification_id");
            echo json_encode(['success' => true]);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>