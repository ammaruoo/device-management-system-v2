<?php
ob_start();
session_start();
require 'db.php';

// Ensure getStatusDetails is available (same mapping as in broken_laptops.php)
if (!function_exists('getStatusDetails')) {
    function getStatusDetails($status) {
        $status_map = [
            'entered' => ['name' => 'تم الإدخال', 'color' => 'gray'],
            'review_pending' => ['name' => 'قيد المراجعة', 'color' => 'orange'],
            'assigned' => ['name' => 'مُعيّن', 'color' => 'blue'],
            'in_repair' => ['name' => 'قيد الإصلاح', 'color' => 'yellow'],
            'returned_for_review' => ['name' => 'مرجع للمراجعة', 'color' => 'purple'],
            'locked' => ['name' => 'مغلق', 'color' => 'green'],
            'مغلق' => ['name' => 'مغلق', 'color' => 'green'],
        ];
        return $status_map[$status] ?? ['name' => ucfirst($status), 'color' => 'gray'];
    }
}

// =================================================================================
// SECURITY & PERMISSIONS
// =================================================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];
$current_username = $_SESSION['username'];
$laptop_id = (int)($_GET['laptop_id'] ?? 0);
if ($laptop_id <= 0) die("جهاز غير صالح");

// =================================================================================
// HANDLE AJAX FORM SUBMISSION FOR NEW MESSAGES
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'errors' => []];
    
    $message = trim($_POST['message'] ?? '');
    $image_path = null;

    // Handle file upload
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/chat_images/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                $response['errors'][] = 'فشل في إنشاء مجلد الرفع.';
                echo json_encode($response);
                exit;
            }
        }
        $fileName = time() . '_' . basename($_FILES['attachment']['name']);
        $targetPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetPath)) {
            $image_path = 'uploads/chat_images/' . $fileName;
        } else {
            $response['errors'][] = 'فشل رفع الملف.';
        }
    }

    // Check if there is actually a file or a text message. Use UPLOAD_ERR_NO_FILE to detect no-upload.
    $hasFile = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;
    if (empty($message) && !$hasFile) {
        $response['errors'][] = 'لا يمكن إرسال رسالة فارغة.';
        echo json_encode($response);
        exit;
    }

    if (empty($response['errors'])) {
        $stmt = $pdo->prepare("INSERT INTO laptop_discussions (laptop_id, user_id, message, image_path) VALUES (?, ?, ?, ?)");
        $stmt->execute([$laptop_id, $current_user_id, $message, $image_path]);
        
        // Trigger notification logic
        $laptop_info_stmt = $pdo->prepare("SELECT serial_number, assigned_user_id, entered_by_user_id FROM broken_laptops WHERE laptop_id = ?");
        $laptop_info_stmt->execute([$laptop_id]);
        $laptop_info = $laptop_info_stmt->fetch();

        $participants_stmt = $pdo->prepare("SELECT DISTINCT user_id FROM laptop_discussions WHERE laptop_id = ?");
        $participants_stmt->execute([$laptop_id]);
        $participant_ids = $participants_stmt->fetchAll(PDO::FETCH_COLUMN);
        $all_involved_ids = array_merge($participant_ids, [$laptop_info['assigned_user_id'], $laptop_info['entered_by_user_id']]);
        $unique_ids_to_notify = array_unique(array_filter($all_involved_ids));
        
        $notification_message = "رسالة جديدة من " . htmlspecialchars($current_username) . " بخصوص الجهاز " . htmlspecialchars($laptop_info['serial_number']);
        $notification_link = "laptop_chat.php?laptop_id=" . $laptop_id;
        $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
        foreach ($unique_ids_to_notify as $notify_user_id) {
            if ($notify_user_id != $current_user_id) {
                $notif_stmt->execute([$notify_user_id, $notification_message, $notification_link]);
            }
        }
        
        $response['success'] = true;
    }
    
    echo json_encode($response);
    exit;
}

// =================================================================================
// FETCH DATA FOR PAGE DISPLAY
// =================================================================================
$stmt = $pdo->prepare("
    SELECT b.*, c.category_name, u_assigned.username as assigned_to
    FROM broken_laptops b
    LEFT JOIN categories c ON b.category_id = c.category_id
    LEFT JOIN users u_assigned ON b.assigned_user_id = u_assigned.user_id
    WHERE b.laptop_id = ?
");
$stmt->execute([$laptop_id]);
$laptop = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$laptop) die("الجهاز غير موجود");

