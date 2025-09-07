-- ========================================================================
-- إضافة عمود user_id لجدول repair_costs
-- ========================================================================

-- إضافة عمود user_id إذا لم يكن موجوداً
ALTER TABLE repair_costs ADD COLUMN IF NOT EXISTS user_id INT NULL;

-- إضافة مفتاح خارجي للربط مع جدول users
ALTER TABLE repair_costs ADD CONSTRAINT IF NOT EXISTS fk_repair_costs_user 
FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL;

-- إضافة فهرس لتحسين الأداء
CREATE INDEX IF NOT EXISTS idx_repair_costs_user_id ON repair_costs(user_id);

-- عرض رسالة نجاح
SELECT 'تم إضافة عمود user_id لجدول repair_costs بنجاح!' as message;
