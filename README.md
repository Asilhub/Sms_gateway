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

Barcha so'rovlar `?key=<API_KEY>` talab qiladi.

| Action | Misol | Tavsif |
|---|---|---|
| `send` | `?action=send&key=KEY&phone=+998...&text=Salom` | Navbatga SMS qo'shadi, `id` qaytaradi |
| `check_status` | `?action=check_status&key=KEY&id=123` | SMS holatini tekshiradi |
| `stats` | `?action=stats&key=KEY` | Navbat va onlayn qurilmalar statistikasi |

### Qurilma API (ilova ishlatadi)
`heartbeat`, `get_task`, `update`, `incoming_sms`, `incoming_call`, `error`.

## Telegram bot
Admin menyusi: **Broadcast** (ommaviy yuborish), **Status**, **Qurilmalar**
(boshqarish, SIM tanlash, nom), **Boshqaruv** (pauza/davom/to'xtatish, test SMS,
kontaktlar, tungi rejim). Kontaktlar `.txt`/`.csv` fayl tashlab qo'shiladi.

## Holat fayllari (runtime, git'da yo'q)
`sms.db`, `broadcast_state.txt`, `broadcast_config.json`, `night_mode.json`,
`smart_break.txt`, `error_streak.txt`, `pending_msg_*.txt`, `status_*.txt`.

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
- 160-belgi cheklovi kirilcha matn uchun noto'g'ri (UCS-2 → 70 belgi) — server tomonida.
- Barcha telefonlar v0.1.0 ga o'tgach, `config.php` `api_keys` dan eski `1206` ni olib tashlash.
