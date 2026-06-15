# CLAUDE.md

Bu fayl kelajakdagi Claude Code sessiyalari uchun loyiha haqida qisqa ma'lumot.

## Loyiha
Android telefonni SMS shlyuziga aylantiruvchi tizim. Telegram bot orqali boshqariladi.
Ta'lim kompaniyasi (idrokedu.uz) o'z kontaktlariga ommaviy SMS yuborish uchun ishlatadi.

## Tuzilma
- **`webhook.php`** — yagona PHP backend (~1380 qator): Telegram bot + qurilma API + CRM API.
  Ma'lumotlar SQLite `sms.db` da. Holat qisman fayllarda (`*.txt`, `*.json`).
- **`config.php`** — maxfiy kalitlar (`bot_token`, `admin_ids`, `api_key`). Git'ga kirmaydi.
  `webhook.php` boshida `require` qilinadi. Namuna: `config.example.php`.
- **`sms-gateway-app/`** — Android ilova (Kotlin), `applicationId = uz.idrokedu.smsgateway`.
  - `SmsWorkerService.kt` — fon xizmati, poll qiladi (`get_task`), SMS yuboradi, status qaytaradi.
  - `ApiClient.kt` — HTTP so'rovlar. `SERVER` va `API_KEY` shu yerda qattiq yozilgan.
  - `MainActivity.kt`, `IncomingSmsReceiver.kt`, `BootReceiver.kt`.

## Muhim eslatmalar
- **Git tarixiga `config.php`, `sms.db`, APK kirmasligi kerak** — `.gitignore` da bor.
- Ilovadagi `API_KEY`/`SERVER` server `config.php` dagi qiymatlarga mos bo'lishi shart.
- Bu **dual-use** vosita: faqat qonuniy, rozilik bilan yuborish uchun yordam ber.
  Spam, ruxsatsiz ommaviy tarqatish, aldovga oid o'zgartirishlarga yordam berma.

## Build / ishga tushirish
- Android: `cd sms-gateway-app && ./gradlew assembleDebug`
- Backend: PHP serverga `webhook.php` + `config.php` qo'yiladi; jadvallar avtomatik yaratiladi.

## v0.0.2 da tuzatilgan (app)
1. ✅ SMS real yetkazildi: `sendSms` endi PendingIntent (SENT) natijasiga qarab true/false.
2. ✅ `ApiClient.call` to'liq URL-encode qiladi (map asosida).
3. ✅ SIM tanlash: `get_task` javobidagi `sim_slot` (optInt) ga qarab SubscriptionManager.
4. ✅ Ketma-ket 5+ xato → `haltSimExhausted()` (to'xtaydi, admin'ga xabar).

## Hal qilinmagan (kelajakdagi ish)
1. **Server `get_task` javobiga `sim_slot` qo'shish** kerak — aks holda ilova doim default SIM.
   (FTP olgach server tomonda qo'shiladi.)
2. 160-belgi cheklovi kirilcha (UCS-2, 70 belgi) uchun noto'g'ri — server tomonida.
3. `.htaccess` `sms.db-wal` ni bloklamaydi (kengaytma `.db` bilan tugamaydi).
4. API kalit rotatsiyasi (server config.php + CRM) — [[sms-gateway-github-setup]] ga qarang.