// Decode problem details from JSON
$problems = [];
if (!empty($laptop['problem_details'])) {
    $decoded_problems = json_decode($laptop['problem_details'], true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $problems = $decoded_problems;
    }
}

// Build a device intro text (specs + full problem details) to show as the first message in the chat
$specs_text = $laptop['specs'] ? trim($laptop['specs']) : 'لا توجد مواصفات';
$details_parts = [];
if (!empty($problems)) {
    if (is_array($problems)) {
        foreach ($problems as $p) {
            $title = isset($p['title']) ? trim($p['title']) : '';
            $det = isset($p['details']) ? trim($p['details']) : '';
            if ($title && $det) $details_parts[] = $title . ': ' . $det;
            elseif ($title) $details_parts[] = $title;
            elseif ($det) $details_parts[] = $det;
        }
    } else {
        $details_parts[] = trim((string)$problems);
    }
}
$details_text = implode(' | ', array_filter($details_parts));
$device_intro_text = $specs_text . ($details_text ? ' — ' . $details_text : '');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>محادثة الجهاز: <?= htmlspecialchars($laptop['serial_number']) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Cairo', sans-serif; }
        [x-cloak] { display: none !important; }
        /* WhatsApp-like styles */
        header.app-header { background: #075E54; color: #fff; }
        header.app-header a { color: rgba(255,255,255,0.9); }

        /* Chat background similar to WhatsApp */
        #chat-container { background: #e5ddd5; }

        /* Message bubbles */
        .chat-bubble-sent { background-color: #DCF8C6; border-radius: 18px 18px 3px 18px; }
        .chat-bubble-received { background-color: #ffffff; border-radius: 18px 18px 18px 3px; }

        .chat-meta { font-size: 11px; color: #666; }

        /* Input area */
        .input-area { background: #f6f6f6; border-radius: 9999px; }

        /* Microphone / recording UI */
        .mic-button { transition: all .15s ease; border-radius: 9999px; padding: .5rem 1rem; display:flex; align-items:center; gap:.5rem; }
        .mic-button.recording { background: #e53935; color: #fff; box-shadow: 0 6px 18px rgba(0,0,0,.15); }
        .mic-button.idle { background: #fff; color: #075E54; border: 1px solid rgba(0,0,0,.06); }

        .recording-bar { height: 40px; background: linear-gradient(90deg,#e53935,#ff7043); border-radius: 9999px; display:flex; align-items:center; gap:.75rem; padding:0 .75rem; color:#fff; }

        /* Scrollbar */
        #chat-container::-webkit-scrollbar { width: 6px; }
        #chat-container::-webkit-scrollbar-track { background: #f1f1f1; }
        #chat-container::-webkit-scrollbar-thumb { background: #888; border-radius: 3px; }
    </style>
</head>
<body class="bg-gray-100 h-screen overflow-hidden">

<div class="flex h-full" x-data="chatApp(<?= $laptop_id ?>, <?= $current_user_id ?>)">
    
    <!-- Main Chat Area -->
    <main class="flex-1 flex flex-col">
        <!-- Header -->
        <header class="app-header border-b p-4 flex justify-between items-center flex-shrink-0">
            <div>
                <h1 class="text-xl font-bold text-gray-800">جهاز: <?= htmlspecialchars($laptop['serial_number']) ?></h1>
                <p class="text-sm text-gray-500">محادثة الفريق الفني</p>
            </div>
            <a href="broken_laptops.php" class="text-sm text-blue-600 hover:underline">العودة لقائمة الأجهزة</a>
        </header>

        <!-- Chat Messages -->
        <div id="chat-container" x-ref="chatContainer" class="flex-1 p-6 overflow-y-auto space-y-4">
            <template x-for="message in messages" :key="message.created_at + message.username">
                <div class="flex items-end gap-3" :class="message.user_id == currentUserId ? 'justify-end' : 'justify-start'">
                    <!-- Avatar -->
                    <div :class="message.user_id == currentUserId ? 'order-2' : 'order-1'">
                        <div class="w-8 h-8 rounded-full bg-gray-300 flex items-center justify-center font-bold text-white" :style="{ backgroundColor: userColor(message.username) }" x-text="message.username.charAt(0).toUpperCase()"></div>
                    </div>
                    <!-- Bubble -->
                    <div class="max-w-md p-3" :class="message.user_id == currentUserId ? 'chat-bubble-sent order-1' : 'chat-bubble-received order-2'">
                        <p class="text-sm font-semibold mb-1" :style="{ color: userColor(message.username) }" x-text="message.username"></p>
                        <p class="text-gray-800" x-text="message.message" x-show="message.message"></p>
                        <div x-show="message.image_path" class="mt-2">
                            <template x-if="isAudio(message.image_path)">
                                <audio :src="message.image_path" controls class="w-full rounded"></audio>
                            </template>
                            <template x-if="!isAudio(message.image_path)">
                                <img :src="message.image_path" @click="openImage(message.image_path)" class="max-w-xs rounded-lg cursor-pointer" alt="مرفق">
                            </template>
                        </div>
                        <p class="text-xs text-gray-500 mt-2 text-left" x-text="new Date(message.created_at).toLocaleTimeString('en-US', {hour: '2-digit', minute:'2-digit'})"></p>
                    </div>
                </div>
            </template>
            <div x-show="isLoading" class="text-center text-gray-500">جاري تحميل الرسائل...</div>
        </div>

        <!-- Message Input Form -->
        <footer class="bg-white border-t p-4 flex-shrink-0">
            <form @submit.prevent="sendMessage()" class="flex items-center gap-3">
                <input type="file" x-ref="attachment" class="hidden" @change="fileName = $event.target.files[0] ? $event.target.files[0].name : ''">
                <button type="button" @click="$refs.attachment.click()" class="p-2 text-gray-500 hover:bg-gray-100 rounded-full" title="إرفاق ملف">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                </button>

                <!-- Hold-to-record button (WhatsApp style) -->
                <div class="relative">
                    <button type="button"
                        @mousedown="startHoldRecord()"
                        @touchstart.prevent="startHoldRecord()"
                        @mouseup="stopHoldRecord()"
                        @touchend.prevent="stopHoldRecord()"
                        @mouseleave="cancelHoldRecord()"
                        :class="isRecording ? 'mic-button recording' : 'mic-button idle'"
                        x-bind:title="isRecording ? 'سحب للإلغاء أو أرفع لإرسال' : 'اضغط مطولاً للتسجيل'">
                        <template x-if="!isRecording">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 1v11m0 0a3 3 0 01-3 3H6a6 6 0 006 6 6 6 0 006-6h-3a3 3 0 01-3-3z"/></svg>
                            <span>اضغط للتسجيل</span>
                        </template>
                        <template x-if="isRecording">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="4"/></svg>
                            <span x-text="recordingTimerText"></span>
                        </template>
                    </button>

                    <!-- Floating recording bar -->
                    <div x-show="isRecording" class="absolute -top-16 left-0" x-cloak>
                        <div class="recording-bar">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="4"/></svg>
                            <strong x-text="recordingTimerText"></strong>
                            <div style="width:140px;" class="h-2 bg-white/30 rounded-full overflow-hidden">
                                <div :style="{ width: recordingProgress + '%' }" class="h-2 bg-white rounded-full"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Camera capture button -->
                <button type="button" @click="openCamera()" class="p-2 text-gray-500 hover:bg-gray-100 rounded-full" title="التقاط صورة بالكاميرا">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h2l2-3h8l2 3h2v11a2 2 0 01-2 2H5a2 2 0 01-2-2V7zM12 11a3 3 0 100 6 3 3 0 000-6z"/></svg>
                </button>

                <div class="flex-1 relative">
                    <input type="text" x-model="newMessage" placeholder="اكتب رسالتك هنا..." class="w-full p-3 pr-12 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <span x-show="fileName" class="absolute left-3 top-1/2 -translate-y-1/2 text-xs bg-gray-200 text-gray-600 px-2 py-1 rounded-full" x-text="fileName"></span>
                </div>
                <button type="submit" class="p-3 bg-blue-600 text-white rounded-full hover:bg-blue-700 disabled:bg-gray-400" :disabled="isSending">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                </button>
            </form>
        </footer>

        <!-- Camera Modal / Preview -->
        <div x-show="isCameraOpen" x-cloak class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
            <div @click.away="closeCamera()" class="bg-white rounded-md p-4 max-w-lg w-full">
                <div class="flex justify-between items-center mb-2">
                    <h3 class="font-bold">معاينة الكاميرا</h3>
                    <div class="flex items-center gap-2">
                        <button @click="capturePhoto()" class="px-3 py-1 bg-green-600 text-white rounded">التقاط</button>
                        <button @click="closeCamera()" class="px-3 py-1 bg-gray-200 rounded">إغلاق</button>
                    </div>
                </div>
                <video x-ref="videoPreview" autoplay playsinline class="w-full rounded"></video>
            </div>
        </div>

    </main>

    <!-- Device Info Sidebar -->
    <aside class="w-96 bg-white border-r flex-shrink-0 flex-col hidden lg:flex">
        <div class="p-4 border-b">
            <h2 class="text-lg font-bold">تفاصيل الجهاز</h2>
        </div>
        <div class="p-4 space-y-3 overflow-y-auto">
            <div class="p-3 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500">الرقم التسلسلي</p>
                <p class="font-semibold"><?= htmlspecialchars($laptop['serial_number']) ?></p>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500">المواصفات</p>
                <p class="font-semibold"><?= htmlspecialchars($laptop['specs'] ?: 'غير محدد') ?></p>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500">الحالة الحالية</p>
                <p class="font-semibold"><?= htmlspecialchars(getStatusDetails($laptop['status'])['name']) ?></p>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500">الفني المسؤول</p>
                <p class="font-semibold"><?= htmlspecialchars($laptop['assigned_to'] ?: 'لم يعين') ?></p>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500">الموظف</p>
                <p class="font-semibold"><?= htmlspecialchars($laptop['employee_name'] ?: 'غير محدد') ?></p>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500">المشاكل المسجلة</p>
                <ul class="list-disc pr-5 mt-1 space-y-1">
                    <?php if (empty($problems)): ?>
                        <li>لا توجد مشاكل مفصلة.</li>
                    <?php else: ?>
                        <?php foreach ($problems as $problem): ?>
                            <li><strong class="font-semibold"><?= htmlspecialchars($problem['title']) ?>:</strong> <?= htmlspecialchars($problem['details'] ?: 'لا توجد تفاصيل إضافية') ?></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </aside>

    <!-- Image Viewer Modal -->
    <div x-show="isImageViewerOpen" @keydown.escape.window="isImageViewerOpen = false" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50" x-cloak>
        <div @click.away="isImageViewerOpen = false" class="relative">
            <img :src="imageViewerSrc" class="max-w-screen-lg max-h-screen-lg p-4">
            <button @click="isImageViewerOpen = false" class="absolute top-2 right-2 text-white text-3xl">&times;</button>
        </div>
    </div>

</div>

<script>
    // Server-provided intro text for the device (specs + details)
    const deviceIntroText = <?= json_encode($device_intro_text, JSON_UNESCAPED_UNICODE) ?>;

    function chatApp(laptopId, currentUserId) {
        return {
            // New state for audio and camera features
            isRecording: false,
            mediaRecorder: null,
            audioChunks: [],
            isCameraOpen: false,
            videoStream: null,
            pendingBlob: null,
            pendingFileName: '',
            laptopId: laptopId,
            currentUserId: currentUserId,
            // A system message that describes the device; will be prepended to fetched messages
            deviceIntroMessage: { user_id: 0, username: 'تفاصيل الجهاز', message: deviceIntroText, image_path: null, created_at: new Date().toISOString() },
            messages: [],
            newMessage: '',
            fileName: '',
            isLoading: true,
            isSending: false,
            isImageViewerOpen: false,
            imageViewerSrc: '',
            colors: ['#3B82F6', '#10B981', '#F59E0B', '#8B5CF6', '#EF4444', '#6366F1'],
            userColors: {},

            init() {
                this.fetchMessages();
                setInterval(() => {
                    this.fetchMessages();
                }, 5000); // Auto-refresh every 5 seconds
            },

            fetchMessages() {
                fetch(`api_handler.php?action=get_chat_messages&laptop_id=${this.laptopId}`)
                    .then(res => res.json())
                    .then(data => {
                        // Always ensure the device intro system message is the first item
                        const fetched = Array.isArray(data) ? data : [];
                        const combined = [this.deviceIntroMessage, ...fetched];
                        if (JSON.stringify(this.messages) !== JSON.stringify(combined)) {
                            this.messages = combined;
                            this.$nextTick(() => this.scrollToBottom());
                        }
                    })
                    .catch(err => {
                        console.error('Failed to fetch messages:', err);
                        alert('فشل في جلب الرسائل. تحقق من اتصالك أو حاول مرة أخرى لاحقًا.');
                    })
                    .finally(() => this.isLoading = false);
            },

            sendMessage() {
                if (this.isSending || (!this.newMessage && !this.pendingBlob && !(this.$refs.attachment && this.$refs.attachment.files[0]))) return;
                this.isSending = true;

                const formData = new FormData();
                formData.append('message', this.newMessage);
                // Attach programmatically captured blob (audio/photo) if present
                if (this.pendingBlob) {
                    formData.append('attachment', this.pendingBlob, this.pendingFileName || ('file_' + Date.now()));
                } else if (this.$refs.attachment && this.$refs.attachment.files[0]) {
                    formData.append('attachment', this.$refs.attachment.files[0]);
                }

                fetch(`laptop_chat.php?laptop_id=${this.laptopId}`, {
                    method: 'POST',
                    body: formData
                })
                .then(async res => {
                    const text = await res.text();
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON from server:', text);
                        alert('خطأ في الخادم. انظر وحدة التحكم أو علامة تبويب الشبكة (Network) للحصول على الاستجابة.');
                        return;
                    }
                    if (data.success) {
                        this.newMessage = '';
                        this.fileName = '';
                        this.pendingBlob = null;
                        this.pendingFileName = '';
                        if (this.$refs.attachment) this.$refs.attachment.value = ''; // Clear file input
                        this.fetchMessages(); // Refresh messages immediately
                    } else {
                        alert('Error: ' + (data.errors ? data.errors.join(', ') : 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error('Request failed:', err);
                    alert('فشل في إرسال الطلب: ' + (err.message || err));
                })
                .finally(() => this.isSending = false);
            },

            // Hold-to-record audio controls (press & hold like WhatsApp)
            recordingStartTime: null,
            recordingTimerInterval: null,
            recordingTimerText: '00:00',
            recordingProgress: 0,
            maxRecordingMs: 5 * 60 * 1000, // 5 minutes max

            async startHoldRecord() {
                if (this.isRecording) return;
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    this.audioChunks = [];
                    this.mediaRecorder = new MediaRecorder(stream);
                    let localStream = stream;
                    this.mediaRecorder.ondataavailable = e => { if (e.data && e.data.size) this.audioChunks.push(e.data); };
                    this.mediaRecorder.onstop = () => {
                        // stop tracks to release microphone
                        try { localStream.getTracks().forEach(t => t.stop()); } catch (e){}
                        const blob = new Blob(this.audioChunks, { type: 'audio/webm' });
                        if (this._recordCancelled) {
                            // discard
                            this._recordCancelled = false;
                        } else {
                            this.pendingBlob = blob;
                            this.pendingFileName = 'recording_' + Date.now() + '.webm';
                            this.fileName = this.pendingFileName;
                            // auto-send after finishing hold
                            this.sendMessage();
                        }
                    };
                    this.mediaRecorder.start();
                    this.isRecording = true;
                    this._recordCancelled = false;
                    this.recordingStartTime = Date.now();
                    this.recordingTimerText = '00:00';
                    this.recordingProgress = 0;
                    this.recordingTimerInterval = setInterval(() => {
                        const elapsed = Date.now() - this.recordingStartTime;
                        const seconds = Math.floor(elapsed / 1000);
                        const mm = String(Math.floor(seconds / 60)).padStart(2, '0');
                        const ss = String(seconds % 60).padStart(2, '0');
                        this.recordingTimerText = `${mm}:${ss}`;
                        this.recordingProgress = Math.min(100, (elapsed / this.maxRecordingMs) * 100);
                        if (elapsed >= this.maxRecordingMs) {
                            // auto-stop and send
                            this.stopHoldRecord();
                        }
                    }, 250);
                } catch (err) {
                    console.error('Microphone access denied or failed:', err);
                    alert('لم يتم منح الوصول إلى الميكروفون أو حدث خطأ. تأكد من الأذونات.');
                }
            },

            stopHoldRecord() {
                if (!this.isRecording) return;
                this.isRecording = false;
                clearInterval(this.recordingTimerInterval);
                try { if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') this.mediaRecorder.stop(); } catch (e) {}
            },

            cancelHoldRecord() {
                if (!this.isRecording) return;
                // mark for discard
                this._recordCancelled = true;
                this.isRecording = false;
                clearInterval(this.recordingTimerInterval);
                try { if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') this.mediaRecorder.stop(); } catch (e) {}
            },

            scrollToBottom() {
                const container = this.$refs.chatContainer;
                container.scrollTop = container.scrollHeight;
            },

            openImage(src) {
                this.imageViewerSrc = src;
                this.isImageViewerOpen = true;
            },

            userColor(username) {
                if (!this.userColors[username]) {
                    let hash = 0;
                    for (let i = 0; i < username.length; i++) {
                        hash = username.charCodeAt(i) + ((hash << 5) - hash);
                    }
                    this.userColors[username] = this.colors[Math.abs(hash % this.colors.length)];
                }
                return this.userColors[username];
            },

            // Detect common audio file extensions
            isAudio(path) {
                if (!path) return false;
                return /\.(webm|ogg|mp3|wav)(\?.*)?$/i.test(path);
            }
        }
    }
</script>

</body>
</html>
