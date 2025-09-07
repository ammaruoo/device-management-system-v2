# Background Agent - وكيل الخلفية

## وصف النظام

Background Agent هو نظام يعمل في الخلفية لمعالجة المهام التلقائية في نظام إدارة الأجهزة، ويشمل:

- 📢 معالجة الإشعارات التلقائية
- 👀 مراقبة العمليات والأوامر
- 🧹 تنظيف البيانات القديمة
- 📊 إرسال التقارير الدورية
- 🔄 مزامنة البيانات

## الميزات

### 1. معالجة الإشعارات التلقائية
- إشعارات المهام المتأخرة (أكثر من 7 أيام)
- إشعارات المهام بدون نشاط (أكثر من 3 أيام)
- إشعارات أوامر الشغل المعلقة

### 2. مراقبة العمليات
- فحص أوامر الشغل المعلقة
- مراقبة حالة الأجهزة
- تنبيهات للمدراء والمسؤولين

### 3. تنظيف البيانات
- حذف الإشعارات القديمة (أكثر من 30 يوم)
- تنظيف tokens FCM المنتهية الصلاحية
- حذف الجلسات القديمة

### 4. التقارير الدورية
- تقرير يومي في الساعة 9 صباحاً
- تقرير أسبوعي كل يوم جمعة في الساعة 10 صباحاً

## طريقة الاستخدام

### 1. تشغيل الـ Agent

```bash
# تشغيل في الخلفية
php background_agent.php start

# عرض الحالة
php background_agent.php status

# إيقاف الـ Agent
php background_agent.php stop

# تشغيل للاختبار (في الواجهة الأمامية)
php background_agent.php run
```

### 2. عرض المساعدة

```bash
php background_agent.php help
```

## إعداد المهام المجدولة (Cron Jobs)

### لنظام Windows (Task Scheduler)

1. افتح Task Scheduler
2. أنشئ مهمة جديدة
3. في تبويب General:
   - Name: Background Agent
   - Security options: Run whether user is logged on or not
4. في تبويب Triggers:
   - New trigger: At startup
   - Additional trigger: Daily at 9:00 AM
5. في تبويب Actions:
   - Action: Start a program
   - Program/script: `php.exe`
   - Arguments: `C:\path\to\background_agent.php run`
   - Start in: `C:\path\to\project`

### لنظام Linux/Unix (Crontab)

```bash
# تحرير crontab
crontab -e

# إضافة المهام التالية:
# تشغيل كل دقيقة
* * * * * cd /path/to/project && php background_agent.php run

# أو للتشغيل المستمر مع إعادة التشغيل التلقائي
@reboot cd /path/to/project && php background_agent.php start
```

## إعدادات الـ Agent

يمكن تعديل الإعدادات في بداية ملف `background_agent.php`:

```php
define('AGENT_SLEEP_TIME', 60);     // ثانية بين كل دورة (دقيقة واحدة)
define('AGENT_MAX_RUNTIME', 3600);  // ساعة واحدة كحد أقصى للتشغيل
```

## مراقبة الـ Agent

### عرض السجلات

```bash
# عرض آخر 20 سطر من السجل
tail -20 logs/background_agent.log

# متابعة السجل في الوقت الفعلي
tail -f logs/background_agent.log
```

### ملفات السجل

- `logs/background_agent.log` - سجل عمليات الـ Agent
- `logs/background_agent.pid` - معرف العملية الحالية
- `logs/daily_report_YYYY-MM-DD` - ملفات التقارير اليومية
- `logs/weekly_report_YYYY-WW` - ملفات التقارير الأسبوعية

## استكشاف الأخطاء

### مشاكل شائعة وحلولها

#### 1. الـ Agent لا يبدأ
```bash
# تحقق من وجود php في PATH
php --version

# تشغيل مع مسار كامل
/usr/bin/php background_agent.php start
```

#### 2. أخطاء في قاعدة البيانات
```bash
# تحقق من إعدادات قاعدة البيانات في db.php
# تأكد من وجود الجداول المطلوبة
php check_operations_table.php
```

#### 3. مشاكل في الإشعارات
```bash
# تحقق من إعدادات Firebase
# تأكد من وجود firebase-service-account.json
ls firebase-service-account.json
```

#### 4. الذاكرة والأداء
```bash
# مراقبة استخدام الذاكرة
ps aux | grep background_agent

# إعادة تشغيل إذا لزم الأمر
php background_agent.php stop
php background_agent.php start
```

## المهام التلقائية التفصيلية

### معالجة الإشعارات التلقائية
- **المهام المتأخرة**: إرسال إشعار عندما تتجاوز مدة المهمة 7 أيام
- **المهام الخاملة**: إرسال إشعار عندما لا تكون هناك عمليات لمدة 3 أيام
- **أوامر الشغل المعلقة**: إشعار المدراء بأوامر الشغل بدون فواتير

### مراقبة العمليات
- فحص أوامر الشغل الجديدة كل دقيقة
- مراقبة حالة الأجهزة والعمليات
- إرسال تنبيهات للمستخدمين المعنيين

### التقارير الدورية
- **تقرير يومي**: إحصائيات النشاط اليومي
- **تقرير أسبوعي**: إحصائيات شاملة للأسبوع
- إرسال للمدراء والمحاسبين حسب الصلاحيات

## الأمان

### إجراءات أمنية
- التحقق من الصلاحيات قبل كل عملية
- تسجيل جميع العمليات في السجل
- منع التشغيل المتعدد للـ Agent
- تنظيف البيانات القديمة بانتظام

### ملفات آمنة
- `firebase-service-account.json` - يجب أن يكون محمياً
- ملفات السجل تحتوي على معلومات حساسة
- ملف PID محمي من التعديل

## التخصيص

### إضافة مهام جديدة

```php
function custom_task($pdo) {
    try {
        log_message("بدء المهمة المخصصة");

        // منطق المهمة هنا

        log_message("انتهت المهمة المخصصة");
    } catch (Exception $e) {
        log_message("خطأ في المهمة المخصصة: " . $e->getMessage(), 'ERROR');
    }
}

// إضافة المهمة في الدورة الرئيسية
custom_task($pdo);
```

### تعديل التوقيتات

```php
// تغيير وقت الانتظار بين الدورات
define('AGENT_SLEEP_TIME', 300); // 5 دقائق

// تغيير الحد الأقصى للتشغيل
define('AGENT_MAX_RUNTIME', 7200); // ساعتان
```

## الدعم والصيانة

### النسخ الاحتياطي
- احتفظ بنسخة احتياطية من ملفات السجل
- احتفظ بنسخة من قاعدة البيانات
- اختبر الاستعادة بانتظام

### المراقبة المستمرة
- راقب حجم ملفات السجل
- تحقق من أداء الـ Agent
- راقب استخدام الموارد

---

**ملاحظة**: تأكد من اختبار الـ Agent في بيئة التطوير قبل نشره في الإنتاج.
