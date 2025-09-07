# 🧾 نظام إدارة الفواتير والاعتماد

## 📋 **نظرة عامة**

تم تطوير نظام متكامل لإدارة الفواتير والاعتماد في نظام إدارة الأجهزة المعطلة. النظام يتيح:

- **إنشاء فواتير** بناءً على أوامر الشغل
- **اعتماد الفواتير** من قبل المديرين ومسؤولي الصيانة
- **تتبع التكاليف** لكل جهاز
- **حساب نسب المهندسين** تلقائياً

## 🎯 **الميزات الرئيسية**

### **1. سند الاستلام المحسن**
- ✅ تاريخ تلقائي (اليوم الحالي)
- ✅ إمكانية تعديل التاريخ
- ✅ حفظ رقم الاستلام مع التاريخ

### **2. أمر الشغل**
- ✅ إدخال رقم أمر الشغل
- ✅ إضافة التكاليف (وصف + مبلغ)
- ✅ حساب الإجمالي تلقائياً

### **3. إنشاء الفاتورة (عملية منفصلة)**
- ✅ البحث عن أمر الشغل (برقم أو اسم الجهاز)
- ✅ عرض بيانات أمر الشغل تلقائياً
- ✅ جلب التكاليف من أمر الشغل
- ✅ إدخال رقم الفاتورة من النظام المحاسبي
- ✅ تحديد تاريخ الفاتورة
- ✅ إضافة ملاحظات

### **4. اعتماد الفاتورة (عملية منفصلة)**
- ✅ البحث عن الفاتورة (برقم أو أمر شغل)
- ✅ عرض بيانات الفاتورة كاملة
- ✅ تعديل المبلغ المعتمد
- ✅ إضافة ملاحظات الاعتماد
- ✅ تسجيل عملية الاعتماد

## 🗄️ **هيكل قاعدة البيانات**

### **الجدول: `operations`**
```sql
-- إضافة حقل تاريخ الاستلام
ALTER TABLE `operations` 
ADD COLUMN `receipt_date` DATE DEFAULT CURRENT_DATE COMMENT 'تاريخ سند الاستلام' AFTER `receipt_number`;
```

### **الجدول: `invoices`**
```sql
CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL AUTO_INCREMENT,
  `laptop_id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `invoice_date` DATE NOT NULL DEFAULT CURRENT_DATE,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `approved_amount` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_by_user_id` int(11) NOT NULL,
  `approved_by_user_id` int(11) DEFAULT NULL,
  `approval_date` timestamp NULL DEFAULT NULL,
  `approval_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`invoice_id`)
);
```

### **الجدول: `invoice_items`**
```sql
CREATE TABLE `invoice_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `original_cost` decimal(10,2) NOT NULL,
  `modified_cost` decimal(10,2) DEFAULT NULL,
  `final_cost` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`)
);
```

### **الجدول: `approval_logs`**
```sql
CREATE TABLE `approval_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` enum('created','modified','approved','rejected') NOT NULL,
  `old_amount` decimal(10,2) DEFAULT NULL,
  `new_amount` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`)
);
```

### **الجدول: `engineer_commissions`**
```sql
CREATE TABLE `engineer_commissions` (
  `commission_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `engineer_id` int(11) NOT NULL,
  `commission_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `commission_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`commission_id`)
);
```

## 🚀 **كيفية الاستخدام**

### **الخطوة 1: إنشاء سند استلام**
1. اختر "سند استلام" من أنواع العمليات
2. أدخل رقم سند الاستلام
3. التاريخ يظهر تلقائياً (يمكن تعديله)
4. أضف تفاصيل إضافية (اختياري)
5. ارفق صورة (اختياري)
6. اضغط "تسجيل العملية"

### **الخطوة 2: إنشاء أمر شغل**
1. اختر "امر شغل" من أنواع العمليات
2. أدخل رقم أمر الشغل
3. أضف التكاليف (وصف + مبلغ)
4. أضف تفاصيل إضافية
5. ارفق صورة (اختياري)
6. اضغط "تسجيل العملية"

### **الخطوة 3: إنشاء فاتورة**
1. اختر "إنشاء فاتورة" من أنواع العمليات
2. ابحث عن أمر الشغل (برقم أو اسم الجهاز)
3. راجع بيانات أمر الشغل والتكاليف
4. أدخل رقم الفاتورة من النظام المحاسبي
5. حدد تاريخ الفاتورة
6. أضف ملاحظات (اختياري)
7. اضغط "تسجيل العملية"

### **الخطوة 4: اعتماد الفاتورة**
1. اختر "اعتماد فاتورة" من أنواع العمليات
2. ابحث عن الفاتورة (برقم أو أمر شغل)
3. راجع بيانات الفاتورة والبنود
4. عدّل المبلغ المعتمد (إذا لزم الأمر)
5. أضف ملاحظات الاعتماد
6. اضغط "تسجيل العملية"

## 🔧 **API Endpoints**

### **البحث عن أمر الشغل**
```http
GET /api_handler.php?action=search_work_order&search={search_term}
```

### **البحث عن الفاتورة**
```http
GET /api_handler.php?action=search_invoice&search={search_term}
```

### **إنشاء فاتورة**
```http
POST /api_handler.php?action=create_invoice
```

### **إضافة عملية**
```http
POST /api_handler.php?action=add_operation
```

## 📊 **تتبع التكاليف**

### **لكل جهاز:**
- إجمالي التكاليف من أوامر الشغل
- إجمالي الفواتير المعتمدة
- نسبة التكاليف المعتمدة

### **نسب المهندسين:**
- حساب العمولات تلقائياً
- تتبع نسب كل مهندس
- تقارير الأداء

## 🔒 **نظام الصلاحيات**

### **إنشاء الفواتير:**
- المستخدمون العاديون
- مسؤولو الصيانة

### **اعتماد الفواتير:**
- المديرين فقط
- مسؤولي الصيانة المصرح لهم

## 📈 **التقارير المتاحة**

1. **تقرير التكاليف الإجمالية**
2. **تقرير الفواتير المعتمدة**
3. **تقرير نسب المهندسين**
4. **تقرير سجل الاعتمادات**
5. **تقرير الفواتير المعلقة**

## 🛠️ **متطلبات النظام**

- PHP 7.4+
- MySQL 5.7+
- Apache/Nginx
- JavaScript (Alpine.js)
- Tailwind CSS

## 📝 **ملاحظات مهمة**

1. **رقم الفاتورة** يجب أن يكون فريداً
2. **التكاليف** تُجلب تلقائياً من أمر الشغل
3. **الاعتماد** يتطلب صلاحيات خاصة
4. **سجل كامل** لكل العمليات
5. **نسخ احتياطية** تلقائية للبيانات

## 🎉 **الخلاصة**

النظام الجديد يوفر:
- ✅ **إدارة متكاملة** للفواتير
- ✅ **عملية منفصلة** لكل نوع
- ✅ **تتبع دقيق** للتكاليف
- ✅ **نظام اعتماد** آمن
- ✅ **تقارير شاملة** للأداء

**النظام جاهز للاستخدام!** 🚀
