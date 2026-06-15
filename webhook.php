<?php
// ================================================
//   SMS GATEWAY BOT v3.0
//   Telegram + Termux SMS Gateway
// ================================================

// SOZLAMALAR — config.php dan o'qiladi (git'ga kirmaydi)
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    http_response_code(500);
    die('config.php topilmadi. config.example.php dan nusxa oling.');
}
$config = require $config_file;
$bot_token = $config['bot_token'];
$admin_ids = $config['admin_ids'];
// Migratsiya uchun bir nechta kalit qo'llab-quvvatlanadi (birinchisi — asosiy/yangi)
$api_keys  = $config['api_keys'] ?? [$config['api_key'] ?? ''];
$api_key   = $api_keys[0];
function keyValid($k) { global $api_keys; return in_array((string)$k, $api_keys, true); }

// Fayllar
$db_file = __DIR__ . '/sms.db';
$broadcast_state_file = __DIR__ . '/broadcast_state.txt';
$broadcast_config_file = __DIR__ . '/broadcast_config.json';
$night_mode_file = __DIR__ . '/night_mode.json';
$smart_break_file = __DIR__ . '/smart_break.txt';
$streak_file = __DIR__ . '/error_streak.txt';
$broadcast_batch_file = __DIR__ . '/broadcast_batch.json'; // joriy yuborish partiyasi (progress uchun)

date_default_timezone_set('Asia/Tashkent');

