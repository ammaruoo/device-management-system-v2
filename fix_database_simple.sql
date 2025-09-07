-- ========================================================================
-- إصلاح قاعدة البيانات - إضافة الجداول والأعمدة المطلوبة
-- ========================================================================

-- إضافة عمود تاريخ الاستلام لجدول العمليات
ALTER TABLE operations ADD COLUMN IF NOT EXISTS receipt_date DATE NULL;

-- إنشاء جدول تكاليف إصلاح (أوامر الشغل)
CREATE TABLE IF NOT EXISTS repair_costs (
    cost_id INT AUTO_INCREMENT PRIMARY KEY,
    operation_id INT NOT NULL,
    laptop_id INT NOT NULL,
    work_order_ref VARCHAR(100) NOT NULL,
    total_cost DECIMAL(10,2) DEFAULT 0.00,
    cost_items JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (operation_id) REFERENCES operations(operation_id) ON DELETE CASCADE,
    FOREIGN KEY (laptop_id) REFERENCES broken_laptops(laptop_id) ON DELETE CASCADE,
    UNIQUE KEY unique_operation_cost (operation_id)
);

-- إنشاء جدول الفواتير
CREATE TABLE IF NOT EXISTS invoices (
    invoice_id INT AUTO_INCREMENT PRIMARY KEY,
    operation_id INT NOT NULL,
    laptop_id INT NOT NULL,
    invoice_number VARCHAR(100) NOT NULL,
    invoice_date DATE NOT NULL,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_amount DECIMAL(10,2) NULL,
    approved_by_user_id INT NULL,
    approval_date TIMESTAMP NULL,
    approval_notes TEXT NULL,
    notes TEXT NULL,
    created_by_user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (operation_id) REFERENCES operations(operation_id) ON DELETE CASCADE,
    FOREIGN KEY (laptop_id) REFERENCES broken_laptops(laptop_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(user_id),
    FOREIGN KEY (approved_by_user_id) REFERENCES users(user_id),
    UNIQUE KEY unique_invoice_number (invoice_number)
);

-- إنشاء جدول بنود الفاتورة
CREATE TABLE IF NOT EXISTS invoice_items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    original_cost DECIMAL(10,2) DEFAULT 0.00,
    modified_cost DECIMAL(10,2) DEFAULT 0.00,
    final_cost DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE
);

-- إنشاء جدول سجل الاعتمادات
CREATE TABLE IF NOT EXISTS approval_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    old_amount DECIMAL(10,2) NULL,
    new_amount DECIMAL(10,2) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- إنشاء فهارس لتحسين الأداء
CREATE INDEX IF NOT EXISTS idx_repair_costs_laptop ON repair_costs(laptop_id);
CREATE INDEX IF NOT EXISTS idx_invoices_laptop ON invoices(laptop_id);
CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status);
CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice ON invoice_items(invoice_id);
CREATE INDEX IF NOT EXISTS idx_approval_logs_invoice ON approval_logs(invoice_id);

-- إضافة بيانات تجريبية لأوامر الشغل (إذا لم تكن موجودة)
INSERT IGNORE INTO repair_costs (operation_id, laptop_id, work_order_ref, total_cost, cost_items) 
SELECT 
    o.operation_id,
    o.laptop_id,
    COALESCE(o.work_order_ref, CONCAT('WO-', o.operation_id)) as work_order_ref,
    150.00 as total_cost,
    JSON_ARRAY(
        JSON_OBJECT('description', 'صيانة الشاشة', 'cost', 80.00),
        JSON_OBJECT('description', 'استبدال البطارية', 'cost', 70.00)
    ) as cost_items
FROM operations o 
WHERE o.repair_result = 'امر شغل' 
AND o.operation_id NOT IN (SELECT DISTINCT operation_id FROM repair_costs);

-- عرض رسالة نجاح
SELECT 'تم إصلاح قاعدة البيانات بنجاح!' as message;
