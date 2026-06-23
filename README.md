# SMS Gateway

Android telefonni SMS shlyuziga (gateway) aylantiruvchi tizim. Telegram bot orqali
boshqariladi, tashqi CRM tizimlaridan API orqali SMS yuborish imkonini beradi.

> **Eslatma:** Faqat o'zingizning roziligi bor kontaktlaringizga (mijozlar, o'quvchilar
> va h.k.) xabar yuborish uchun ishlating. Spam va ruxsatsiz tarqatish ko'p davlatlarda
> qonunga zid.

## Arxitektura

```
┌─────────────┐      HTTPS       ┌──────────────────┐      SMS      ┌──────────┐
│  Telegram   │ ───────────────▶ │   webhook.php    │ ◀──polling─── │ Android  │
│  admin bot  │ ◀─────────────── │  (PHP + SQLite)  │ ──tasks────▶  │   ilova  │ ──▶ 📱
└─────────────┘                  └──────────────────┘               └──────────┘
                                          ▲
                                          │ HTTPS API (key)
                                  ┌───────┴────────┐
                                  │   CRM / tashqi  │
                                  │     tizim       │
                                  └────────────────┘
```

- **Android ilova** (`sms-gateway-app/`) — serverni har necha soniyada so'roq qiladi
  (poll), navbatdagi SMS vazifasini oladi va telefon SIM-kartasi orqali yuboradi.
  Foreground service sifatida ishlaydi, qurilma yonganda avtomatik tiklanadi.
- **Backend** (`webhook.php`) — bitta PHP fayl: Telegram bot + qurilma API + CRM API.
  Ma'lumotlar SQLite (`sms.db`) da saqlanadi.

## Tarkibiy qismlar

| Fayl/papka | Vazifasi |
|---|---|
| `webhook.php` | Backend: Telegram bot, qurilma API, CRM API |
| `config.php` | Maxfiy kalitlar (git'ga **kirmaydi**) |
| `config.example.php` | Sozlama namunasi |
| `.htaccess` | `.db`/`.txt`/`.json` fayllarga to'g'ridan-to'g'ri kirishni bloklaydi |
| `sms-gateway-app/` | Android ilova (Kotlin) |
| `SmsGateway.apk` | Tayyor o'rnatish fayli (git'da saqlanmaydi) |

### Android ilova ichki tuzilishi
- `MainActivity.kt` — UI, ruxsatlar, qurilma ID, workerni boshqarish
- `SmsWorkerService.kt` — fon xizmati: poll → SMS yuborish → status qaytarish
- `ApiClient.kt` — serverga HTTP so'rovlar
- `IncomingSmsReceiver.kt` — kiruvchi SMS larni serverga xabar qiladi
- `BootReceiver.kt` — qurilma yonganda xizmatni qayta ishga tushiradi

## O'rnatish

### 1. Backend (server)
1. `webhook.php`, `config.example.php`, `.htaccess` ni serverga yuklang (HTTPS shart).
2. Sozlama faylini yarating:
   ```bash
   cp config.example.php config.php
   ```
   `config.php` ichida `bot_token`, `admin_ids`, `api_key` ni to'ldiring.
3. Telegram webhook ni o'rnating:
   ```
   https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://sms.idrokedu.uz/webhook.php
   ```
4. SQLite jadvallari birinchi so'rovda avtomatik yaratiladi.

### 2. Android ilova
1. `sms-gateway-app/` ni Android Studio'da oching.
2. Server manzilini sozlang:
   ```bash
   cd sms-gateway-app
   cp keys.properties.example keys.properties
   ```
   `keys.properties` da faqat `SERVER` bo'ladi (maxfiy emas). So'ng: `./gradlew assembleDebug`
3. **API kalit APK ichida saqlanmaydi** — foydalanuvchi ilovani birinchi ochganda
   qo'lda kiritadi (server `config.php` dagi `api_keys` dan biri). Kalitni keyin
   o'zgartirish: "Qurilma: ..." matnini uzoq bosing.
3. APK ni telefonga o'rnating, SMS ruxsatlarini bering, batareya optimizatsiyasini o'chiring.
4. Ilova ishga tushgach, qurilma serverda avtomatik ro'yxatga olinadi.