// ============ DATABASE ============
try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA busy_timeout=5000");

    // SMS navbat
    $db->exec("CREATE TABLE IF NOT EXISTS queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone TEXT NOT NULL,
        message TEXT NOT NULL,
        status TEXT DEFAULT 'pending',
        assigned_device TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Ustun mavjudligini tekshirish va qo'shish
    $cols = $db->query("PRAGMA table_info(queue)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('assigned_device', $cols)) {
        $db->exec("ALTER TABLE queue ADD COLUMN assigned_device TEXT DEFAULT NULL");
    }
    if (!in_array('updated_at', $cols)) {
        $db->exec("ALTER TABLE queue ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    }

    $db->exec("CREATE INDEX IF NOT EXISTS idx_queue_status ON queue(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_queue_status_device ON queue(status, assigned_device)");

    // Kontaktlar
    $db->exec("CREATE TABLE IF NOT EXISTS contacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone TEXT UNIQUE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Qurilmalar
    $db->exec("CREATE TABLE IF NOT EXISTS devices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id TEXT UNIQUE NOT NULL,
        name TEXT DEFAULT '',
        sim_slot INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        last_seen INTEGER DEFAULT 0,
        tasks_sent INTEGER DEFAULT 0,
        tasks_failed INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Ilova versiyasi ustuni (qaysi telefon yangilangan/eski ekanini bilish uchun)
    $dcols = $db->query("PRAGMA table_info(devices)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('app_version', $dcols)) {
        $db->exec("ALTER TABLE devices ADD COLUMN app_version INTEGER DEFAULT 0");
    }

}
catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// ============ YORDAMCHI FUNKSIYALAR ============

function e($text)
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function formatPhone($phone)
{
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (substr($phone, 0, 1) !== '+')
        $phone = '+' . $phone;
    return $phone;
}

/**
 * SMS uchun necha segment (alohida SMS) ketishini taxminlaydi.
 * GSM-7 ga sig'sa: 160 / segment (uzun bo'lsa 153). Kirilcha/emoji bo'lsa UCS-2:
 * 70 / segment (uzun bo'lsa 67). Ilova ham shunga mos ravishda multipart yuboradi.
 */
function smsSegments($text)
{
    $len = mb_strlen($text, 'UTF-8');
    if ($len === 0) return 0;
    // GSM-7 asosiy belgilar to'plami (kengaytma belgilarsiz — taxminiy)
    $gsm = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡"
         . "ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    $isGsm = true;
    for ($i = 0; $i < $len; $i++) {
        if (mb_strpos($gsm, mb_substr($text, $i, 1, 'UTF-8'), 0, 'UTF-8') === false) {
            $isGsm = false;
            break;
        }
    }
    if ($isGsm) {
        return $len <= 160 ? 1 : (int)ceil($len / 153);
    }
    return $len <= 70 ? 1 : (int)ceil($len / 67);
}

// ---- QURILMA FUNKSIYALARI ----

function registerDevice($device_id)
{
    global $db;
    $existing = $db->prepare("SELECT id FROM devices WHERE device_id=?");
    $existing->execute([$device_id]);
    if (!$existing->fetch()) {
        $db->prepare("INSERT INTO devices (device_id, name, last_seen) VALUES (?, ?, ?)")
            ->execute([$device_id, $device_id, time()]);
    }
    else {
        $db->prepare("UPDATE devices SET last_seen=? WHERE device_id=?")
            ->execute([time(), $device_id]);
    }
}

function updateHeartbeat($device_id)
{
    global $db;
    if (!$device_id)
        return;
    registerDevice($device_id);
    // Ilova o'z versiyasini (versionCode) yuborsa — saqlaymiz
    if (isset($_GET['app'])) {
        $db->prepare("UPDATE devices SET app_version=? WHERE device_id=?")
            ->execute([(int)$_GET['app'], $device_id]);
    }
}

function getOnlineDevices()
{
    global $db;
    $cutoff = time() - 90;
    $stmt = $db->prepare("SELECT * FROM devices WHERE is_active=1 AND last_seen > ?");
    $stmt->execute([$cutoff]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllDevices()
{
    global $db;
    return $db->query("SELECT * FROM devices ORDER BY last_seen DESC")->fetchAll(PDO::FETCH_ASSOC);
}

function getDevice($device_id)
{
    global $db;
    $stmt = $db->prepare("SELECT * FROM devices WHERE device_id=?");
    $stmt->execute([$device_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function isDeviceOnline($device)
{
    return (time() - ($device['last_seen'] ?? 0)) < 90;
}

function getDeviceStatusText()
{
    $devices = getAllDevices();
    $online = 0;
    foreach ($devices as $d) {
        if ($d['is_active'] && isDeviceOnline($d))
            $online++;
    }
    $total = count($devices);
    if ($total == 0)
        return ["icon" => "🔴", "text" => "Qurilma yo'q", "online" => 0, "total" => 0];
    if ($online == 0)
        return ["icon" => "🔴", "text" => "OFFLINE", "online" => 0, "total" => $total];
    return ["icon" => "🟢", "text" => "$online/$total ONLINE", "online" => $online, "total" => $total];
}

function getLastSeenText($timestamp)
{
    if (!$timestamp)
        return "Hech qachon";
    $diff = time() - $timestamp;
    if ($diff < 60)
        return $diff . "s oldin";
    if ($diff < 3600)
        return floor($diff / 60) . " daq oldin";
    if ($diff < 86400)
        return floor($diff / 3600) . " soat oldin";
    return floor($diff / 86400) . " kun oldin";
}

// ---- BROADCAST SOZLAMALARI ----

function getBroadcastState()
{
    global $broadcast_state_file;
    if (!file_exists($broadcast_state_file))
        return "active";
    return trim(file_get_contents($broadcast_state_file));
}

function setBroadcastState($state)
{
    global $broadcast_state_file;
    file_put_contents($broadcast_state_file, $state);
}

function getBroadcastConfig()
{
    global $broadcast_config_file;
    $default = ["mode" => "asc", "device_mode" => "all"];
    if (!file_exists($broadcast_config_file))
        return $default;
    $data = json_decode(file_get_contents($broadcast_config_file), true);
    return $data ? array_merge($default, $data) : $default;
}

function setBroadcastConfig($config)
{
    global $broadcast_config_file;
    file_put_contents($broadcast_config_file, json_encode($config));
}

function getNightModeConfig()
{
    global $night_mode_file;
    $default = ["enabled" => false, "start" => "22:35", "end" => "07:00"];
    if (!file_exists($night_mode_file))
        return $default;
    $data = json_decode(file_get_contents($night_mode_file), true);
    return $data ? array_merge($default, $data) : $default;
}

function setNightModeConfig($config)
{
    global $night_mode_file;
    file_put_contents($night_mode_file, json_encode($config));
}

function isNightModeActive()
{
    $nm = getNightModeConfig();
    if (!$nm['enabled'])
        return false;
    $now = date('H:i');
    if ($nm['start'] > $nm['end'])
        return ($now >= $nm['start'] || $now < $nm['end']);
    return ($now >= $nm['start'] && $now < $nm['end']);
}

function isSmartBreakActive()
{
    global $smart_break_file;
    if (!file_exists($smart_break_file))
        return false;
    $until = (int)file_get_contents($smart_break_file);
    if (time() < $until)
        return true;
    @unlink($smart_break_file);
    return false;
}

function getSmartBreakRemaining()
{
    global $smart_break_file;
    if (!file_exists($smart_break_file))
        return 0;
    $until = (int)file_get_contents($smart_break_file);
    $r = $until - time();
    return $r > 0 ? $r : 0;
}

function recoverStuckTasks()
{
    global $db;
    $db->exec("UPDATE queue SET status='pending', assigned_device=NULL, updated_at=CURRENT_TIMESTAMP
               WHERE status='processing' AND updated_at < datetime('now', '-120 seconds')");
}

function getModeTextShort($mode)
{
    switch ($mode) {
        case 'desc':
            return "🔼 Oxiridan";
        case 'random':
            return "🔀 Aralash";
        default:
            return "🔽 Boshidan";
    }
}

// ---- PROGRESS / PARTIYA ----

/** Matnli progress-bar: ███████░░░ 70% */
function progressBar($done, $total, $width = 12)
{
    if ($total <= 0)
        return str_repeat('░', $width) . " 0%";
    $ratio = max(0, min(1, $done / $total));
    $filled = (int)round($ratio * $width);
    $pct = (int)round($ratio * 100);
    return str_repeat('█', $filled) . str_repeat('░', $width - $filled) . " {$pct}%";
}

function getBatch()
{
    global $broadcast_batch_file;
    if (!file_exists($broadcast_batch_file))
        return null;
    $d = json_decode(file_get_contents($broadcast_batch_file), true);
    return $d ?: null;
}

function setBatch($startId, $total)
{
    global $broadcast_batch_file;
    file_put_contents($broadcast_batch_file, json_encode(['start_id' => (int)$startId, 'total' => (int)$total, 'ts' => time()]));
}

function clearBatch()
{
    global $broadcast_batch_file;
    if (file_exists($broadcast_batch_file))
        @unlink($broadcast_batch_file);
}

/** Jonli HOLAT kartasi: matn + holatga mos inline tugmalar qaytaradi. */
function buildDashboard()
{
    global $db;
    $ds = getDeviceStatusText();
    $bState = getBroadcastState();
    $conf = getBroadcastConfig();

    if (isSmartBreakActive())
        $state = "😴 Dam olmoqda (" . ceil(getSmartBreakRemaining() / 60) . " daq)";
    elseif (isNightModeActive()) {
        $nm = getNightModeConfig();
        $state = "🌙 Tungi rejim ({$nm['end']} gacha)";
    } elseif ($bState == "paused")
        $state = "⏸ To'xtatib turilgan";
    else
        $state = "▶️ Faol";

    $pending = (int)$db->query("SELECT COUNT(*) FROM queue WHERE status='pending'")->fetchColumn();
    $processing = (int)$db->query("SELECT COUNT(*) FROM queue WHERE status='processing'")->fetchColumn();
    $running = ($pending + $processing) > 0;

    $msg = "📊 <b>HOLAT</b>\n\n";
    $msg .= "{$ds['icon']} Qurilmalar: <b>{$ds['text']}</b>\n";
    $msg .= "⚙️ Jarayon: <b>$state</b>\n";

    $batch = getBatch();
    if ($batch && $batch['total'] > 0) {
        $start = (int)$batch['start_id'];
        $total = (int)$batch['total'];
        $sent = (int)$db->query("SELECT COUNT(*) FROM queue WHERE id>=$start AND status='sent'")->fetchColumn();
        $failed = (int)$db->query("SELECT COUNT(*) FROM queue WHERE id>=$start AND status='failed'")->fetchColumn();
        $done = $sent + $failed;
        $left = max(0, $total - $done);
        $title = $running ? "📨 Yuborilmoqda" : "✅ Yakunlandi";
        $msg .= "\n<b>$title</b>\n";
        $msg .= "<code>" . progressBar($done, $total) . "</code>\n";
        $msg .= "✅ <b>$sent</b> · ❌ <b>$failed</b> · ⏳ <b>$left</b>  (jami $total)\n";
    } elseif ($running) {
        $msg .= "\n📨 <b>Yuborilmoqda</b>\n";
        $msg .= "⏳ Navbatda: <b>$pending</b> · 🔄 Jarayonda: <b>$processing</b>\n";
    } else {
        $sentAll = (int)$db->query("SELECT COUNT(*) FROM queue WHERE status='sent'")->fetchColumn();
        $msg .= "\n📭 Faol yuborish yo'q\n";
        $msg .= "✅ Navbatdagi yuborilganlar: <b>$sentAll</b>\n";
    }

    $totalContacts = (int)$db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
    $msg .= "\n👥 Kontaktlar: <b>$totalContacts</b> · 🔄 " . getModeTextShort($conf['mode'] ?? 'asc');
    $msg .= "\n🕒 " . date('H:i:s');

    $rows = [];
    if ($running) {
        if ($bState == 'paused')
            $rows[] = [['text' => "▶️ Davom ettirish", 'callback_data' => "st_resume"], ['text' => "🔄 Yangilash", 'callback_data' => "st_refresh"]];
        else
            $rows[] = [['text' => "⏸ Pauza", 'callback_data' => "st_pause"], ['text' => "🔄 Yangilash", 'callback_data' => "st_refresh"]];
        $rows[] = [['text' => "🛑 To'xtatish (navbatni o'chirish)", 'callback_data' => "st_stop"]];
    } else {
        $rows[] = [['text' => "🔄 Yangilash", 'callback_data' => "st_refresh"]];
    }

    return ['text' => $msg, 'kb' => ['inline_keyboard' => $rows]];
}

/** Ixcham qurilmalar ro'yxati: sarlavha-xulosa + har qurilmaga 1 ta tugma. */
function buildDeviceList()
{
    global $db;
    $devices = getAllDevices();
    if (empty($devices))
        return ['text' => "📱 <b>QURILMALAR</b>\n\nHech qanday qurilma ulanmagan.\n\n<i>Ilovani telefonda ishga tushiring — qurilma avtomatik ro'yxatga olinadi.</i>", 'kb' => null];

    $online = 0;
    $offline = 0;
    $sentSum = 0;
    $failSum = 0;
    foreach ($devices as $d) {
        if (isDeviceOnline($d))
            $online++;
        else
            $offline++;
        $sentSum += (int)$d['tasks_sent'];
        $failSum += (int)$d['tasks_failed'];
    }
    $total = count($devices);

    $msg = "📱 <b>QURILMALAR</b>\n\n";
    $msg .= "🟢 Onlayn: <b>$online</b>" . ($offline ? " · 🔴 Oflayn: <b>$offline</b>" : "") . "\n";
    $msg .= "✅ Jami yuborildi: <b>$sentSum</b> · ❌ <b>$failSum</b>\n\n";
    $msg .= "<i>Boshqarish uchun qurilmani tanlang</i> 👇";

    $buttons = [];
    foreach ($devices as $d) {
        $icon = ($d['is_active'] && isDeviceOnline($d)) ? "🟢" : ($d['is_active'] ? "🔴" : "⛔");
        $name = $d['name'] ?: $d['device_id'];
        if (mb_strlen($name, 'UTF-8') > 20)
            $name = mb_substr($name, 0, 20, 'UTF-8');
        $buttons[] = [['text' => "$icon $name · {$d['tasks_sent']}✓", 'callback_data' => "dev_" . $d['device_id']]];
    }

    // Oflayn (eski/takror) qurilmalarni bitta tugma bilan tozalash
    if ($offline > 0)
        $buttons[] = [['text' => "🧹 Oflaynlarni o'chirish ($offline)", 'callback_data' => "dev_clean_offline"]];

    // Yuborish qurilmasini tanlash faqat bir nechta qurilma bo'lsa kerak
    if ($total >= 2) {
        $conf = getBroadcastConfig();
        $dm = $conf['device_mode'] ?? 'all';
        if ($dm == 'all')
            $dmText = "Hammasi";
        elseif ($dm == 'round_robin')
            $dmText = "Teng bo'lib";
        else {
            $dev = getDevice($dm);
            $dmText = $dev ? ($dev['name'] ?: $dev['device_id']) : $dm;
        }
        $buttons[] = [['text' => "🎯 Yuborish qurilmasi: $dmText", 'callback_data' => "devtarget"]];
    }

    return ['text' => $msg, 'kb' => ['inline_keyboard' => $buttons]];
}

// ---- KEYBOARDS ----

function mainKeyboard()
{
    return ['keyboard' => [
            [['text' => "✉️ Ommaviy SMS"]],
            [['text' => "📊 Holat"], ['text' => "🧪 Test SMS"]],
            [['text' => "📱 Qurilmalar"], ['text' => "📂 Kontaktlar"]],
            [['text' => "🌙 Tungi rejim"], ['text' => "🗑️ Tozalash"]]
        ], 'resize_keyboard' => true];
}

function controlKeyboard()
{
    // Endi asosiy menyu — eski "Boshqaruv" tugmalari Holat kartasiga ko'chirildi.
    return mainKeyboard();
}

// ============ TELEGRAM API ============

function telegramRequest($method, $data)
{
    global $bot_token;
    $ch = curl_init("https://api.telegram.org/bot$bot_token/$method");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function sendMessage($chat_id, $text, $keyboard = null, $parse = null)
{
    $data = ['chat_id' => $chat_id, 'text' => $text];
    if ($keyboard)
        $data['reply_markup'] = json_encode($keyboard);
    if ($parse)
        $data['parse_mode'] = $parse;
    return telegramRequest('sendMessage', $data);
}

function deleteMessage($chat_id, $message_id)
{
    return telegramRequest('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
}

function editMessage($chat_id, $message_id, $text, $keyboard = null, $parse = null)
{
    $data = ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text];
    if ($keyboard)
        $data['reply_markup'] = json_encode($keyboard);
    if ($parse)
        $data['parse_mode'] = $parse;
    return telegramRequest('editMessageText', $data);
}

function answerCallback($callback_id, $text)
{
    return telegramRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => $text]);
}

function getTelegramFile($file_id)
{
    global $bot_token;
    $j = json_decode(file_get_contents("https://api.telegram.org/bot$bot_token/getFile?file_id=$file_id"), true);
    return isset($j['result']['file_path'])
        ? "https://api.telegram.org/file/bot$bot_token/" . $j['result']['file_path'] : false;
}

function alertAdmins($text)
{
    global $admin_ids;
    foreach ($admin_ids as $id)
        sendMessage($id, $text, null, "HTML");
}

// ============ CRM API (Tashqi integratsiya) ============
if (isset($_GET['action']) && in_array($_GET['action'], ['send', 'check_status', 'stats'])) {
    header('Content-Type: application/json');
    if (!keyValid($_GET['key'] ?? '')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    $action = $_GET['action'];

    // 1. SMS yuborish
    if ($action == 'send') {
        $phone = formatPhone($_GET['phone'] ?? '');
        $text = trim($_GET['text'] ?? '');
        if (empty($text) || strlen($phone) < 7) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO queue (phone, message, status) VALUES (:p, :m, 'test_pending')");
        $stmt->execute([':p' => $phone, ':m' => $text]);
        echo json_encode(['status' => 'ok', 'id' => $db->lastInsertId()]);
        exit;
    }

    // 2. Holatni tekshirish
    if ($action == 'check_status') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT status, updated_at FROM queue WHERE id = ?");
        $stmt->execute([$id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$res) {
            echo json_encode(['status' => 'error', 'message' => 'Not found']);
            exit;
        }
        echo json_encode(['status' => 'ok', 'sms_status' => $res['status'], 'updated_at' => $res['updated_at']]);
        exit;
    }

    // 3. Umumiy statistika
    if ($action == 'stats') {
        $stats = $db->query("SELECT status, COUNT(*) as cnt FROM queue GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $devices = getOnlineDevices();
        $out = [
            'status' => 'ok',
            'queue' => [
                'pending' => $stats['pending'] ?? 0,
                'sent' => $stats['sent'] ?? 0,
                'failed' => $stats['failed'] ?? 0
            ],
            'online_devices' => count($devices)
        ];
        // Batafsil qurilmalar ro'yxati (diagnostika uchun) — ?devices=1
        if (!empty($_GET['devices'])) {
            $list = [];
            foreach (getAllDevices() as $d) {
                $list[] = [
                    'device_id' => $d['device_id'],
                    'name' => $d['name'],
                    'online' => isDeviceOnline($d) ? 1 : 0,
                    'active' => (int)$d['is_active'],
                    'last_seen_ago_s' => time() - (int)($d['last_seen'] ?? 0),
                    'app' => (int)($d['app_version'] ?? 0),
                    'sent' => (int)$d['tasks_sent'],
                    'failed' => (int)$d['tasks_failed'],
                ];
            }
            $out['devices'] = $list;
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Eski metod uchun (agar kimdir ishlatayotgan bo'lsa)
if (isset($_GET['phone']) && isset($_GET['text']) && !isset($_GET['action'])) {
    header('Content-Type: application/json');
    if (keyValid($_GET['key'] ?? '')) {
        $phone = formatPhone($_GET['phone']);
        $text = trim($_GET['text']);
        $stmt = $db->prepare("INSERT INTO queue (phone, message, status) VALUES (:p, :m, 'test_pending')");
        $stmt->execute([':p' => $phone, ':m' => $text]);
        echo json_encode(['status' => 'ok', 'id' => $db->lastInsertId()]);
        exit;
    }
}

// ============ DEVICE API ============
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $key = $_GET['key'] ?? '';
    // charset=utf-8: emoji va kirilcha matn ilovaga buzilmasdan yetib borishi uchun
    header('Content-Type: application/json; charset=utf-8');

    // Key tekshirish
    if (!keyValid($key) && $action !== 'heartbeat' && $action !== 'version') {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        exit;
    }

    if ($action == 'heartbeat') {
        $device_id = $_GET['device_id'] ?? null;
        if ($device_id)
            updateHeartbeat($device_id);
        echo json_encode(["status" => "ok", "time" => time()]);
        exit;
    }

    // Ilova versiyasini tekshirish (kalit talab qilinmaydi) — majburiy yangilanish uchun
    if ($action == 'version') {
        echo json_encode([
            "status"      => "ok",
            "latest_code" => 7,            // app versionCode
            "latest_name" => "0.5.0",      // app versionName
            "url"         => "https://sms.idrokedu.uz/SmsGateway.apk",
            "force"       => true
        ]);
        exit;
    }

    // Kiruvchi qo'ng'iroq
    if ($action == 'incoming_call') {
        $phone = e($_GET['phone'] ?? 'Unknown');
        $device = e($_GET['device_id'] ?? '?');
        alertAdmins("📞 <b>KIRUVCHI QO'NG'IROQ</b>\n\n👤 Kimdan: <code>$phone</code>\n📱 Qurilma: <code>$device</code>");
        echo json_encode(["status" => "ok"]);
        exit;
    }

    // Xatolik
    if ($action == 'error') {
        $msg = e($_GET['msg'] ?? 'Unknown');
        $device = e($_GET['device_id'] ?? '?');

        $last_err_file = __DIR__ . '/last_error_' . md5($device) . '.txt';
        $last_err = file_exists($last_err_file) ? file_get_contents($last_err_file) : '';

        if ($msg !== $last_err) {
            alertAdmins("🚨 <b>XATOLIK!</b>\n\n📱 Qurilma: <code>$device</code>\n⚠️: <code>$msg</code>");
            file_put_contents($last_err_file, $msg);
        }
        echo json_encode(["status" => "ok"]);
        exit;
    }

    // Kiruvchi SMS
    if ($action == 'incoming_sms') {
        $phone = e($_GET['phone'] ?? 'Unknown');
        $msg = e($_GET['msg'] ?? '');
        $device = e($_GET['device_id'] ?? '?');
        // if ($msg) alertAdmins("📩 <b>YANGI SMS</b>\n\n📞 Kimdan: <b>$phone</b>\n📱 Qurilma: <code>$device</code>\n📝 Xabar:\n<code>$msg</code>");
        echo json_encode(["status" => "ok"]);
        exit;
    }

    // ============ TASK OLISH ============
    if ($action == 'get_task') {
        $device_id = $_GET['device_id'] ?? null;
        if ($device_id)
            updateHeartbeat($device_id);

        $dev = $device_id ? getDevice($device_id) : null;
        if ($dev && !$dev['is_active']) {
            echo json_encode(["status" => "empty", "reason" => "device_disabled"]);
            exit;
        }

        recoverStuckTasks();

        $task = null;
        try {
            // Mutlaq qulflash - boshqa hech bir so'rov kirib kela olmaydi
            $db->exec("BEGIN EXCLUSIVE");

            // 1. Birinchi navbatda TEST SMSlarni tekshiramiz
            $sql = "SELECT id, phone, message FROM queue WHERE status='test_pending'";
            $params = [];
            if ($device_id) {
                $sql .= " AND (assigned_device IS NULL OR assigned_device=?)";
                $params[] = $device_id;
            }
            $sql .= " LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            // 2. Agar TEST SMS bo'lmasa, navbatdagi BROADCAST SMSni qidiramiz
            if (!$task && !isSmartBreakActive() && getBroadcastState() == "active" && !isNightModeActive()) {
                $conf = getBroadcastConfig();
                $mode = $conf['mode'] ?? 'asc';
                $deviceMode = $conf['device_mode'] ?? 'all';

                $canProcess = true;
                if ($deviceMode !== 'all' && $deviceMode !== 'round_robin' && $device_id !== $deviceMode) {
                    $canProcess = false;
                }

                if ($canProcess) {
                    $deviceFilterSQL = "";
                    $filterParams = [];
                    if ($deviceMode === 'round_robin' && $device_id) {
                        $deviceFilterSQL = " AND (assigned_device IS NULL OR assigned_device=?)";
                        $filterParams[] = $device_id;
                    }

                    if ($mode == "random") {
                        $sql = "SELECT id FROM queue WHERE status='pending'" . $deviceFilterSQL . " LIMIT 100";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($filterParams);
                        $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($candidates)) {
                            $randomId = $candidates[array_rand($candidates)];
                            $stmt = $db->prepare("SELECT id, phone, message FROM queue WHERE id=?");
                            $stmt->execute([$randomId]);
                            $task = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                    }
                    else {
                        $orderBy = ($mode == "desc") ? "id DESC" : "id ASC";
                        $sql = "SELECT id, phone, message FROM queue WHERE status='pending'" . $deviceFilterSQL . " ORDER BY $orderBy LIMIT 1";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($filterParams);
                        $task = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                }
            }

            // 3. Agar vazifa topilgan bo'lsa, uni darhol band qilamiz
            if ($task) {
                $db->prepare("UPDATE queue SET status='processing', assigned_device=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
                    ->execute([$device_id, $task['id']]);
            }

            $db->exec("COMMIT");
        }
        catch (Exception $e) {
            if ($db->inTransaction())
                $db->exec("ROLLBACK");
            echo json_encode(["status" => "empty", "reason" => "db_busy"]);
            exit;
        }

        if ($task) {
            // Ilova qaysi SIM'dan yuborishini bilishi uchun qurilma sim_slot ini qo'shamiz
            $task['sim_slot'] = (int)($dev['sim_slot'] ?? 0);
            // JSON_UNESCAPED_UNICODE: emoji (4-baytli UTF-8) \uXXXX surrogat juftlarsiz,
            // xom UTF-8 sifatida yuboriladi — ilovada buzilmaydi.
            echo json_encode($task, JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["status" => "empty"]);
        }
        exit;
    }

    // ============ STATUS YANGILASH ============
    if ($action == 'update') {
        $id = $_GET['id'] ?? 0;
        $status = $_GET['status'] ?? '';
        $device_id = $_GET['device_id'] ?? null;

        if (!$id || !in_array($status, ['sent', 'failed'])) {
            echo json_encode(["status" => "error", "message" => "Invalid params"]);
            exit;
        }

        $db->prepare("UPDATE queue SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$status, $id]);

        // Qurilma statistikasini yangilash
        if ($device_id) {
            if ($status == 'sent') {
                $db->prepare("UPDATE devices SET tasks_sent = tasks_sent + 1 WHERE device_id=?")->execute([$device_id]);
            }
            else {
                $db->prepare("UPDATE devices SET tasks_failed = tasks_failed + 1 WHERE device_id=?")->execute([$device_id]);
            }
        }

        // Smart Break
        if ($status == 'failed') {
            $streak = file_exists($GLOBALS['streak_file']) ? (int)file_get_contents($GLOBALS['streak_file']) : 0;
            $streak++;
            file_put_contents($GLOBALS['streak_file'], $streak);
            if ($streak >= 10) {
                file_put_contents($GLOBALS['smart_break_file'], time() + 300);
                file_put_contents($GLOBALS['streak_file'], 0);
                alertAdmins("😴 <b>SMART BREAK</b>\n\nKetma-ket 10 xato! 5 daq dam olish.\n📱 Qurilma: <code>" . e($device_id ?? '?') . "</code>");
            }
        }
        else {
            file_put_contents($GLOBALS['streak_file'], 0);
        }

        echo json_encode(["status" => "ok"]);
        exit;
    }

    echo json_encode(["status" => "error", "message" => "Unknown action"]);
    exit;
}

// ============ TELEGRAM WEBHOOK ============
$update = json_decode(file_get_contents('php://input'), true);
if (!$update)
    exit;

// ============ CALLBACK QUERY ============
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $chat_id = $cb['message']['chat']['id'];
    $msg_id = $cb['message']['message_id'];
    $user_id = $cb['from']['id'];
    $data = $cb['data'];

    if (!in_array($user_id, $admin_ids)) {
        answerCallback($cb['id'], "⛔ Ruxsat yo'q!");
        exit;
    }

    // ---- MODE CHANGE ----
    if (strpos($data, "set_mode_") === 0) {
        $newMode = str_replace("set_mode_", "", $data);
        if (!in_array($newMode, ['asc', 'desc', 'random']))
            exit;
        $conf = getBroadcastConfig();
        $conf['mode'] = $newMode;
        setBroadcastConfig($conf);
        answerCallback($cb['id'], "✅ Rejim: $newMode");
        sendMessage($chat_id, "🔄 Tartib o'zgartirildi: <b>" . getModeTextShort($newMode) . "</b>", mainKeyboard(), "HTML");
        exit;
    }

    // ---- DEVICE MODE (broadcast uchun qurilma tanlash) ----
    if (strpos($data, "devmode_") === 0) {
        $selected = str_replace("devmode_", "", $data);
        $conf = getBroadcastConfig();
        $conf['device_mode'] = $selected;
        setBroadcastConfig($conf);

        if ($selected == 'all')
            $label = "📱 Barcha qurilmalar";
        elseif ($selected == 'round_robin')
            $label = "🔄 Teng taqsimlash";
        else {
            $dev = getDevice($selected);
            $label = "📱 " . ($dev ? ($dev['name'] ?: $dev['device_id']) : $selected);
        }

        answerCallback($cb['id'], "✅ $label");
        // Tanlovdan keyin ixcham qurilmalar ro'yxatiga qaytamiz (inline)
        $dl = buildDeviceList();
        editMessage($chat_id, $msg_id, $dl['text'], $dl['kb'], "HTML");
        exit;
    }

    // ---- BROADCAST: TARTIB TANLASH ----
    if (strpos($data, "order_") === 0) {
        $parts = explode("_", $data);
        if (count($parts) < 3)
            exit;
        $mode = $parts[1];
        if (!in_array($mode, ['asc', 'desc', 'random']))
            exit;

        $msg_file = __DIR__ . "/pending_msg_" . $chat_id . ".txt";
        if (!file_exists($msg_file)) {
            answerCallback($cb['id'], "❌ Xabar topilmadi");
            exit;
        }

        $message = file_get_contents($msg_file);
        $conf = getBroadcastConfig();
        $conf['mode'] = $mode;
        setBroadcastConfig($conf);

        // Qurilma tanlash tugmalari
        $devices = getAllDevices();
        $dev_buttons = [];
        $dev_buttons[] = [
            ['text' => "📱 Hammasi (barcha telefon)", 'callback_data' => "bcast_dev_all_" . $parts[2]],
        ];
        // "Teng bo'lib" va alohida telefon tanlash faqat bir nechta qurilma bo'lsa kerak
        $multi = count($devices) >= 2;
        if ($multi) {
            $dev_buttons[] = [
                ['text' => "🔄 Teng bo'lib (har telefonga teng)", 'callback_data' => "bcast_dev_rr_" . $parts[2]],
            ];
            foreach ($devices as $d) {
                $icon = isDeviceOnline($d) ? "🟢" : "🔴";
                $name = $d['name'] ?: $d['device_id'];
                $dev_buttons[] = [
                    ['text' => "$icon $name", 'callback_data' => "bcast_dev_" . $d['device_id'] . "_" . $parts[2]]
                ];
            }
        }

        $dev_buttons[] = [['text' => "❌ Bekor qilish", 'callback_data' => "cancel_broadcast"]];

        $count = $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
        $preview = mb_strlen($message, 'UTF-8') > 80 ? mb_substr($message, 0, 80, 'UTF-8') . "..." : $message;

        $msg = "📱 <b>QAYSI TELEFONDAN?</b>\n\n";
        $msg .= "📝 Xabar: <code>" . e($preview) . "</code>\n";
        $msg .= "👥 Qabul qiluvchilar: <b>$count</b> ta\n";
        $msg .= "🔄 Tartib: <b>" . getModeTextShort($mode) . "</b>\n";
        if ($multi)
            $msg .= "\nℹ️ <i>Har kontakt 1 marta oladi. \"Teng bo'lib\" = ro'yxat telefonlarga teng bo'linadi.</i>";

        deleteMessage($chat_id, $msg_id);
        sendMessage($chat_id, $msg, ['inline_keyboard' => $dev_buttons], "HTML");
        exit;
    }

    // ---- BROADCAST: QURILMA TANLASH VA TASDIQLASH ----
    if (strpos($data, "bcast_dev_") === 0) {
        $rest = str_replace("bcast_dev_", "", $data);

        // Determine device mode and hash
        if (strpos($rest, "all_") === 0) {
            $deviceMode = "all";
            $msg_hash = substr($rest, 4);
        }
        elseif (strpos($rest, "rr_") === 0) {
            $deviceMode = "round_robin";
            $msg_hash = substr($rest, 3);
        }
        else {
            $lastUnderscore = strrpos($rest, "_");
            $deviceMode = substr($rest, 0, $lastUnderscore);
            $msg_hash = substr($rest, $lastUnderscore + 1);
        }

        $conf = getBroadcastConfig();
        $conf['device_mode'] = $deviceMode;
        setBroadcastConfig($conf);

        $msg_file = __DIR__ . "/pending_msg_" . $chat_id . ".txt";
        if (!file_exists($msg_file)) {
            answerCallback($cb['id'], "❌ Xabar topilmadi");
            exit;
        }

        $message = file_get_contents($msg_file);
        $count = $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
        $preview = mb_strlen($message, 'UTF-8') > 80 ? mb_substr($message, 0, 80, 'UTF-8') . "..." : $message;

        if ($deviceMode == 'all')
            $devLabel = "📱 Barcha qurilmalar";
        elseif ($deviceMode == 'round_robin')
            $devLabel = "🔄 Teng taqsimlash";
        else {
            $dev = getDevice($deviceMode);
            $devLabel = "📱 " . ($dev ? ($dev['name'] ?: $dev['device_id']) : $deviceMode);
        }

        $confirmMsg = "📋 <b>TASDIQLASH</b>\n\n";
        $confirmMsg .= "👥 Qabul qiluvchilar: <b>$count</b> ta\n";
        $confirmMsg .= "🔄 Tartib: <b>" . getModeTextShort($conf['mode']) . "</b>\n";
        $confirmMsg .= "📱 Qurilma: <b>$devLabel</b>\n";
        $confirmMsg .= "📝 Xabar:\n<code>" . e($preview) . "</code>\n\n";
        $confirmMsg .= "⚠️ Yuborishni tasdiqlaysizmi?";

        $confirm_kb = ['inline_keyboard' => [
                [
                    ['text' => "✅ YUBORISH", 'callback_data' => "confirm_" . $msg_hash],
                    ['text' => "❌ Bekor", 'callback_data' => "cancel_broadcast"]
                ]
            ]];

        deleteMessage($chat_id, $msg_id);
        sendMessage($chat_id, $confirmMsg, $confirm_kb, "HTML");
        exit;
    }

    // ---- BROADCAST TASDIQLASH ----
    if (strpos($data, "confirm_") === 0) {
        $msg_file = __DIR__ . "/pending_msg_" . $chat_id . ".txt";
        if (!file_exists($msg_file)) {
            answerCallback($cb['id'], "❌ Xabar topilmadi");
            exit;
        }

        $message = file_get_contents($msg_file);
        $contacts = $db->query("SELECT phone FROM contacts")->fetchAll(PDO::FETCH_COLUMN);
        $conf = getBroadcastConfig();
        $deviceMode = $conf['device_mode'] ?? 'all';

        // Round-robin uchun qurilmalar ro'yxati
        $rrDevices = [];
        if ($deviceMode === 'round_robin') {
            $online = getOnlineDevices();
            foreach ($online as $d)
                $rrDevices[] = $d['device_id'];
            if (empty($rrDevices))
                $rrDevices = [null]; // fallback
        }

        // Joriy partiya boshlanish ID si (progress hisobi uchun)
        $startId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM queue")->fetchColumn() + 1;

        $ins = $db->prepare("INSERT INTO queue (phone, message, assigned_device) VALUES (:p, :m, :d)");
        $db->beginTransaction();
        $added = 0;
        $rrIndex = 0;
        foreach ($contacts as $phone) {
            $assignedDevice = null;
            if ($deviceMode === 'round_robin' && !empty($rrDevices)) {
                $assignedDevice = $rrDevices[$rrIndex % count($rrDevices)];
                $rrIndex++;
            }
            elseif ($deviceMode !== 'all' && $deviceMode !== 'round_robin') {
                $assignedDevice = null; // device_mode tekshiruvi get_task da
            }
            $ins->execute([':p' => $phone, ':m' => $message, ':d' => $assignedDevice]);
            $added++;
        }
        $db->commit();

        @unlink($msg_file);
        setBroadcastState("active");
        setBatch($startId, $added);

        deleteMessage($chat_id, $msg_id);
        sendMessage($chat_id, "🚀 <b>$added</b> ta SMS navbatga qo'shildi! Yuborish boshlandi 👇", mainKeyboard(), "HTML");
        // Darhol jonli HOLAT kartasini ko'rsatamiz — jarayonni shu yerdan kuzatasiz
        $dash = buildDashboard();
        sendMessage($chat_id, $dash['text'], $dash['kb'], "HTML");
        answerCallback($cb['id'], "🚀 Boshlandi!");
        exit;
    }

    // ---- BEKOR QILISH ----
    if ($data == "cancel_broadcast") {
        @unlink(__DIR__ . "/pending_msg_" . $chat_id . ".txt");
        deleteMessage($chat_id, $msg_id);
        sendMessage($chat_id, "❌ Bekor qilindi.", mainKeyboard());
        answerCallback($cb['id'], "❌ Bekor");
        exit;
    }

    // ---- HOLAT KARTASI TUGMALARI (jonli) ----
    if ($data == "st_refresh") {
        $dash = buildDashboard();
        editMessage($chat_id, $msg_id, $dash['text'], $dash['kb'], "HTML");
        answerCallback($cb['id'], "🔄 Yangilandi");
        exit;
    }
    if ($data == "st_pause") {
        setBroadcastState("paused");
        $dash = buildDashboard();
        editMessage($chat_id, $msg_id, $dash['text'], $dash['kb'], "HTML");
        answerCallback($cb['id'], "⏸ To'xtatildi");
        exit;
    }
    if ($data == "st_resume") {
        setBroadcastState("active");
        if (file_exists($smart_break_file))
            @unlink($smart_break_file);
        @file_put_contents($streak_file, 0);
        $dash = buildDashboard();
        editMessage($chat_id, $msg_id, $dash['text'], $dash['kb'], "HTML");
        answerCallback($cb['id'], "▶️ Davom");
        exit;
    }
    if ($data == "st_stop") {
        $p = $db->query("SELECT COUNT(*) FROM queue WHERE status IN ('pending','processing')")->fetchColumn();
        editMessage($chat_id, $msg_id, "🛑 <b>To'xtatishni tasdiqlang</b>\n\nNavbatdagi <b>$p</b> ta SMS o'chiriladi (yuborilganlar qoladi).", ['inline_keyboard' => [[
            ['text' => "🛑 Ha, to'xtat", 'callback_data' => "stop_confirm"],
            ['text' => "🔙 Yo'q", 'callback_data' => "stop_cancel"]
        ]]], "HTML");
        answerCallback($cb['id'], "");
        exit;
    }

    // ---- STOP ----
    if ($data == "stop_confirm") {
        $deleted = $db->query("SELECT COUNT(*) FROM queue WHERE status IN ('pending','processing')")->fetchColumn();
        $db->exec("DELETE FROM queue WHERE status IN ('pending','processing')");
        setBroadcastState("active");
        clearBatch();
        @file_put_contents($streak_file, 0);
        if (file_exists($smart_break_file))
            @unlink($smart_break_file);
        deleteMessage($chat_id, $msg_id);
        sendMessage($chat_id, "🛑 <b>To'xtatildi!</b> $deleted ta xabar o'chirildi.", mainKeyboard(), "HTML");
        answerCallback($cb['id'], "🛑 To'xtatildi");
        exit;
    }
    if ($data == "stop_cancel") {
        $dash = buildDashboard();
        editMessage($chat_id, $msg_id, $dash['text'], $dash['kb'], "HTML");
        answerCallback($cb['id'], "Bekor");
        exit;
    }

    // ---- QURILMA BOSHQARUVI ----
    if ($data == "dev_list") {
        $dl = buildDeviceList();
        editMessage($chat_id, $msg_id, $dl['text'], $dl['kb'], "HTML");
        answerCallback($cb['id'], "📱 Qurilmalar");
        exit;
    }

    // ---- OFLAYN (eski/takror) QURILMALARNI O'CHIRISH ----
    if ($data == "dev_clean_offline") {
        $cutoff = time() - 90;
        $del = $db->prepare("DELETE FROM devices WHERE last_seen < ?");
        $del->execute([$cutoff]);
        $n = $del->rowCount();
        $dl = buildDeviceList();
        editMessage($chat_id, $msg_id, $dl['text'], $dl['kb'], "HTML");
        answerCallback($cb['id'], "🧹 $n ta oflayn qurilma o'chirildi");
        exit;
    }

    // ---- BROADCAST QURILMA TANLASH (jonli) ----
    if ($data == "devtarget") {
        $devices = getAllDevices();
        $rows = [];
        $rows[] = [
            ['text' => "📱 Hammasi", 'callback_data' => "devmode_all"],
            ['text' => "🔄 Teng bo'lib", 'callback_data' => "devmode_round_robin"]
        ];
        foreach ($devices as $d) {
            $icon = isDeviceOnline($d) ? "🟢" : "🔴";
            $name = $d['name'] ?: $d['device_id'];
            $rows[] = [['text' => "$icon $name", 'callback_data' => "devmode_" . $d['device_id']]];
        }
        $rows[] = [['text' => "🔙 Ortga", 'callback_data' => "dev_list"]];
        $txt = "🎯 <b>Yuborish qurilmasi</b>\n\n"
            . "📱 <b>Hammasi</b> — barcha onlayn telefonlar birga yuboradi (eng tez). Tavsiya etiladi.\n\n"
            . "🔄 <b>Teng bo'lib</b> — kontaktlar telefonlarga teng bo'linadi (har bir telefon teng miqdorda yuboradi).\n\n"
            . "Yoki faqat bitta telefonni tanlang.\n\n"
            . "ℹ️ <i>Har bir kontakt baribir faqat 1 marta SMS oladi — bu \"bitta odamga 10 raqamdan yuborish\" emas.</i>";
        editMessage($chat_id, $msg_id, $txt, ['inline_keyboard' => $rows], "HTML");
        answerCallback($cb['id'], "🎯 Tanlang");
        exit;
    }

    // ---- BITTA QURILMA SOZLAMALARI ----
    if (strpos($data, "dev_") === 0 && $data !== "dev_list" && $data !== "dev_back") {

        // Device action: dev_{device_id}, dev_toggle_{id}, dev_rename_{id}, dev_sim_{id}_{slot}, dev_del_{id}, dev_reset_{id}
        if (strpos($data, "dev_toggle_") === 0) {
            $did = str_replace("dev_toggle_", "", $data);
            $dev = getDevice($did);
            if ($dev) {
                $newState = $dev['is_active'] ? 0 : 1;
                $db->prepare("UPDATE devices SET is_active=? WHERE device_id=?")->execute([$newState, $did]);
                answerCallback($cb['id'], $newState ? "✅ Yoqildi" : "🔴 O'chirildi");
            }
            // Refresh device page
            $data = "dev_" . $did;
        }

        if (strpos($data, "dev_sim_") === 0) {
            $rest = str_replace("dev_sim_", "", $data);
            $parts = explode("_", $rest);
            $slot = array_pop($parts);
            $did = implode("_", $parts);
            $db->prepare("UPDATE devices SET sim_slot=? WHERE device_id=?")->execute([(int)$slot, $did]);
            answerCallback($cb['id'], "✅ SIM $slot tanlandi");
            $data = "dev_" . $did;
        }

        if (strpos($data, "dev_rename_") === 0) {
            $did = str_replace("dev_rename_", "", $data);
            file_put_contents(__DIR__ . "/status_" . $chat_id . ".txt", "RENAME_DEVICE:" . $did);
            deleteMessage($chat_id, $msg_id);
            sendMessage($chat_id, "✏️ <b>Qurilma nomini kiriting:</b>\n\n<i>Masalan: Ali telefoni, Ofis Samsung, va h.k.</i>", ['remove_keyboard' => true], "HTML");
            answerCallback($cb['id'], "✏️ Nom kiriting");
            exit;
        }

        if (strpos($data, "dev_del_") === 0) {
            $did = str_replace("dev_del_", "", $data);
            $wasOnline = ($d = getDevice($did)) && isDeviceOnline($d);
            $db->prepare("DELETE FROM devices WHERE device_id=?")->execute([$did]);
            // Onlayn (tirik) qurilma o'chirilsa, keyingi signalda qaytadi — shuni ogohlantiramiz.
            answerCallback($cb['id'], $wasOnline ? "🗑 O'chirildi — lekin telefon yoniq bo'lsa qaytadi" : "🗑 O'chirildi");
            $dl = buildDeviceList();
            editMessage($chat_id, $msg_id, $dl['text'], $dl['kb'] ?: ['inline_keyboard' => [[['text' => "🔙 Ortga", 'callback_data' => "dev_back"]]]], "HTML");
            exit;
        }

        if (strpos($data, "dev_reset_") === 0) {
            $did = str_replace("dev_reset_", "", $data);
            $db->prepare("UPDATE devices SET tasks_sent=0, tasks_failed=0 WHERE device_id=?")->execute([$did]);
            answerCallback($cb['id'], "🔄 Statistika tozalandi");
            $data = "dev_" . $did;
        }

        // Qurilma sahifasi
        $did = str_replace("dev_", "", $data);
        $dev = getDevice($did);
        if (!$dev) {
            answerCallback($cb['id'], "❌ Qurilma topilmadi");
            exit;
        }

        $icon = ($dev['is_active'] && isDeviceOnline($dev)) ? "🟢 ONLINE" : ($dev['is_active'] ? "🟡 OFFLINE" : "🔴 O'CHIRILGAN");
        $name = $dev['name'] ?: $dev['device_id'];
        $simText = $dev['sim_slot'] == 0 ? "Default" : "SIM " . $dev['sim_slot'];
        $appv = (int)($dev['app_version'] ?? 0);
        $appText = $appv >= 4 ? "✅ yangi (code $appv)" : ($appv > 0 ? "⚠️ eski (code $appv)" : "❓ noma'lum");
        $total = $dev['tasks_sent'] + $dev['tasks_failed'];
        $rate = $total > 0 ? round($dev['tasks_sent'] / $total * 100) : 0;

        $msg = "📱 <b>" . e($name) . "</b>\n\n";
        $msg .= "🆔 ID: <code>{$dev['device_id']}</code>\n";
        $msg .= "📶 Holat: <b>$icon</b>\n";
        $msg .= "📡 SIM: <b>$simText</b>\n";
        $msg .= "📦 Ilova: <b>$appText</b>\n";
        $msg .= "⏱ Oxirgi signal: " . getLastSeenText($dev['last_seen']) . "\n\n";
        $msg .= "📊 <b>Statistika:</b>\n";
        $msg .= "✅ Yuborildi: <b>{$dev['tasks_sent']}</b>\n";
        $msg .= "❌ Xato: <b>{$dev['tasks_failed']}</b>\n";
        $msg .= "📈 Muvaffaqiyat: <b>{$rate}%</b>";

        $toggleText = $dev['is_active'] ? "🔴 O'chirish" : "🟢 Yoqish";

        $buttons = [
            [
                ['text' => $toggleText, 'callback_data' => "dev_toggle_" . $did],
                ['text' => "✏️ Nom o'zgartirish", 'callback_data' => "dev_rename_" . $did]
            ],
            [
                ['text' => "SIM 1", 'callback_data' => "dev_sim_" . $did . "_1"],
                ['text' => "SIM 2", 'callback_data' => "dev_sim_" . $did . "_2"],
                ['text' => "Default", 'callback_data' => "dev_sim_" . $did . "_0"],
            ],
            [
                ['text' => "🔄 Statistikani tozalash", 'callback_data' => "dev_reset_" . $did],
            ],
            [
                ['text' => "🗑 O'chirish", 'callback_data' => "dev_del_" . $did],
                ['text' => "🔙 Ortga", 'callback_data' => "dev_list"]
            ]
        ];

        editMessage($chat_id, $msg_id, $msg, ['inline_keyboard' => $buttons], "HTML");
        answerCallback($cb['id'], "📱 " . $name);
        exit;
    }

    if ($data == "dev_back") {
        deleteMessage($chat_id, $msg_id);
        sendMessage($chat_id, "📱 <b>Qurilmalar</b> menyusidan chiqildi.", mainKeyboard(), "HTML");
        exit;
    }

    // ---- NIGHT MODE ----
    if ($data == "nm_toggle") {
        $nm = getNightModeConfig();
        $nm['enabled'] = !$nm['enabled'];
        setNightModeConfig($nm);
        $statusIcon = $nm['enabled'] ? "🟢 YONIQ" : "🔴 O'CHIQ";
        $msg = "🌙 <b>TUNGI REJIM</b>\n\nHolat: <b>$statusIcon</b>\nBoshlanish: <code>{$nm['start']}</code>\nTugash: <code>{$nm['end']}</code>";
        $nm_kb = ['inline_keyboard' => [
                [['text' => $nm['enabled'] ? "🔴 O'chirish" : "🟢 Yoqish", 'callback_data' => 'nm_toggle']],
                [['text' => '⏰ Vaqtni sozlash', 'callback_data' => 'nm_set_time']]
            ]];
        editMessage($chat_id, $msg_id, $msg, $nm_kb, "HTML");
        answerCallback($cb['id'], $nm['enabled'] ? "✅ Yoqildi" : "❌ O'chirildi");
        exit;
    }

    if ($data == "nm_set_time") {
        deleteMessage($chat_id, $msg_id);
        file_put_contents(__DIR__ . "/status_" . $chat_id . ".txt", "WAIT_FOR_NM_TIME");
        sendMessage($chat_id, "⏰ Vaqtni kiriting:\n\n<code>22:35-07:00</code>", ['remove_keyboard' => true], "HTML");
        answerCallback($cb['id'], "⏰ Kiriting");
        exit;
    }

    exit;
}

// ============ ODDIY XABARLAR ============
if (isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $user_id = $update['message']['from']['id'];
    $text = $update['message']['text'] ?? '';

    if (!in_array($user_id, $admin_ids)) {
        sendMessage($chat_id, "⛔ Ruxsat yo'q!");
        exit;
    }

    $status_file = __DIR__ . "/status_" . $chat_id . ".txt";
    $state = file_exists($status_file) ? trim(file_get_contents($status_file)) : "";

    // ---- FAYL YUKLASH ----
    if (isset($update['message']['document'])) {
        $file_path = getTelegramFile($update['message']['document']['file_id']);
        if (!$file_path) {
            sendMessage($chat_id, "⚠️ Faylni o'qib bo'lmadi.");
            exit;
        }

        $content = @file_get_contents($file_path);
        if ($content === false) {
            sendMessage($chat_id, "⚠️ Yuklab bo'lmadi.");
            exit;
        }

        $lines = explode("\n", $content);
        $stmt = $db->prepare("INSERT OR IGNORE INTO contacts (phone) VALUES (:p)");
        $added = 0;
        $db->beginTransaction();
        foreach ($lines as $line) {
            $phone = preg_replace('/[^0-9]/', '', trim($line));
            if (strlen($phone) >= 9) {
                $stmt->execute([':p' => formatPhone($phone)]);
                $added += $stmt->rowCount();
            }
        }
        $db->commit();
        $total = $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
        sendMessage($chat_id, "✅ <b>+$added</b> ta qo'shildi\n👥 Jami: <b>$total</b>", controlKeyboard(), "HTML");
        exit;
    }

    // ---- START ----
    if ($text == "/start" || $text == "/menu" || $text == "🔙 Asosiy Menyu") {
        if (file_exists($status_file))
            @unlink($status_file);
        $ds = getDeviceStatusText();

        $msg = "╔═══════════════════╗\n";
        $msg .= "   📱 <b>SMS SENDER BOT</b>   \n";
        $msg .= "╚═══════════════════╝\n\n";
        $msg .= "{$ds['icon']} <b>Qurilmalar:</b> {$ds['text']}\n\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        $msg .= "<i>Menyudan tanlang</i> 👇";
        sendMessage($chat_id, $msg, mainKeyboard(), "HTML");
    }

    // ---- QURILMALAR (ixcham) ----
    elseif ($text == "📱 Qurilmalar") {
        $dl = buildDeviceList();
        sendMessage($chat_id, $dl['text'], $dl['kb'], "HTML");
    }

    // ---- BOSHQARUV (eski — Holat kartasiga ko'chirildi) ----
    elseif ($text == "⚙️ Boshqaruv") {
        $dash = buildDashboard();
        sendMessage($chat_id, $dash['text'], $dash['kb'], "HTML");
    }

    elseif ($text == "⏸ Pauza") {
        setBroadcastState("paused");
        $p = $db->query("SELECT COUNT(*) FROM queue WHERE status='pending'")->fetchColumn();
        sendMessage($chat_id, "⏸ <b>To'xtatildi.</b> Navbatda: <b>$p</b> ta", controlKeyboard(), "HTML");
    }

    elseif ($text == "▶️ Davom") {
        setBroadcastState("active");
        if (file_exists($smart_break_file))
            @unlink($smart_break_file);
        @file_put_contents($streak_file, 0);
        $p = $db->query("SELECT COUNT(*) FROM queue WHERE status='pending'")->fetchColumn();
        sendMessage($chat_id, "▶️ <b>Davom etmoqda.</b> Navbatda: <b>$p</b> ta", controlKeyboard(), "HTML");
    }

    elseif ($text == "🛑 To'xtatish") {
        $p = $db->query("SELECT COUNT(*) FROM queue WHERE status IN ('pending','processing')")->fetchColumn();
        sendMessage($chat_id, "⚠️ <b>$p</b> ta SMS o'chiriladi. Tasdiqlaysizmi?", ['inline_keyboard' => [[
                    ['text' => "🛑 HA ($p ta)", 'callback_data' => "stop_confirm"],
                    ['text' => "🔙 Yo'q", 'callback_data' => "stop_cancel"]
                ]]], "HTML");
    }

    // ---- HOLAT (jonli dashboard) ----
    elseif ($text == "📊 Holat" || $text == "📡 Status") {
        $dash = buildDashboard();
        sendMessage($chat_id, $dash['text'], $dash['kb'], "HTML");
    }

    // ---- KONTAKTLAR ----
    elseif ($text == "📂 Kontaktlar") {
        $count = $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
        $msg = "📂 <b>KONTAKTLAR</b>\n\n👥 Jami: <b>$count</b> ta\n\n<i>Qo'shish uchun .txt yoki .csv fayl tashlang</i> 📎";
        sendMessage($chat_id, $msg, controlKeyboard(), "HTML");
    }

    // ---- TOZALASH ----
    elseif ($text == "🗑️ Tozalash") {
        $p = $db->query("SELECT COUNT(*) FROM queue")->fetchColumn();
        $c = $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
        sendMessage($chat_id, "🗑️ <b>TOZALASH</b>\n\n📊 Navbat: <b>$p</b> | 👥 Kontaktlar: <b>$c</b>\n\nNimani tozalash kerak?", [
            'keyboard' => [
                [['text' => "🗑️ Navbatni tozalash"], ['text' => "❌ Kontaktlarni o'chirish"]],
                [['text' => "🔙 Bekor qilish"]]
            ], 'resize_keyboard' => true
        ], "HTML");
    }
    elseif ($text == "🗑️ Navbatni tozalash") {
        $c = $db->query("SELECT COUNT(*) FROM queue")->fetchColumn();
        $db->exec("DELETE FROM queue");
        clearBatch();
        sendMessage($chat_id, "🗑️ $c ta o'chirildi!", mainKeyboard());
    }
    elseif ($text == "❌ Kontaktlarni o'chirish") {
        $c = $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
        $db->exec("DELETE FROM contacts");
        sendMessage($chat_id, "❌ $c ta kontakt o'chirildi!", controlKeyboard());
    }
    elseif ($text == "🔙 Bekor qilish") {
        if (file_exists($status_file))
            @unlink($status_file);
        sendMessage($chat_id, "Bekor qilindi.", controlKeyboard());
    }

    // ---- BROADCAST ----
    elseif ($text == "✉️ Ommaviy SMS" || $text == "✉️ Broadcast") {
        $count = $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
        if ($count == 0) {
            sendMessage($chat_id, "⚠️ Avval kontakt qo'shing!\n\n📂 Kontaktlar → .txt/.csv fayl tashlang.", mainKeyboard());
            exit;
        }
        file_put_contents($status_file, "WAIT_FOR_MSG");
        sendMessage($chat_id, "✉️ <b>Ommaviy SMS</b>\n\n👥 Qabul qiluvchilar: <b>$count</b> ta\n\n✍️ Xabar matnini yozing.\n<i>Uzun matn va emoji ham bo'ladi (uzun bo'lsa bir nechta SMS sifatida ketadi).</i>", ['remove_keyboard' => true], "HTML");
    }

    elseif ($state == "WAIT_FOR_MSG") {
        @unlink($status_file);
        $charCount = mb_strlen($text, 'UTF-8');
        if ($charCount == 0) {
            sendMessage($chat_id, "⚠️ Bo'sh xabar!", mainKeyboard());
            exit;
        }
        if ($charCount > 800) {
            sendMessage($chat_id, "⚠️ Juda uzun: <b>$charCount</b> belgi (max 800)\n" . ($charCount - 800) . " ta belgini olib tashlang.", mainKeyboard(), "HTML");
            exit;
        }

        $seg = smsSegments($text);
        file_put_contents(__DIR__ . "/pending_msg_" . $chat_id . ".txt", $text);
        $hash = substr(md5($text . time()), 0, 8);
        $preview = mb_strlen($text, 'UTF-8') > 50 ? mb_substr($text, 0, 50, 'UTF-8') . "..." : $text;

        sendMessage($chat_id, "✅ <b>Qabul qilindi!</b>\n\n📊 <b>$charCount</b> belgi • ~<b>$seg</b> ta SMS\n📝 <code>" . e($preview) . "</code>\n\n🔄 Tartibni tanlang:", ['inline_keyboard' => [
                [
                    ['text' => "🔽 Boshidan", 'callback_data' => "order_asc_$hash"],
                    ['text' => "🔼 Oxiridan", 'callback_data' => "order_desc_$hash"]
                ],
                [['text' => "🔀 Aralash", 'callback_data' => "order_random_$hash"]],
                [['text' => "❌ Bekor", 'callback_data' => "cancel_broadcast"]]
            ]], "HTML");
    }

    // ---- TEST SMS ----
    elseif ($text == "🧪 Test SMS" || $text == "Test SMS") {
        file_put_contents($status_file, "WAIT_FOR_TEST_SMS");
        sendMessage($chat_id, "🧪 <b>TEST SMS</b>\n\nFormat:\n<code>+998901234567 Salom 😀 dunyo</code>\n\n<i>⚡ Navbatsiz, darhol yuboriladi. Emoji ham sinab ko'ring.</i>", ['remove_keyboard' => true], "HTML");
    }

    elseif ($state == "WAIT_FOR_TEST_SMS") {
        $parts = explode(" ", $text, 2);
        if (count($parts) < 2) {
            sendMessage($chat_id, "⚠️ Format: <code>+998xx Matn</code>", ['remove_keyboard' => true], "HTML");
            exit;
        }
        $phone = formatPhone(trim($parts[0]));
        $message = trim($parts[1]);
        if (strlen($phone) < 7 || empty($message)) {
            sendMessage($chat_id, "⚠️ Noto'g'ri ma'lumot!", ['remove_keyboard' => true]);
            exit;
        }
        $db->prepare("INSERT INTO queue (phone, message, status) VALUES (?,?,'test_pending')")->execute([$phone, $message]);
        @unlink($status_file);
        sendMessage($chat_id, "✅ Test SMS navbatga qo'shildi\n📞 <code>" . e($phone) . "</code>\n📝 <code>" . e($message) . "</code>", controlKeyboard(), "HTML");
    }

    // ---- TUNGI REJIM ----
    elseif ($text == "🌙 Tungi rejim") {
        $nm = getNightModeConfig();
        $s = $nm['enabled'] ? "🟢 YONIQ" : "🔴 O'CHIQ";
        sendMessage($chat_id, "🌙 <b>TUNGI REJIM</b>\n\nHolat: <b>$s</b>\nVaqt: <code>{$nm['start']}</code> — <code>{$nm['end']}</code>", ['inline_keyboard' => [
                [['text' => $nm['enabled'] ? "🔴 O'chirish" : "🟢 Yoqish", 'callback_data' => 'nm_toggle']],
                [['text' => '⏰ Vaqtni sozlash', 'callback_data' => 'nm_set_time']]
            ]], "HTML");
    }

    elseif ($state == "WAIT_FOR_NM_TIME") {
        if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $text, $m)) {
            if ($m[1] <= 23 && $m[2] <= 59 && $m[3] <= 23 && $m[4] <= 59) {
                $nm = getNightModeConfig();
                $nm['start'] = sprintf("%02d:%02d", $m[1], $m[2]);
                $nm['end'] = sprintf("%02d:%02d", $m[3], $m[4]);
                setNightModeConfig($nm);
                @unlink($status_file);
                sendMessage($chat_id, "✅ Saqlandi: <code>{$nm['start']}</code> — <code>{$nm['end']}</code>", controlKeyboard(), "HTML");
                exit;
            }
        }
        sendMessage($chat_id, "⚠️ Format: <code>22:35-07:00</code>", ['remove_keyboard' => true], "HTML");
    }

    // ---- QURILMA NOM O'ZGARTIRISH ----
    elseif (strpos($state, "RENAME_DEVICE:") === 0) {
        $did = str_replace("RENAME_DEVICE:", "", $state);
        $newName = trim($text);
        if (empty($newName)) {
            sendMessage($chat_id, "⚠️ Nom bo'sh bo'lishi mumkin emas!");
            exit;
        }
        $db->prepare("UPDATE devices SET name=? WHERE device_id=?")->execute([$newName, $did]);
        @unlink($status_file);
        sendMessage($chat_id, "✅ Qurilma nomi o'zgartirildi: <b>" . e($newName) . "</b>", mainKeyboard(), "HTML");
    }
}
?>