<?php
// ================================================
//   SOZLAMALAR NAMUNASI
//   Bu faylni config.php deb nusxalang va o'z qiymatlaringizni kiriting:
//     cp config.example.php config.php
//   config.php git'ga kirmaydi (.gitignore da).
// ================================================

return [
    // @BotFather dan olingan Telegram bot tokeni
    'bot_token' => 'BOT_TOKEN_SHU_YERGA',

    // Botni boshqara oladigan admin Telegram ID lari (@userinfobot dan oling)
    'admin_ids' => [111111111, 222222222],

    // Qurilma/CRM API uchun maxfiy kalit — UZUN va TASODIFIY qiling!
    // Masalan: bin2hex(random_bytes(16)) natijasini ishlatishingiz mumkin.
    'api_key'   => 'BU_YERGA_UZUN_TASODIFIY_KALIT',
];