## API (CRM integratsiyasi)

Base URL: `https://sms.idrokedu.uz/webhook.php` · barcha so'rovlar `?key=<API_KEY>` talab qiladi (aks holda `403`).
O'quv markaz CRM'i o'quvchilarga xabar va **tasdiqlash kodlari (OTP)** yuborish uchun ishlatadi.

### 1. `send` — SMS/OTP yuborish
`?action=send&key=KEY&phone=998901234567&text=Kod:%201234`
```json
{ "status":"ok", "id":143, "phone":"+998901234567",
  "segments":1, "online_devices":2, "deliverable":true }
```
`deliverable:false` → hech qurilma onlayn emas, SMS qurilma ulanmaguncha navbatda turadi.

### 2. `check_status` — yetkazildimi
`?action=check_status&key=KEY&id=143`
```json
{ "status":"ok", "sms_status":"sent",
  "delivered":true, "pending":false, "failed":false, "updated_at":"..." }
```
`pending:true` = navbatda/urinilmoqda (failover davom etyapti) · `failed:true` = barcha qurilma urinib bo'lmadi.

### 3. `stats` — tizim sog'ligi
`?action=stats&key=KEY` (`&devices=1` — har qurilma batafsil)
```json
{ "status":"ok", "ready":true, "online_devices":2,
  "queue":{ "pending":0, "otp_pending":0, "processing":0, "sent":1240, "failed":3 },
  "oldest_pending_age_s":0, "night_mode":false, "smart_break":false }
```
`ready` = kamida 1 qurilma onlayn (yuborish mumkin) · `oldest_pending_age_s` katta bo'lsa = tiqilish.

