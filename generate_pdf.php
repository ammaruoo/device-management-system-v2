<?php
require_once 'dompdf/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

function generateTicketPdf($device_data, $problems_data, $transfer_ref) {
    // تهيئة Dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);

    // قراءة قالب HTML
    $template_path = __DIR__ . '/ticket_template.html';
    $html = file_get_contents($template_path);

    // إعداد البيانات للقالب
    $report_date = date('Y-m-d H:i');

    // تحديد نص الحالة وفئة التنسيق للجهاز
    $statusClass = 'status-entered';
    $statusText = 'تم الإدخال';
    if ($device_data['status'] === 'in_progress') {
        $statusClass = 'status-in_progress';
        $statusText = 'قيد المعالجة';
    } elseif ($device_data['status'] === 'fixed') {
        $statusClass = 'status-fixed';
        $statusText = 'تم الإصلاح';
    } elseif ($device_data['status'] === 'closed') {
        $statusClass = 'status-closed';
        $statusText = 'مغلق';
    }
    $device_data['status_class'] = $statusClass;
    $device_data['status_text'] = $statusText;
    $device_data['with_charger'] = $device_data['with_charger'] ? 'نعم' : 'لا';
    $device_data['ticket_number'] = $device_data['ticket_number'] ?? 'N/A';
    $device_data['serial_number'] = $device_data['serial_number'] ?? 'N/A';
    $device_data['branch_name'] = $device_data['branch_name'] ?? 'N/A';
    $device_data['problem_type'] = $device_data['problem_type'] ?? 'N/A';
    $device_data['problem_nature'] = $device_data['problem_nature'] ?? 'N/A';
    $device_data['assigned_technician'] = $device_data['assigned_technician'] ?? 'N/A';

    // معالجة المشاكل
    $processed_problems = [];
    foreach ($problems_data as $problem) {
        $problemStatusClass = 'problem-pending';
        $problemStatusText = 'قيد المعالجة';
        if (stripos($problem['repair_result'] ?? '', 'تم الإصلاح') !== false) {
            $problemStatusClass = 'problem-resolved';
            $problemStatusText = 'تم الإصلاح';
        }
        $problem['status_class'] = $problemStatusClass;
        $problem['status_text'] = $problemStatusText;
        $processed_problems[] = $problem;
    }
    $device_data['problems'] = $processed_problems;

    // استبدال المتغيرات في القالب
    $html = str_replace('{{ report_date }}', htmlspecialchars($report_date), $html);
    $html = str_replace('{{ device.ticket_number }}', htmlspecialchars($device_data['ticket_number']), $html);
    $html = str_replace('{{ device.item_number }}', htmlspecialchars($device_data['item_number']), $html);
    $html = str_replace('{{ device.specs }}', htmlspecialchars($device_data['specs']), $html);
    $html = str_replace('{{ device.serial_number }}', htmlspecialchars($device_data['serial_number']), $html);
    $html = str_replace('{{ device.employee_name }}', htmlspecialchars($device_data['employee_name']), $html);
    $html = str_replace('{{ device.branch_name }}', htmlspecialchars($device_data['branch_name']), $html);
    $html = str_replace('{{ device.problem_type }}', htmlspecialchars($device_data['problem_type']), $html);
    $html = str_replace('{{ device.problem_nature }}', htmlspecialchars($device_data['problem_nature']), $html);
    $html = str_replace('{{ device.with_charger }}', htmlspecialchars($device_data['with_charger']), $html);
    $html = str_replace('{{ device.assigned_technician }}', htmlspecialchars($device_data['assigned_technician']), $html);
    $html = str_replace('{{ device.status_class }}', htmlspecialchars($device_data['status_class']), $html);
    $html = str_replace('{{ device.status_text }}', htmlspecialchars($device_data['status_text']), $html);
    $html = str_replace('{{ device.problem_count }}', htmlspecialchars($device_data['problem_count']), $html);

    // معالجة المشاكل في القالب (هذا يتطلب معالجة خاصة لـ for loop و if/else)
    // للحفاظ على البساطة والسرعة، سنقوم بإنشاء HTML للمشاكل يدوياً هنا
    $problems_html = '';
    if (!empty($device_data['problems'])) {
        foreach ($device_data['problems'] as $problem) {
            $repair_result_html = '';
            if (!empty($problem['repair_result'])) {
                $repair_result_html = '<p style="color: #065F46;">نتيجة الإصلاح: ' . htmlspecialchars($problem['repair_result']) . '</p>';
            }
            $problems_html .= '<div class="problem-item">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h4>' . htmlspecialchars($problem['problem_title']) . '</h4>
                                        <p>' . htmlspecialchars($problem['problem_details']) . '</p>
                                        ' . $repair_result_html . '
                                    </div>
                                    <span class="status-badge ' . htmlspecialchars($problem['status_class']) . '">' . htmlspecialchars($problem['status_text']) . '</span>
                                </div>
                            </div>';
        }
    } else {
        $problems_html = '<p>لا توجد مشاكل مسجلة لهذا الجهاز.</p>';
    }
    $html = preg_replace('/{% if device.problems %}(.*?){% endif %}/s', $problems_html, $html);
    $html = str_replace('{% for problem in device.problems %}', '', $html);
    $html = str_replace('{% endfor %}', '', $html);
    $html = str_replace('{% else %}', '', $html);

    $dompdf->loadHtml($html);

    // (اختياري) تحديد حجم الورقة والاتجاه
    $dompdf->setPaper('A4', 'portrait');

    // عرض HTML كـ PDF
    $dompdf->render();

    // إرسال ملف PDF إلى المتصفح
    $dompdf->stream('ticket_' . $device_data['ticket_number'] . '.pdf', array('Attachment' => 0));
}

// هذا الجزء سيتم استدعاؤه من الكود الأصلي
// For testing purposes, you can uncomment and run this part directly

$sample_device = [
    'ticket_number' => 'TICKET-001',
    'item_number' => 'LAPTOP-XYZ',
    'specs' => 'Dell XPS 15, i7, 16GB RAM',
    'serial_number' => 'SN123456789',
    'employee_name' => 'أحمد محمد',
    'branch_name' => 'الفرع الرئيسي',
    'problem_type' => 'مشكلة في الشاشة',
    'problem_nature' => 'شاشة سوداء عند التشغيل',
    'with_charger' => true,
    'assigned_technician' => 'فني1',
    'status' => 'in_progress',
    'problem_count' => 1
];

$sample_problems = [
    [
        'problem_title' => 'الشاشة لا تعمل',
        'problem_details' => 'الشاشة لا تستجيب عند تشغيل الجهاز، لا يوجد إضاءة خلفية.',
        'repair_result' => 'تم استبدال الشاشة بنجاح.'
    ]
];

generateTicketPdf($sample_device, $sample_problems, 'TRF-001');

?>
