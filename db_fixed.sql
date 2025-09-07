-- phpMyAdmin SQL Dump - ملف قاعدة البيانات المصحح
-- تم إصلاح مشكلة view laptop_status_report
-- يمكن استيراد هذا الملف بدون أخطاء

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

--
-- بنية الجدول `branches`
--

CREATE TABLE `branches` (
  `branch_id` int(11) NOT NULL,
  `branch_name` varchar(100) NOT NULL COMMENT 'اسم الفرع',
  `branch_code` varchar(10) NOT NULL COMMENT 'رمز الفرع',
  `location` varchar(200) DEFAULT NULL COMMENT 'موقع الفرع',
  `manager_id` int(11) DEFAULT NULL COMMENT 'مدير الفرع',
  `status` enum('active','inactive') DEFAULT 'active' COMMENT 'حالة الفرع',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='جدول الفروع';

-- --------------------------------------------------------

--
-- بنية الجدول `warehouses`
--

CREATE TABLE `warehouses` (
  `warehouse_id` int(11) NOT NULL,
  `warehouse_number` varchar(50) NOT NULL COMMENT 'رقم المخزن',
  `warehouse_name` varchar(100) NOT NULL COMMENT 'اسم المخزن',
  `warehouse_type` enum('main','branch','repair','storage') NOT NULL COMMENT 'نوع المخزن',
  `branch_id` int(11) NOT NULL COMMENT 'الفرع المرتبط',
  `manager_name` varchar(100) DEFAULT NULL COMMENT 'اسم مدير المخزن',
  `location` varchar(200) DEFAULT NULL COMMENT 'موقع المخزن',
  `status` enum('active','inactive') DEFAULT 'active' COMMENT 'حالة المخزن',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='جدول المخازن';

-- --------------------------------------------------------

--
-- بنية الجدول `warehouse_permissions`
--

CREATE TABLE `warehouse_permissions` (
  `permission_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'معرف المستخدم',
  `warehouse_id` int(11) NOT NULL COMMENT 'معرف المخزن',
  `permission_type` enum('transfer_from','transfer_to','both') NOT NULL COMMENT 'نوع الصلاحية',
  `status` enum('active','inactive') DEFAULT 'active' COMMENT 'حالة الصلاحية',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='صلاحيات المستخدمين على المخازن';

-- --------------------------------------------------------

--
-- بنية الجدول `warehouse_transfers`
--

CREATE TABLE `warehouse_transfers` (
  `transfer_id` int(11) NOT NULL,
  `laptop_id` int(11) NOT NULL COMMENT 'معرف الجهاز',
  `from_warehouse_id` int(11) NOT NULL COMMENT 'المخزن المصدر',
  `to_warehouse_id` int(11) NOT NULL COMMENT 'المخزن المقصود',
  `transfer_user_id` int(11) NOT NULL COMMENT 'المستخدم المرسل',
  `receive_user_id` int(11) DEFAULT NULL COMMENT 'المستخدم المستلم',
  `accounting_transfer_number` varchar(100) DEFAULT NULL COMMENT 'رقم التحويل المحاسبي',
  `transfer_date` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ التحويل',
  `receive_date` timestamp NULL DEFAULT NULL COMMENT 'تاريخ الاستلام',
  `transfer_status` enum('pending','in_transit','received','cancelled') DEFAULT 'pending' COMMENT 'حالة التحويل',
  `is_received` tinyint(1) DEFAULT 0 COMMENT 'تم الاستلام؟',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='جدول تحويلات المخازن';

-- --------------------------------------------------------

--
-- بنية الجدول `warehouse_transfer_logs`
--

CREATE TABLE `warehouse_transfer_logs` (
  `log_id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL COMMENT 'معرف التحويل',
  `action_type` enum('created','updated','received','cancelled') NOT NULL COMMENT 'نوع الإجراء',
  `user_id` int(11) NOT NULL COMMENT 'المستخدم الذي قام بالإجراء',
  `action_date` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإجراء',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='سجل تحويلات المخازن';

-- --------------------------------------------------------

--
-- بنية الجدول `operations`
--

CREATE TABLE `operations` (
  `operation_id` int(11) NOT NULL,
  `laptop_id` int(11) NOT NULL COMMENT 'معرف الجهاز',
  `user_id` int(11) NOT NULL COMMENT 'معرف المستخدم',
  `repair_result` varchar(255) NOT NULL COMMENT 'نوع العملية',
  `details` text DEFAULT NULL COMMENT 'تفاصيل العملية',
  `operation_date` datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ العملية',
  `image_path` varchar(500) DEFAULT NULL COMMENT 'مسار الصورة',
  `receipt_number` varchar(100) DEFAULT NULL COMMENT 'رقم الإيصال',
  `remaining_problems_count` int(11) DEFAULT 0 COMMENT 'عدد المشاكل المتبقية'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='جدول العمليات';

-- --------------------------------------------------------

--
-- بنية الجدول `broken_laptops`
--

CREATE TABLE `broken_laptops` (
  `laptop_id` int(250) NOT NULL,
  `employee_name` varchar(150) DEFAULT NULL,
  `item_number` varchar(100) DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `device_category_number` varchar(100) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `specs` text DEFAULT NULL,
  `specs_difference` text DEFAULT NULL,
  `with_charger` tinyint(1) DEFAULT 0,
  `problems_count` int(11) DEFAULT 0,
  `branch_name` varchar(100) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `problem_details` text DEFAULT NULL,
  `entered_by_user_id` int(11) DEFAULT NULL,
  `assigned_user_id` int(11) DEFAULT NULL,
  `problem_type` varchar(100) DEFAULT NULL,
  `problem_nature` varchar(100) DEFAULT NULL,
  `transfer_ref` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `repeat_problem_count` int(11) DEFAULT 0,
  `ticket_number` varchar(32) DEFAULT NULL,
  `creation_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `permissions` enum('admin','manager','user','technician') DEFAULT 'user',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `categories`
--

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `locks`
--

CREATE TABLE `locks` (
  `lock_id` int(11) NOT NULL,
  `laptop_id` int(11) NOT NULL,
  `lock_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `lock_type` varchar(100) DEFAULT NULL,
  `solution_percentage` decimal(5,2) DEFAULT NULL,
  `more_description` text DEFAULT NULL,
  `final_status` varchar(100) DEFAULT NULL,
  `hold_duration` varchar(100) DEFAULT NULL,
  `hold_reason` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `transfer_ref` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `complaints`
--

CREATE TABLE `complaints` (
  `complaint_id` int(11) NOT NULL,
  `laptop_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `complaint_text` text NOT NULL,
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `item_specifications`
--

CREATE TABLE `item_specifications` (
  `item_number` varchar(100) NOT NULL,
  `specs` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `laptop_discussions`
--

CREATE TABLE `laptop_discussions` (
  `discussion_id` int(11) NOT NULL,
  `laptop_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `inventory_transfer_logs`
--

CREATE TABLE `inventory_transfer_logs` (
  `log_id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `predefined_operations`
--

CREATE TABLE `predefined_operations` (
  `operation_id` int(11) NOT NULL,
  `operation_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `estimated_time` int(11) DEFAULT NULL COMMENT 'الوقت المقدر بالدقائق',
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `repair_costs`
--

CREATE TABLE `repair_costs` (
  `cost_id` int(11) NOT NULL,
  `laptop_id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `cost_amount` decimal(10,2) NOT NULL,
  `cost_type` enum('parts','labor','other') DEFAULT 'labor',
  `description` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- تعريف Views
--

-- View: laptop_status_report
DROP TABLE IF EXISTS `laptop_status_report`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `laptop_status_report` AS 
SELECT 
    `wt`.`laptop_id` AS `laptop_id`,
    `wt`.`receive_user_id` AS `current_holder`,
    `wt`.`is_received` AS `is_received`,
    `wt`.`transfer_date` AS `transfer_date`,
    `wt`.`receive_date` AS `receive_date`,
    `wt`.`transfer_status` AS `transfer_status`,
    `bl`.`item_number` AS `item_number`,
    `bl`.`serial_number` AS `serial_number`,
    `w_from`.`warehouse_name` AS `from_warehouse`,
    `w_to`.`warehouse_name` AS `to_warehouse`
FROM `warehouse_transfers` `wt`
JOIN `broken_laptops` `bl` ON `wt`.`laptop_id` = `bl`.`laptop_id`
JOIN `warehouses` `w_from` ON `wt`.`from_warehouse_id` = `w_from`.`warehouse_id`
JOIN `warehouses` `w_to` ON `wt`.`to_warehouse_id` = `w_to`.`warehouse_id`
WHERE `wt`.`transfer_id` = (
    SELECT MAX(`wt2`.`transfer_id`) 
    FROM `warehouse_transfers` `wt2` 
    WHERE `wt2`.`laptop_id` = `wt`.`laptop_id`
);

-- View: pending_transfers_summary
DROP TABLE IF EXISTS `pending_transfers_summary`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `pending_transfers_summary` AS 
SELECT 
    `wt`.`accounting_transfer_number` AS `accounting_transfer_number`,
    `wt`.`transfer_date` AS `transfer_date`,
    `wt`.`to_warehouse_id` AS `to_warehouse_id`,
    `w_to`.`warehouse_name` AS `to_warehouse`,
    `b_to`.`branch_name` AS `to_branch`,
    `u_transfer`.`username` AS `transferred_by`,
    COUNT(`wt`.`laptop_id`) AS `devices_count`,
    GROUP_CONCAT(DISTINCT `bl`.`serial_number` ORDER BY `bl`.`serial_number` ASC SEPARATOR ', ') AS `serial_numbers`,
    GROUP_CONCAT(DISTINCT `bl`.`item_number` ORDER BY `bl`.`item_number` ASC SEPARATOR ', ') AS `item_numbers`,
    MIN(`wt`.`transfer_date`) AS `earliest_transfer`,
    MAX(`wt`.`transfer_date`) AS `latest_transfer`
FROM `warehouse_transfers` `wt`
JOIN `broken_laptops` `bl` ON `wt`.`laptop_id` = `bl`.`laptop_id`
JOIN `warehouses` `w_to` ON `wt`.`to_warehouse_id` = `w_to`.`warehouse_id`
JOIN `branches` `b_to` ON `w_to`.`branch_id` = `b_to`.`branch_id`
JOIN `users` `u_transfer` ON `wt`.`transfer_user_id` = `u_transfer`.`user_id`
WHERE `wt`.`is_received` = 0 AND `wt`.`transfer_status` = 'in_transit'
GROUP BY `wt`.`accounting_transfer_number`, `wt`.`to_warehouse_id`
ORDER BY `wt`.`transfer_date` DESC;

-- --------------------------------------------------------

--
-- إضافة البيانات الأساسية
--

-- إضافة فروع أساسية
INSERT INTO `branches` (`branch_id`, `branch_name`, `branch_code`, `location`, `status`) VALUES
(1, 'المازن', 'MZN', 'المازن - المقر الرئيسي', 'active'),
(2, 'المتجر', 'STORE', 'المتجر - فرع صنعاء', 'active'),
(3, 'الحكمة', 'HIKMA', 'الحكمة - فرع صنعاء', 'active'),
(4, 'المازن-الحديدة', 'HODEIDA', 'المازن الحديدة - فرع الحديدة', 'active'),
(5, 'المازن-عدن', 'ADEN', 'عدن - فرع عدن', 'active'),
(6, 'عدن نون', 'NOON', 'نون - فرع عدن', 'active');

-- إضافة مخازن أساسية
INSERT INTO `warehouses` (`warehouse_id`, `warehouse_number`, `warehouse_name`, `warehouse_type`, `branch_id`, `status`) VALUES
(1, 'WH-001', 'مخزن المقر الرئيسي', 'main', 1, 'active'),
(2, 'WH-002', 'مخزن المتجر', 'branch', 2, 'active'),
(3, 'WH-003', 'مخزن الإصلاح', 'repair', 1, 'active'),
(4, 'WH-004', 'مخزن الحكمة', 'branch', 3, 'active'),
(5, 'WH-005', 'مخزن الحديدة', 'branch', 4, 'active'),
(6, 'WH-006', 'مخزن عدن', 'branch', 5, 'active');

-- إضافة مستخدم admin أساسي
INSERT INTO `users` (`user_id`, `username`, `email`, `password`, `full_name`, `permissions`, `status`) VALUES
(1, 'admin', 'admin@mazn.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مدير النظام', 'admin', 'active');

-- إضافة فئات أساسية
INSERT INTO `categories` (`category_id`, `category_name`, `description`, `status`) VALUES
(1, 'لابتوب', 'أجهزة لابتوب', 'active'),
(2, 'كمبيوتر', 'أجهزة كمبيوتر مكتبية', 'active'),
(3, 'طابعة', 'طابعات', 'active'),
(4, 'شاشة', 'شاشات', 'active');

-- إضافة مستخدمين أساسيين
INSERT INTO `users` (`user_id`, `username`, `email`, `password`, `full_name`, `permissions`, `status`) VALUES
(2, 'mazen', 'mazen@mazn.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مازن', 'admin', 'active'),
(3, 'ammar', 'ammar@mazn.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'عمار', 'manager', 'active'),
(4, 'sultan', 'sultan@mazn.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'سلطان', 'technician', 'active'),
(5, 'mustafa', 'mustafa@mazn.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مصطفى', 'technician', 'active');

-- إضافة صلاحيات أساسية للمخازن
INSERT INTO `warehouse_permissions` (`permission_id`, `user_id`, `warehouse_id`, `permission_type`, `status`) VALUES
(1, 1, 1, 'both', 'active'),  -- admin على مخزن المقر الرئيسي
(2, 1, 2, 'both', 'active'),  -- admin على مخزن المتجر
(3, 1, 3, 'both', 'active'),  -- admin على مخزن الإصلاح
(4, 2, 1, 'both', 'active'),  -- مازن على مخزن المقر الرئيسي
(5, 2, 3, 'both', 'active'),  -- مازن على مخزن الإصلاح
(6, 3, 2, 'both', 'active'),  -- عمار على مخزن المتجر
(7, 4, 1, 'transfer_from', 'active'),  -- سلطان يرسل من مخزن المقر
(8, 4, 3, 'transfer_to', 'active'),    -- سلطان يستلم في مخزن الإصلاح
(9, 5, 3, 'both', 'active');           -- مصطفى على مخزن الإصلاح

-- إضافة أجهزة معطلة أساسية للاختبار
INSERT INTO `broken_laptops` (`laptop_id`, `employee_name`, `item_number`, `category_id`, `serial_number`, `specs`, `branch_name`, `warehouse_id`, `problem_details`, `entered_by_user_id`, `problem_type`, `problem_nature`, `status`, `creation_date`) VALUES
(1, 'أحمد محمد', '22-733-001', 1, '39NRCV00524736A', 'Asus ROG Zephyrus GM501G i7-8750H 32GB 256+1TB 15.6 GTX1070 8GB', 'المتجر', 2, '[{"title":"البطارية","details":"الجهاز يحتاج بطارية جديدة"}]', 3, 'هاردوير', 'سهلة عادية', 'entered', '2025-09-03 03:00:00'),
(2, 'فاطمة علي', '19-117-101', 1, 'PF3BCDL1', 'Lenovo IdeaPad i7-11th 8GB-NO HDD 15.6inch', 'المتجر', 2, '[{"title":"الهارد ديسك","details":"الهارد معطل ويحتاج استبدال"}]', 3, 'هاردوير', 'صعبة عادية', 'entered', '2025-09-03 03:00:00'),
(3, 'محمد حسن', '22-883-001', 1, 'MP2HHVRT1', 'Lenovo Legion 5 i9-14900HX 32GB 1TB 16in RTX4060 8GB', 'المتجر', 2, '[{"title":"الشاشة","details":"الشاشة مكسورة"}]', 3, 'هاردوير', 'صعبة مستعجلة', 'entered', '2025-09-03 03:00:00');

-- إضافة عمليات أساسية للاختبار
INSERT INTO `operations` (`operation_id`, `laptop_id`, `user_id`, `repair_result`, `details`, `operation_date`) VALUES
(1, 1, 3, 'إدخال جهاز', 'تم إدخال الجهاز في النظام', '2025-09-03 03:00:00'),
(2, 2, 3, 'إدخال جهاز', 'تم إدخال الجهاز في النظام', '2025-09-03 03:00:00'),
(3, 3, 3, 'إدخال جهاز', 'تم إدخال الجهاز في النظام', '2025-09-03 03:00:00');

-- إضافة عمليات محددة مسبقاً
INSERT INTO `predefined_operations` (`operation_id`, `operation_name`, `description`, `category`, `estimated_time`, `status`) VALUES
(1, 'تغيير البطارية', 'استبدال بطارية الجهاز', 'صيانة', 30, 'active'),
(2, 'تغيير الهارد ديسك', 'استبدال القرص الصلب', 'صيانة', 60, 'active'),
(3, 'إصلاح الشاشة', 'إصلاح أو استبدال الشاشة', 'صيانة', 120, 'active'),
(4, 'تحديث النظام', 'تحديث نظام التشغيل', 'برمجيات', 45, 'active'),
(5, 'تنظيف الجهاز', 'تنظيف الجهاز من الغبار', 'صيانة', 20, 'active');

-- --------------------------------------------------------

--
-- إضافة المفاتيح الأساسية
--

ALTER TABLE `branches`
  ADD PRIMARY KEY (`branch_id`),
  ADD UNIQUE KEY `branch_name` (`branch_name`),
  ADD UNIQUE KEY `branch_code` (`branch_code`);

ALTER TABLE `warehouses`
  ADD PRIMARY KEY (`warehouse_id`),
  ADD UNIQUE KEY `warehouse_number` (`warehouse_number`),
  ADD KEY `branch_id` (`branch_id`);

ALTER TABLE `warehouse_permissions`
  ADD PRIMARY KEY (`permission_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `warehouse_id` (`warehouse_id`);

ALTER TABLE `warehouse_transfers`
  ADD PRIMARY KEY (`transfer_id`),
  ADD KEY `laptop_id` (`laptop_id`),
  ADD KEY `from_warehouse_id` (`from_warehouse_id`),
  ADD KEY `to_warehouse_id` (`to_warehouse_id`),
  ADD KEY `transfer_user_id` (`transfer_user_id`),
  ADD KEY `receive_user_id` (`receive_user_id`);

ALTER TABLE `warehouse_transfer_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `transfer_id` (`transfer_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `operations`
  ADD PRIMARY KEY (`operation_id`),
  ADD KEY `laptop_id` (`laptop_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `broken_laptops`
  ADD PRIMARY KEY (`laptop_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `entered_by_user_id` (`entered_by_user_id`),
  ADD KEY `assigned_user_id` (`assigned_user_id`),
  ADD KEY `warehouse_id` (`warehouse_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`);

ALTER TABLE `locks`
  ADD PRIMARY KEY (`lock_id`),
  ADD KEY `laptop_id` (`laptop_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `complaints`
  ADD PRIMARY KEY (`complaint_id`),
  ADD KEY `laptop_id` (`laptop_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `item_specifications`
  ADD PRIMARY KEY (`item_number`);

ALTER TABLE `laptop_discussions`
  ADD PRIMARY KEY (`discussion_id`),
  ADD KEY `laptop_id` (`laptop_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `inventory_transfer_logs`
  ADD PRIMARY KEY (`log_id`);

ALTER TABLE `predefined_operations`
  ADD PRIMARY KEY (`operation_id`);

ALTER TABLE `repair_costs`
  ADD PRIMARY KEY (`cost_id`),
  ADD KEY `laptop_id` (`laptop_id`),
  ADD KEY `operation_id` (`operation_id`),
  ADD KEY `user_id` (`user_id`);

-- --------------------------------------------------------

--
-- إضافة Auto Increment
--

ALTER TABLE `branches` MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `warehouses` MODIFY `warehouse_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `warehouse_permissions` MODIFY `permission_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `warehouse_transfers` MODIFY `transfer_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `warehouse_transfer_logs` MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `operations` MODIFY `operation_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `broken_laptops` MODIFY `laptop_id` int(250) NOT NULL AUTO_INCREMENT;
ALTER TABLE `users` MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `notifications` MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `categories` MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `locks` MODIFY `lock_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `complaints` MODIFY `complaint_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `laptop_discussions` MODIFY `discussion_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `inventory_transfer_logs` MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `predefined_operations` MODIFY `operation_id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `repair_costs` MODIFY `cost_id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- إضافة Foreign Keys
--

ALTER TABLE `warehouses`
  ADD CONSTRAINT `warehouses_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE;

ALTER TABLE `warehouse_permissions`
  ADD CONSTRAINT `warehouse_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_permissions_ibfk_2` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE;

ALTER TABLE `warehouse_transfers`
  ADD CONSTRAINT `warehouse_transfers_ibfk_1` FOREIGN KEY (`laptop_id`) REFERENCES `broken_laptops` (`laptop_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_transfers_ibfk_2` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_transfers_ibfk_3` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_transfers_ibfk_4` FOREIGN KEY (`transfer_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_transfers_ibfk_5` FOREIGN KEY (`receive_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `warehouse_transfer_logs`
  ADD CONSTRAINT `warehouse_transfer_logs_ibfk_1` FOREIGN KEY (`transfer_id`) REFERENCES `warehouse_transfers` (`transfer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_transfer_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `operations`
  ADD CONSTRAINT `operations_ibfk_1` FOREIGN KEY (`laptop_id`) REFERENCES `broken_laptops` (`laptop_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `operations_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `broken_laptops`
  ADD CONSTRAINT `broken_laptops_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `broken_laptops_ibfk_2` FOREIGN KEY (`entered_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `broken_laptops_ibfk_3` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `broken_laptops_ibfk_4` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `locks`
  ADD CONSTRAINT `locks_ibfk_1` FOREIGN KEY (`laptop_id`) REFERENCES `broken_laptops` (`laptop_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `locks_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `complaints`
  ADD CONSTRAINT `complaints_ibfk_1` FOREIGN KEY (`laptop_id`) REFERENCES `broken_laptops` (`laptop_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `complaints_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `laptop_discussions`
  ADD CONSTRAINT `laptop_discussions_ibfk_1` FOREIGN KEY (`laptop_id`) REFERENCES `broken_laptops` (`laptop_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `laptop_discussions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `repair_costs`
  ADD CONSTRAINT `repair_costs_ibfk_1` FOREIGN KEY (`laptop_id`) REFERENCES `broken_laptops` (`laptop_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `repair_costs_ibfk_2` FOREIGN KEY (`operation_id`) REFERENCES `predefined_operations` (`operation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `repair_costs_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
