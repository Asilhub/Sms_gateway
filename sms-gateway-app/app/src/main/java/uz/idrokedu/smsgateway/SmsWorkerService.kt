package uz.idrokedu.smsgateway

import android.app.*
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import android.telephony.SmsManager
import android.telephony.SubscriptionManager
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.*
import org.json.JSONObject
import kotlin.coroutines.resume

class SmsWorkerService : Service() {

    companion object {
        const val TAG = "SmsWorker"
        const val CHANNEL_ID = "sms_gateway_channel"
        const val NOTIFICATION_ID = 1
        const val ACTION_STOP = "uz.idrokedu.smsgateway.STOP"

        // Bir qurilma ketma-ket shuncha xato qilsa — SIM paketi tugagan deb hisoblab to'xtaydi
        const val MAX_CONSECUTIVE_FAILS = 5

        var isRunning = false
        var sentCount = 0
        var failCount = 0
        var lastLog = ""
        var onLogUpdate: ((String) -> Unit)? = null
    }

    private var job: Job? = null
    private var wakeLock: PowerManager.WakeLock? = null
    private lateinit var deviceId: String
    private var consecutiveFails = 0

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        deviceId = loadDeviceId()
        ApiClient.init(this)
        createNotificationChannel()
        val wakePm = getSystemService(Context.POWER_SERVICE) as PowerManager
        wakeLock = wakePm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "SmsGateway::Worker")
        wakeLock?.acquire()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }

        startForeground(NOTIFICATION_ID, buildNotification("Ishlamoqda..."))
        isRunning = true
        consecutiveFails = 0

        job = CoroutineScope(Dispatchers.IO).launch {
            log("✅ Worker ishga tushdi")
            while (isActive) {
                try {
                    processLoop()
                } catch (e: CancellationException) {
                    break
                } catch (e: Exception) {
                    log("❌ Xatolik: ${e.message}")
                    ApiClient.reportError(e.message ?: "Unknown", deviceId)
                    delay(30_000)
                }
            }
        }

        return START_STICKY
    }

    private suspend fun processLoop() {
        // API kalit kiritilmagan bo'lsa — ishlamaymiz
        if (!ApiClient.hasKey()) {
            log("🔑 API kalit kiritilmagan")
            updateNotification("API kalit kiriting — ilovani oching")
            delay(60_000)
            return
        }

        // Heartbeat
        if (!ApiClient.heartbeat(deviceId)) {
            log("🔴 Server bilan aloqa yo'q")
            updateNotification("Server bilan aloqa yo'q")
            delay(30_000)
            return
        }

        // Task olish
        val response = ApiClient.getTask(deviceId)
        if (response == null) {
            delay(20_000)
            return
        }

        val status = response.optString("status", "")

        if (status == "empty") {
            val reason = response.optString("reason", "")
            val (msg, waitTime) = when (reason) {
                "paused" -> "⏸ Pauza" to 30_000L
                "night_mode" -> "🌙 Tungi rejim" to 300_000L
                "smart_break" -> "😴 Smart break" to 300_000L
                "device_disabled" -> "🔴 Qurilma o'chirilgan" to 60_000L
                "other_device" -> "📱 Boshqa qurilma uchun" to 30_000L
                else -> "📭 Navbat bo'sh" to 15_000L
            }
            log(msg)
            updateNotification(msg)
            delay(waitTime)
            return
        }

        // SMS ma'lumotlarini olish
        val id = response.optString("id", "")
        val phone = response.optString("phone", "")
        val message = response.optString("message", "")
        // Server qurilma uchun SIM slotini bersa ishlatamiz (0 = default)
        val simSlot = response.optInt("sim_slot", 0)

        if (id.isEmpty() || phone.isEmpty() || message.isEmpty()) {
            delay(5_000)
            return
        }

        log("📤 → $phone (ID:$id)")
        updateNotification("📤 Yuborilmoqda: $phone")

        // SMS yuborish — natijani PendingIntent orqali haqiqatda tekshiramiz
        val sent = sendSms(phone, message, simSlot)

        if (sent) {
            ApiClient.updateTask(id, "sent", deviceId)
            sentCount++
            consecutiveFails = 0
            log("✅ $phone (ID:$id)")
            updateNotification("✅ Yuborildi: $phone | Jami: $sentCount")
        } else {
            ApiClient.updateTask(id, "failed", deviceId)
            failCount++
            consecutiveFails++
            log("❌ $phone (ID:$id) [${consecutiveFails}/$MAX_CONSECUTIVE_FAILS]")

            if (consecutiveFails >= MAX_CONSECUTIVE_FAILS) {
                haltSimExhausted()
                return
            }
        }

        delay(5_000)
    }

    /**
     * Ketma-ket MAX_CONSECUTIVE_FAILS ta xatodan keyin: SIM paketi tugagan deb
     * hisoblab, behuda urinmasdan to'xtaydi. Admin'ga xabar yuboriladi.
     */
    private fun haltSimExhausted() {
        val msg = "⚠️ SIM paketi tugagan bo'lishi mumkin: ketma-ket $MAX_CONSECUTIVE_FAILS xato. Yuborish to'xtatildi."
        log("🛑 $msg")
        ApiClient.reportError(msg, deviceId)

        // Doimiy (ongoing emas) bildirishnoma qoldiramiz
        val notif = NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("SMS Gateway — to'xtatildi")
            .setContentText("SIM paketi tugagan bo'lishi mumkin. Qayta boshlash uchun ilovani oching.")
            .setStyle(NotificationCompat.BigTextStyle().bigText(
                "Ketma-ket $MAX_CONSECUTIVE_FAILS ta SMS yuborilmadi. SIM paketingiz tugagan bo'lishi mumkin.\n" +
                "Tekshirib, ilovani ochib qayta ishga tushiring."))
            .setSmallIcon(android.R.drawable.stat_notify_error)
            .setAutoCancel(true)
            .setContentIntent(openPendingIntent())
            .build()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            stopForeground(STOP_FOREGROUND_DETACH)
        } else {
            @Suppress("DEPRECATION") stopForeground(false)
        }
        getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, notif)
        stopSelf()
    }

    /**
     * SMS yuboradi va PendingIntent (SENT) orqali HAQIQIY natijani kutadi.
     * Hamma qism RESULT_OK bo'lsa true, aks holda (yoki timeout) false.
     */
    private suspend fun sendSms(phone: String, message: String, simSlot: Int): Boolean {
        val smsManager = getSmsManagerForSlot(simSlot)
        val parts = try {
            smsManager.divideMessage(message)
        } catch (e: Exception) {
            Log.e(TAG, "divideMessage error: ${e.message}")
            return false
        }
        val total = parts.size
        val action = "uz.idrokedu.smsgateway.SMS_SENT.${System.currentTimeMillis()}.${(0..99999).random()}"

        return withTimeoutOrNull(45_000L) {
            suspendCancellableCoroutine { cont ->
                var received = 0
                var allOk = true

                val receiver = object : BroadcastReceiver() {
                    override fun onReceive(c: Context?, i: Intent?) {
                        received++
                        if (resultCode != Activity.RESULT_OK) allOk = false
                        if (received >= total) {
                            try { unregisterReceiver(this) } catch (_: Exception) {}
                            if (cont.isActive) cont.resume(allOk)
                        }
                    }
                }

                ContextCompat.registerReceiver(
                    this@SmsWorkerService, receiver,
                    IntentFilter(action), ContextCompat.RECEIVER_NOT_EXPORTED
                )
                cont.invokeOnCancellation {
                    try { unregisterReceiver(receiver) } catch (_: Exception) {}
                }

                try {
                    val sentIntents = ArrayList<PendingIntent>(total)
                    for (idx in 0 until total) {
                        sentIntents.add(
                            PendingIntent.getBroadcast(
                                this@SmsWorkerService, idx,
                                Intent(action).setPackage(packageName),
                                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
                            )
                        )
                    }
                    if (total == 1) {
                        smsManager.sendTextMessage(phone, null, message, sentIntents[0], null)
                    } else {
                        smsManager.sendMultipartTextMessage(phone, null, parts, sentIntents, null)
                    }
                } catch (e: Exception) {
                    Log.e(TAG, "SMS send error: ${e.message}")
                    try { unregisterReceiver(receiver) } catch (_: Exception) {}
                    if (cont.isActive) cont.resume(false)
                }
            }
        } ?: false
    }

    /** Berilgan SIM slot (1/2) uchun SmsManager; topilmasa yoki 0 bo'lsa — default. */
    private fun getSmsManagerForSlot(slot: Int): SmsManager {
        if (slot in 1..2) {
            try {
                val sm = getSystemService(SubscriptionManager::class.java)
                val targetIndex = slot - 1 // SIM 1 -> simSlotIndex 0
                val info = sm?.activeSubscriptionInfoList
                    ?.firstOrNull { it.simSlotIndex == targetIndex }
                if (info != null) {
                    return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                        getSystemService(SmsManager::class.java)
                            .createForSubscriptionId(info.subscriptionId)
                    } else {
                        @Suppress("DEPRECATION")
                        SmsManager.getSmsManagerForSubscriptionId(info.subscriptionId)
                    }
                }
                log("⚠️ SIM $slot topilmadi, default ishlatildi")
            } catch (e: Exception) {
                Log.e(TAG, "SIM slot error: ${e.message}")
            }
        }
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            getSystemService(SmsManager::class.java)
        } else {
            @Suppress("DEPRECATION")
            SmsManager.getDefault()
        }
    }

    private fun loadDeviceId(): String {
        val prefs = getSharedPreferences("sms_gateway", MODE_PRIVATE)
        var id = prefs.getString("device_id", null)
        if (id == null) {
            val brand = Build.BRAND.lowercase().replace(" ", "_")
            val model = Build.MODEL.lowercase().replace(" ", "_")
            val rand = (1000..9999).random()
            id = "${brand}_${model}_$rand"
            prefs.edit().putString("device_id", id).apply()
        }
        return id
    }

    private fun log(msg: String) {
        val time = java.text.SimpleDateFormat("HH:mm:ss", java.util.Locale.getDefault())
            .format(java.util.Date())
        val line = "[$time] $msg"
        lastLog = line
        Log.d(TAG, msg)
        onLogUpdate?.invoke(line)
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID, "SMS Gateway", NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "SMS Gateway fon xizmati"
                setShowBadge(false)
            }
            val nm = getSystemService(NotificationManager::class.java)
            nm.createNotificationChannel(channel)
        }
    }

    private fun openPendingIntent(): PendingIntent {
        val openIntent = Intent(this, MainActivity::class.java)
        return PendingIntent.getActivity(
            this, 0, openIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }

    private fun buildNotification(text: String): Notification {
        val stopIntent = Intent(this, SmsWorkerService::class.java).apply { action = ACTION_STOP }
        val stopPending = PendingIntent.getService(
            this, 0, stopIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("SMS Gateway")
            .setContentText(text)
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setOngoing(true)
            .setContentIntent(openPendingIntent())
            .addAction(android.R.drawable.ic_delete, "To'xtatish", stopPending)
            .build()
    }

    private fun updateNotification(text: String) {
        val nm = getSystemService(NotificationManager::class.java)
        nm.notify(NOTIFICATION_ID, buildNotification(text))
    }

    override fun onDestroy() {
        job?.cancel()
        wakeLock?.release()
        isRunning = false
        log("🛑 Worker to'xtatildi")
        super.onDestroy()
    }
}
