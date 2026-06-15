package uz.idrokedu.smsgateway

import android.app.*
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import android.telephony.SmsManager
import android.telephony.SubscriptionManager
import android.util.Log
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.*
import org.json.JSONObject

class SmsWorkerService : Service() {

    companion object {
        const val TAG = "SmsWorker"
        const val CHANNEL_ID = "sms_gateway_channel"
        const val NOTIFICATION_ID = 1
        const val ACTION_STOP = "uz.idrokedu.smsgateway.STOP"

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

        if (id.isEmpty() || phone.isEmpty() || message.isEmpty()) {
            delay(5_000)
            return
        }

        log("📤 → $phone (ID:$id)")
        updateNotification("📤 Yuborilmoqda: $phone")

        // SMS yuborish
        val sent = sendSms(phone, message)

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
            log("❌ $phone (ID:$id)")

            if (consecutiveFails >= 5) {
                log("⚠️ 5 ta xato! 2 daq tanaffus")
                updateNotification("⚠️ Tanaffus - ko'p xato")
                delay(120_000)
                consecutiveFails = 0
            }
        }

        delay(5_000)
    }

    private fun sendSms(phone: String, message: String): Boolean {
        return try {
            val smsManager = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                getSystemService(SmsManager::class.java)
            } else {
                @Suppress("DEPRECATION")
                SmsManager.getDefault()
            }

            val parts = smsManager.divideMessage(message)
            if (parts.size == 1) {
                smsManager.sendTextMessage(phone, null, message, null, null)
            } else {
                smsManager.sendMultipartTextMessage(phone, null, parts, null, null)
            }

            // SMS yuborilganini tekshirish uchun biroz kutish
            Thread.sleep(3000)
            true
        } catch (e: Exception) {
            Log.e(TAG, "SMS send error: ${e.message}")
            false
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

    private fun buildNotification(text: String): Notification {
        val stopIntent = Intent(this, SmsWorkerService::class.java).apply { action = ACTION_STOP }
        val stopPending = PendingIntent.getService(
            this, 0, stopIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val openIntent = Intent(this, MainActivity::class.java)
        val openPending = PendingIntent.getActivity(
            this, 0, openIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("SMS Gateway")
            .setContentText(text)
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setOngoing(true)
            .setContentIntent(openPending)
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
