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
- **Deploy**: `./deploy.sh` (webhook.php ni FTP orqali serverga yuklaydi). FTP login/parol
  `.deploy.env` da (gitignore'langan). Host `sms.idrokedu.uz`, yo'l `/www/sms.idrokedu.uz/`.
  Skript `config.php`/`sms.db` ni HECH QACHON yubormaydi. `./deploy.sh --apk` — APK yuklaydi.

## v0.6.0 (server, deploy 2026-06-23) — joriy. CRM API + FAILOVER
**Faqat `webhook.php`.** O'quv markaz CRM integratsiyasi (o'quvchi ma'lumotlari, OTP/tasdiqlash kodlari).
1. ✅ **Avtomatik FAILOVER**: bitta qurilma SMS yubora olmasa (`update?status=failed`) — endi
   darhol `failed` bo'lmaydi. SMS boshqa onlayn qurilmaga qayta navbatga qo'yiladi; xato
   qaytargan qurilma o'sha SMS'ni qayta olmaydi. Barcha onlayn qurilma urinib ham bo'lmasa
   (yoki `MAX_SEND_ATTEMPTS`=6) → shundagina `failed` + admin'ga ogohlantirish (OTP uchun).
   - Yangi `queue` ustunlari: `attempts`, `failed_devices` (',dev1,dev2,'), `is_priority`
     (1=OTP/CRM→test_pending, 0=ommaviy→pending). Migratsiya avtomatik (PRAGMA tekshiruv).
   - `get_task` selektlari `failed_devices NOT LIKE` bilan xato qurilmani chetlab o'tadi.
   - `recoverStuckTasks` endi is_priority'ga qarab tiklaydi (OTP'ni broadcast'ga tushirmaydi).
   - Smart-break faqat YAKUNIY xatolarni sanaydi (har retry'ni emas).
2. ✅ **CRM API kuchaytirildi** (eski maydonlar saqlanib, yangilari qo'shildi):
   - `send` → `online_devices`, `deliverable` (qurilma onlaynmi), `segments` (necha SMS).
   - `check_status` → `delivered`/`pending`/`failed` bool bayroqlar.
   - `stats` → `ready` (kamida 1 qurilma onlayn), `queue.otp_pending`/`processing`,
     `oldest_pending_age_s` (tiqilish), `night_mode`/`smart_break` bayroqlar.

## v0.0.2 da tuzatilgan (app)
1. ✅ SMS real yetkazildi: `sendSms` endi PendingIntent (SENT) natijasiga qarab true/false.
2. ✅ `ApiClient.call` to'liq URL-encode qiladi (map asosida).
3. ✅ SIM tanlash: `get_task` javobidagi `sim_slot` (optInt) ga qarab SubscriptionManager.
4. ✅ Ketma-ket 5+ xato → `haltSimExhausted()` (to'xtaydi, admin'ga xabar).

## v0.2.0 (app + server, deploy qilingan) — joriy
- App: force-update endi APK'ni DownloadManager bilan yuklab, FileProvider orqali o'zi o'rnatadi
  (`MainActivity.startAppUpdate/installUpdate`). Manifest: REQUEST_INSTALL_PACKAGES + FileProvider
  (`res/xml/file_paths.xml`).
- App→server: heartbeat'da `app`=versionCode yuboradi. Server `devices.app_version` saqlaydi,
  bot qurilma sahifasida "📦 Ilova: yangi/eski (code N)" ko'rsatadi. latest_code=4.
- Yangi versiya chiqarganda: build.gradle versionCode++, webhook.php `version` endpoint
  latest_code/name yangilash, APK ni serverga (SmsGateway.apk) yuklash.

## Xavfsizlik: heartbeat endi kalit talab qiladi (server, deploy 2026-06-16) — joriy
- `webhook.php` device API: faqat `version` kalitsiz. Avval `heartbeat` ham kalitsiz edi —
  shuning uchun o'chirilgan kalitli eski telefon `get_task` qilolmasa ham heartbeat
  yuborib "onlayn" ko'rinardi. Endi `heartbeat` ham `keyValid()` talab qiladi → eski/yaroqsiz
  kalitli qurilma umuman ulanolmaydi (401) va ro'yxatga tushmaydi.
- `stats` API'ga `?devices=1` qo'shildi (diagnostika: qurilma id/online/last_seen/app).

## v0.5.0 (app + server, deploy 2026-06-16) — joriy. latest_code=7
1. ✅ **Takror qurilma muammosi**: device_id endi BARQAROR — `ANDROID_ID` hash'idan
   (`generateDeviceId()` MainActivity + `SmsWorkerService.loadDeviceId()`). Qayta o'rnatishda
   ham bir xil ID → botda dubl chiqmaydi. Mavjud prefs ID saqlanadi (churn yo'q).
2. ✅ **Bot: oflayn qurilmalarni tozalash** — qurilmalar ro'yxatida "🧹 Oflaynlarni o'chirish (N)"
   tugmasi (`dev_clean_offline`, last_seen<90s o'chiradi). Onlayn telefon teginilmaydi.
3. ✅ **Bot: "Teng bo'lib" tushuntirildi** — `devtarget` va broadcast qurilma-tanlash ekranlarida
   izoh ("har kontakt 1 marta oladi, bu 10 raqamdan yuborish EMAS"). Round-robin/target faqat
   2+ qurilma bo'lsa ko'rsatiladi (1 telefonda yashiriladi).

## Bot UI yangilanishi (server, deploy qilingan 2026-06-16)
Telegram bot UX qayta ishlandi (faqat `webhook.php`):
1. ✅ **Jonli HOLAT dashboard** (`buildDashboard()`): progress-bar (█░ %), holat, onlayn qurilmalar,
   joriy partiya ✅/❌/⏳. Inline tugmalar: ⏸ Pauza / ▶️ Davom / 🔄 Yangilash / 🛑 To'xtatish
   (`st_pause/st_resume/st_refresh/st_stop`). Pauza/Stop endi DOIMIY klaviaturada emas — shu kartada.
2. ✅ **Partiya progressi**: broadcast confirm'da `setBatch(startId, total)` → `broadcast_batch.json`;
   dashboard `id>=start_id` bo'yicha sent/failed sanaydi. `clearBatch()` stop/tozalashda.
3. ✅ **Ixcham qurilmalar** (`buildDeviceList()`): uzun matn bloklari o'rniga xulosa-sarlavha +
   har qurilmaga 1 ta tugma. Broadcast qurilma tanlash alohida `devtarget` ekraniga ko'chirildi.
4. ✅ **Yangi asosiy menyu** (`mainKeyboard`): ✉️ Ommaviy SMS / 📊 Holat · 🧪 Test SMS /
   📱 Qurilmalar · 📂 Kontaktlar / 🌙 Tungi rejim · 🗑️ Tozalash. `controlKeyboard()`=mainKeyboard().
5. ✅ Broadcast boshlangach darhol HOLAT kartasi ko'rsatiladi (jarayonni shu yerdan kuzatish).

## v0.4.0 (app + server, deploy qilingan 2026-06-16)
**UI/dizayn yangilanishi.** latest_code=6.
1. ✅ **Launcher icon tuzatildi** (avval hamma telefonda oppoq edi — manifest `android:icon` to'g'ridan
   to'g'ri `ic_launcher_foreground` (orqa fonsiz oq vektor) ga ishora qilardi). Endi to'liq adaptive
   icon: `mipmap-anydpi-v26/ic_launcher(_round).xml` (background gradient + foreground chat-pufakcha +
   monochrome), API24/25 uchun `mipmap/ic_launcher*.xml` → `drawable/ic_launcher_legacy.xml` (composed
   vektor). Manifest: `@mipmap/ic_launcher` + `roundIcon`.
2. ✅ **Yangi UI** (`activity_main.xml`): gradient header (logo+sarlavha+qurilma+versiya+⚙️),
   status kartasi, Yuborildi/Xato statistika kartalari (ic_check/ic_error), ic_play/ic_stop bilan
   tugma, yumaloq terminal-jurnal. Yangi palitra `colors.xml`, light tema `themes.xml`.
3. ✅ **Sozlamalar menyusi** (⚙️ header tugmasi → `showSettingsMenu()`): API kalit, yangilanish
   tekshirish, batareya optimizatsiyasi, bildirishnoma sozlamalari, qurilma ID nusxalash,
   jurnal tozalash, ilova haqida.
4. Material vektor ikonkalar: `ic_sms, ic_play, ic_stop, ic_settings, ic_check, ic_error`.

## v0.3.0 (app + server, deploy qilingan 2026-06-15)
1. ✅ **Emoji tuzatildi**: `get_task` endi `json_encode($task, JSON_UNESCAPED_UNICODE)` +
   header `application/json; charset=utf-8`. Emoji (4-baytli UTF-8) surrogat-juft escape'siz
   xom UTF-8 sifatida ketadi — ilovada buzilmaydi. (Ildiz sabab: kirilcha ishlardi, emoji yo'q.)
2. ✅ **160 limit → 800**: Broadcast `WAIT_FOR_MSG` da limit 800 belgiga ko'tarildi; `smsSegments()`
   helper GSM-7/UCS-2 ga qarab necha SMS ketishini ko'rsatadi (uzun matn = multipart).
3. ✅ **Eski kalit o'chirildi**: server `config.php` `api_keys` da faqat asosiy kalit qoldi. Eski
   kalit endi 403. (Eski/v0.0.1 telefonlar ishlamay qoladi — qo'lda v0.3.0 o'rnatish kerak.)
4. ✅ App: qurilma satrida `v0.3.0`, bosib qo'lda yangilanish tekshirish. latest_code=5.

## v0.1.0 (app + server, deploy qilingan)
1. ✅ API kalit APK ichida YO'Q — `ApiClient.apiKey` prefs'dan, foydalanuvchi kiritadi
   (MainActivity `showKeyDialog`). Server URL faqat BuildConfig'da.
2. ✅ Majburiy yangilanish: app `action=version` ni tekshiradi → `showUpdateDialog`.
   Server `version` endpoint `latest_code/url/force` qaytaradi (kalitsiz). APK serverda:
   `https://sms.idrokedu.uz/SmsGateway.apk`. Yangi versiyada `latest_code` ni oshirish kerak.
3. ✅ Server: get_task `sim_slot`, ko'p kalit (`api_keys`), `.htaccess` (db-wal/config.php) — deploy qilingan.

## Hal qilinmagan (kelajakdagi ish)
1. Eski (v0.0.1 / eski kalitdagi) telefonlar endi ishlamaydi — bir marta qo'lda v0.3.0 o'rnatish kerak.
   v0.1.0+ telefonlar majburiy-yangilanish orqali o'zi o'tadi (force-update).
