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

## Hal qilinmagan kamchiliklar (kelajakdagi ish)
1. SMS real yetkazilganini tekshirish yo'q — `SmsWorkerService.sendSms` `PendingIntent`siz
   `Thread.sleep(3000)` qilib har doim `true` qaytaradi.
2. `ApiClient.call` URL-encoding qilmaydi — `msg`/`phone` maxsus belgilarda buziladi.
3. SIM tanlash yarim: bazada `sim_slot` bor, ilova o'qimaydi.
4. 160-belgi cheklovi kirilcha (UCS-2, 70 belgi) uchun noto'g'ri.
5. `.htaccess` `sms.db-wal` ni bloklamaydi (kengaytma `.db` bilan tugamaydi).