### FAILOVER (avtomatik)
Qurilma SMS yubora olmasa, tizim uni **boshqa onlayn qurilmaga** o'tkazadi (xato qurilma o'sha SMS'ni
qayta olmaydi). **Faqat barcha qurilma urinib bo'lmaganda** `failed` bo'ladi (OTP uchun admin'ga ogohlantirish).

### Qurilma API (ilova ishlatadi)
`heartbeat`, `get_task`, `update`, `incoming_sms`, `incoming_call`, `error`, `version`.

## Telegram bot
Asosiy menyu: **✉️ Ommaviy SMS** (broadcast), **📊 Holat**, **🧪 Test SMS**,
**📱 Qurilmalar**, **📂 Kontaktlar**, **🌙 Tungi rejim**, **🗑️ Tozalash**.

- **📊 Holat** — jonli dashboard: progress-bar (sent/failed/qoldi), onlayn qurilmalar va
  inline tugmalar (⏸ Pauza / ▶️ Davom / 🔄 Yangilash / 🛑 To'xtatish). Broadcast boshlangach
  shu karta darhol ko'rsatiladi va 🔄 bilan yangilab borasiz.
- **📱 Qurilmalar** — ixcham ro'yxat (har qurilma 1 ta tugma), "🧹 Oflaynlarni o'chirish"
  (eski/takror qurilmalar). Qurilmani tanlab: SIM, nom, yoqish/o'chirish, statistika.
  Yuborish qurilmasini tanlash (Hammasi / Teng bo'lib / bitta) faqat 2+ qurilmada chiqadi.
- Kontaktlar `.txt`/`.csv` fayl tashlab qo'shiladi.

## Holat fayllari (runtime, git'da yo'q)
`sms.db`, `broadcast_state.txt`, `broadcast_config.json`, `broadcast_batch.json`,
`night_mode.json`, `smart_break.txt`, `error_streak.txt`, `pending_msg_*.txt`, `status_*.txt`.

## O'zgarishlar (v0.6.0) — server
- ✅ **Avtomatik FAILOVER** — bitta qurilma yubora olmasa, SMS boshqa onlayn qurilmaga o'tadi;
  faqat hammasi urinib bo'lmaganda `failed`. OTP'lar `recoverStuckTasks`'da broadcast'ga tushmaydi.
- ✅ **CRM API kuchaytirildi** — `send`: `deliverable`/`online_devices`/`segments`;
  `check_status`: `delivered`/`pending`/`failed`; `stats`: `ready`/`otp_pending`/`oldest_pending_age_s`/`night_mode`/`smart_break`.
- ✅ **`deploy.sh`** — FTP orqali bir buyruq bilan deploy (login/parol `.deploy.env`, git'da yo'q).

## O'zgarishlar (v0.5.0)
- ✅ **Barqaror qurilma ID** — `ANDROID_ID` asosida; qayta o'rnatishda ham bir xil, botda
  takror qurilma chiqmaydi. Mavjud ID lar saqlanadi.
- ✅ **Jonli HOLAT dashboard** — progress-bar + inline ⏸/▶️/🔄/🛑; broadcast boshlangach darhol chiqadi.
- ✅ **Ixcham qurilmalar** ro'yxati + "🧹 Oflaynlarni o'chirish"; "Teng bo'lib" izohi, target faqat 2+ qurilmada.
- ✅ Soddalashtirilgan asosiy menyu (pauza/stop endi Holat kartasida, doimiy klaviaturada emas).

## O'zgarishlar (v0.4.0)
- ✅ **Launcher icon tuzatildi** — avval hamma telefonda oppoq edi; endi to'liq adaptive icon
  (gradient fon + chat-pufakcha), eski Android uchun fallback.
- ✅ **Yangi UI** — gradient header, status kartasi, Yuborildi/Xato statistika kartalari,
  play/stop ikonkali tugma, yumaloq terminal-jurnal.
- ✅ **Sozlamalar menyusi** (⚙️) — kalit, yangilanish, batareya, bildirishnoma, qurilma ID
  nusxalash, jurnal tozalash, ilova haqida. Material vektor ikonkalar.

## O'zgarishlar (v0.3.0)
- ✅ **Emoji tuzatildi** — emoji-li SMS to'g'ri yetkaziladi (server `JSON_UNESCAPED_UNICODE` + `charset=utf-8`).
- ✅ **160 → 800 belgi** — uzun matn bir nechta SMS (multipart) sifatida ketadi; bot SMS sonini ko'rsatadi.
- ✅ **Eski vaqtinchalik kalit olib tashlandi** — endi faqat asosiy API kalit ishlaydi.
- ✅ Ilova: ekranda versiya, qo'lda yangilanish tekshirish.

## O'zgarishlar (v0.2.0)
- ✅ **To'liq avtomatik yangilanish** — "Yangilash" bosilganda ilova APK'ni o'zi yuklab,
  o'zi o'rnatadi (DownloadManager + FileProvider). Brauzer fallback bor.
- ✅ **Versiya hisoboti** — har telefon heartbeat'da `app` (versionCode) yuboradi; bot
  qurilma sahifasida "📦 Ilova: yangi/eski" ko'rsatadi (kim yangilangan-yo'qligini bilish uchun).

## O'zgarishlar (v0.1.0)
- ✅ **API kalit APK ichida emas** — foydalanuvchi qo'lda kiritadi (decompile qilsa ham sir yo'q).
- ✅ **Majburiy yangilanish** — ilova `action=version` ni tekshiradi; eski bo'lsa yopiq dialog
  ko'rsatib, yangi APK'ni (serverdan) yuklashga undaydi.
- ✅ Server `api_keys` massiv — bir nechta kalit (migratsiya/kalit almashtirish uchun).

## O'zgarishlar (v0.0.2)
- ✅ SMS endi `PendingIntent` (SENT) orqali **haqiqatda** tekshiriladi — "yolg'on sent" tugadi.
- ✅ `ApiClient` da to'liq URL-encoding — maxsus belgili matn/raqamlar buzilmaydi.
- ✅ SIM tanlash: ilova `get_task` javobidagi `sim_slot` ga qarab to'g'ri SIM'dan yuboradi
  (server endi `sim_slot` ni yuboradi).
- ✅ Ketma-ket 5+ xatoda qurilma to'xtaydi ("SIM paketi tugagan bo'lishi mumkin") va behuda urinmaydi.

## Qolgan ishlar (TODO)
- Eski (v0.0.1) telefonlar majburiy-yangilanish kodiga ega emas — bir marta qo'lda v0.5.0
  o'rnatish kerak; keyin force-update orqali o'zi yangilanadi.
