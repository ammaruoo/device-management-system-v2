-- =================================================================================
-- تحديث قاعدة البيانات لإضافة نظام الفواتير والاعتماد
-- =================================================================================

-- إضافة حقل تاريخ الاستلام لجدول operations
ALTER TABLE `operations` 
ADD COLUMN `receipt_date` DATE DEFAULT CURRENT_DATE COMMENT 'تاريخ سند الاستلام' AFTER `receipt_number`;

-- إنشاء جدول الفواتير
CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL AUTO_INCREMENT,
  `laptop_id` int(11) NOT NULL COMMENT 'معرف الجهاز',
  `operation_id` int(11) NOT NULL COMMENT 'معرف العملية (أمر الشغل)',
  `invoice_number` varchar(100) NOT NULL COMMENT 'رقم الفاتورة من النظام المحاسبي',
  `invoice_date` DATE NOT NULL DEFAULT CURRENT_DATE COMMENT 'تاريخ الفاتورة',
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي المبلغ',
  `approved_amount` decimal(10,2) DEFAULT NULL COMMENT 'المبلغ المعتمد',
  `status` enum('pending','approved','rejected') DEFAULT 'pending' COMMENT 'حالة الفاتورة',
  `created_by_user_id` int(11) NOT NULL COMMENT 'المستخدم الذي أنشأ الفاتورة',
  `approved_by_user_id` int(11) DEFAULT NULL COMMENT 'المستخدم الذي اعتمد الفاتورة',
  `approval_date` timestamp NULL DEFAULT NULL COMMENT 'تاريخ الاعتماد',
  `approval_notes` text DEFAULT NULL COMMENT 'ملاحظات الاعتماد',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`invoice_id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `laptop_id` (`laptop_id`),
  KEY `operation_id` (`operation_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  KEY `approved_by_user_id` (`approved_by_user_id`),
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`laptop_id`) REFERENCES `broken_laptops` (`laptop_id`) ON DELETE CASCADE,
  CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`operation_id`) ON DELETE CASCADE,
  CONSTRAINT `invoices_ibfk_3` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `invoices_ibfk_4` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='جدول الفواتير';

-- إنشاء جدول تفاصيل الفواتير
CREATE TABLE `invoice_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL COMMENT 'معرف الفاتورة',
  `description` varchar(255) NOT NULL COMMENT 'وصف البند',
  `original_cost` decimal(10,2) NOT NULL COMMENT 'التكلفة الأصلية من أمر الشغل',
  `modified_cost` decimal(10,2) DEFAULT NULL COMMENT 'التكلفة المعدلة',
  `final_cost` decimal(10,2) NOT NULL COMMENT 'التكلفة النهائية',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='تفاصيل بنود الفواتير';

-- إنشاء جدول سجل الاعتمادات
CREATE TABLE `approval_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL COMMENT 'معرف الفاتورة',
  `user_id` int(11) NOT NULL COMMENT 'المستخدم الذي قام بالإجراء',
  `action` enum('created','modified','approved','rejected') NOT NULL COMMENT 'نوع الإجراء',
  `old_amount` decimal(10,2) DEFAULT NULL COMMENT 'المبلغ القديم',
  `new_amount` decimal(10,2) DEFAULT NULL COMMENT 'المبلغ الجديد',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `approval_logs_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE,
  CONSTRAINT `approval_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='سجل اعتمادات الفواتير';

-- إنشاء جدول نسب المهندسين
CREATE TABLE `engineer_commissions` (
  `commission_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL COMMENT 'معرف الفاتورة',
  `engineer_id` int(11) NOT NULL COMMENT 'معرف المهندس',
  `commission_percentage` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'نسبة العمولة',
  `commission_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'مبلغ العمولة',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`commission_id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `engineer_id` (`engineer_id`),
  CONSTRAINT `engineer_commissions_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE,
  CONSTRAINT `engineer_commissions_ibfk_2` FOREIGN KEY (`engineer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='نسب عمولات المهندسين';

-- إضافة فهارس لتحسين الأداء
CREATE INDEX `idx_operations_receipt_date` ON `operations` (`receipt_date`);
CREATE INDEX `idx_invoices_status_date` ON `invoices` (`status`, `invoice_date`);
CREATE INDEX `idx_invoice_items_cost` ON `invoice_items` (`final_cost`);
CREATE INDEX `idx_approval_logs_action_date` ON `approval_logs` (`action`, `created_at`);

-- إضافة بيانات تجريبية للاختبار
INSERT INTO `invoices` (`laptop_id`, `operation_id`, `invoice_number`, `invoice_date`, `total_amount`, `status`, `created_by_user_id`) VALUES
(1, 1, 'INV-2025-001', CURRENT_DATE, 150.00, 'pending', 1),
(2, 2, 'INV-2025-002', CURRENT_DATE, 200.00, 'pending', 1);

-- إضافة بنود تجريبية
INSERT INTO `invoice_items` (`invoice_id`, `description`, `original_cost`, `final_cost`) VALUES
(1, 'بطارية جديدة', 100.00, 100.00),
(1, 'عمل يدوي', 50.00, 50.00),
(2, 'هارد ديسك جديد', 150.00, 150.00),
(2, 'عمل يدوي', 50.00, 50.00);

-- إضافة نسب تجريبية للمهندسين
INSERT INTO `engineer_commissions` (`invoice_id`, `engineer_id`, `commission_percentage`, `commission_amount`) VALUES
(1, 4, 15.00, 22.50),  -- سلطان: 15% من 150
(1, 5, 10.00, 15.00),  -- مصطفى: 10% من 150
(2, 4, 20.00, 40.00),  -- سلطان: 20% من 200
(2, 5, 15.00, 30.00);  -- مصطفى: 15% من 200
