-- ========================================================================
-- إضافة الأعمدة المفقودة
-- ========================================================================

-- إضافة عمود work_order_ref لجدول operations
ALTER TABLE operations ADD COLUMN IF NOT EXISTS work_order_ref VARCHAR(100) NULL;

-- إضافة عمود notes لجدول invoices (إذا لم يكن موجوداً)
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS notes TEXT NULL;

-- إضافة عمود updated_at لجدول invoices (إذا لم يكن موجوداً)
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP;

-- عرض رسالة نجاح
SELECT 'تم إضافة الأعمدة المفقودة بنجاح!' as message;
