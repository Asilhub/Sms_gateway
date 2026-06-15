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

    // Qurilma/CRM API kalitlari (massiv). Birinchisi — asosiy.
    // Bir nechta kalit qo'llab-quvvatlanadi (kalit almashtirish/migratsiya uchun).
    // UZUN va TASODIFIY qiling, masalan: bin2hex(random_bytes(24)).
    'api_keys'  => [
        'BU_YERGA_UZUN_TASODIFIY_KALIT',
    ],
];
