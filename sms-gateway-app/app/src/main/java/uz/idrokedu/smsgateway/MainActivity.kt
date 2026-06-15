package uz.idrokedu.smsgateway

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.ScrollView
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.google.android.material.button.MaterialButton

class MainActivity : AppCompatActivity() {

    private lateinit var tvStatus: TextView
    private lateinit var tvStats: TextView
    private lateinit var tvLastAction: TextView
    private lateinit var tvDeviceId: TextView
    private lateinit var tvLog: TextView
    private lateinit var scrollLog: ScrollView
    private lateinit var btnToggle: MaterialButton
    private lateinit var statusDot: android.view.View

    private val logLines = mutableListOf<String>()
    private val maxLogLines = 100

    private var updateDialog: AlertDialog? = null

    private val requiredPermissions = mutableListOf(
        Manifest.permission.SEND_SMS,
        Manifest.permission.READ_SMS,
        Manifest.permission.RECEIVE_SMS,
        Manifest.permission.READ_PHONE_STATE
    ).apply {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            add(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        tvStatus = findViewById(R.id.tvStatus)
        tvStats = findViewById(R.id.tvStats)
        tvLastAction = findViewById(R.id.tvLastAction)
        tvDeviceId = findViewById(R.id.tvDeviceId)
        tvLog = findViewById(R.id.tvLog)
        scrollLog = findViewById(R.id.scrollLog)
        btnToggle = findViewById(R.id.btnToggle)
        statusDot = findViewById(R.id.statusDot)

        ApiClient.init(this)

        // Device ID
        val prefs = getSharedPreferences("sms_gateway", MODE_PRIVATE)
        var deviceId = prefs.getString("device_id", null)
        if (deviceId == null) {
            val brand = Build.BRAND.lowercase().replace(" ", "_")
            val model = Build.MODEL.lowercase().replace(" ", "_")
            val rand = (1000..9999).random()
            deviceId = "${brand}_${model}_$rand"
            prefs.edit().putString("device_id", deviceId).apply()
        }
        tvDeviceId.text = "Qurilma: $deviceId"
        // Uzoq bosib API kalitni o'zgartirish mumkin
        tvDeviceId.setOnLongClickListener { showKeyDialog(); true }

        // Log callback
        SmsWorkerService.onLogUpdate = { line ->
            runOnUiThread { appendLog(line) }
        }

        btnToggle.setOnClickListener {
            when {
                SmsWorkerService.isRunning -> stopWorker()
                !ApiClient.hasKey() -> showKeyDialog()
                else -> requestPermissionsAndStart()
            }
        }

        updateUI()

        // API kalit yo'q bo'lsa — avval kalit so'raymiz
        if (!ApiClient.hasKey()) {
            showKeyDialog()
        } else if (hasAllPermissions()) {
            requestBatteryOptimization()
            if (!SmsWorkerService.isRunning) {
                startWorker()
            }
        } else {
            requestPermissionsAndStart()
        }
    }

    override fun onResume() {
        super.onResume()
        SmsWorkerService.onLogUpdate = { line ->
            runOnUiThread { appendLog(line) }
        }
        updateUI()
        checkForUpdate()
    }

    // ---- API KALIT ----

    private fun showKeyDialog() {
        val input = EditText(this).apply {
            hint = "API kalit"
            setText(ApiClient.apiKey)
            setSingleLine()
        }
        val pad = (16 * resources.displayMetrics.density).toInt()
        val box = FrameLayout(this).apply {
            setPadding(pad, pad / 2, pad, 0)
            addView(input)
        }
        AlertDialog.Builder(this)
            .setTitle("🔑 API kalit")
            .setMessage("Ishlash uchun API kalitni kiriting.\nKalitni administrator beradi.")
            .setView(box)
            .setCancelable(false)
            .setPositiveButton("Saqlash") { _, _ ->
                val key = input.text.toString().trim()
                if (key.isEmpty()) {
                    appendLog("⚠️ Kalit bo'sh bo'lishi mumkin emas")
                    showKeyDialog()
                } else {
                    ApiClient.saveApiKey(this, key)
                    appendLog("🔑 Kalit saqlandi")
                    if (hasAllPermissions()) {
                        requestBatteryOptimization()
                        startWorker()
                    } else {
                        requestPermissionsAndStart()
                    }
                }
            }
            .setNegativeButton("Bekor", null)
            .show()
    }

    // ---- MAJBURIY YANGILANISH ----

    private fun checkForUpdate() {
        if (updateDialog?.isShowing == true) return
        Thread {
            val info = ApiClient.getLatestVersion() ?: return@Thread
            val latest = info.optInt("latest_code", 0)
            val url = info.optString("url", "")
            if (latest > BuildConfig.VERSION_CODE && url.isNotEmpty()) {
                val name = info.optString("latest_name", "")
                val force = info.optBoolean("force", false)
                runOnUiThread { showUpdateDialog(name, url, force) }
            }
        }.start()
    }

    private fun showUpdateDialog(name: String, url: String, force: Boolean) {
        if (isFinishing || updateDialog?.isShowing == true) return
        val msg = "Yangi versiya" + (if (name.isNotEmpty()) " ($name)" else "") + " chiqdi." +
            if (force) "\n\nDavom etish uchun yangilang." else ""
        val b = AlertDialog.Builder(this)
            .setTitle("⬆️ Yangilanish")
            .setMessage(msg)
            .setCancelable(!force)
            .setPositiveButton("Yangilash") { _, _ ->
                try { startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) } catch (_: Exception) {}
            }
        if (!force) b.setNegativeButton("Keyinroq", null)
        updateDialog = b.create()
        updateDialog?.show()
    }

    // ---- WORKER / RUXSATLAR ----

    private fun requestPermissionsAndStart() {
        val needed = requiredPermissions.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }
        if (needed.isNotEmpty()) {
            ActivityCompat.requestPermissions(this, needed.toTypedArray(), 100)
        } else {
            requestBatteryOptimization()
            startWorker()
        }
    }

    override fun onRequestPermissionsResult(
        requestCode: Int, permissions: Array<out String>, grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == 100) {
            val smsGranted = ContextCompat.checkSelfPermission(
                this, Manifest.permission.SEND_SMS
            ) == PackageManager.PERMISSION_GRANTED

            if (smsGranted) {
                requestBatteryOptimization()
                startWorker()
            } else {
                appendLog("❌ SMS ruxsati berilmadi!")
                tvStatus.text = "Ruxsat kerak"
            }
        }
    }

    private fun requestBatteryOptimization() {
        val pm = getSystemService(POWER_SERVICE) as PowerManager
        if (!pm.isIgnoringBatteryOptimizations(packageName)) {
            try {
                val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS)
                intent.data = Uri.parse("package:$packageName")
                startActivity(intent)
            } catch (_: Exception) {}
        }
    }

    private fun hasAllPermissions(): Boolean {
        return requiredPermissions.all {
            ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED
        }
    }

    private fun startWorker() {
        if (!ApiClient.hasKey()) { showKeyDialog(); return }
        val intent = Intent(this, SmsWorkerService::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(intent)
        } else {
            startService(intent)
        }
        appendLog("🚀 Worker ishga tushdi")
        updateUI()
    }

    private fun stopWorker() {
        val intent = Intent(this, SmsWorkerService::class.java)
        intent.action = SmsWorkerService.ACTION_STOP
        startService(intent)
        appendLog("🛑 Worker to'xtatildi")
        btnToggle.postDelayed({ updateUI() }, 500)
    }

    private fun updateUI() {
        if (SmsWorkerService.isRunning) {
            tvStatus.text = "Ishlamoqda"
            statusDot.setBackgroundResource(R.drawable.dot_green)
            btnToggle.text = "⏹  TO'XTATISH"
            btnToggle.setBackgroundColor(ContextCompat.getColor(this, android.R.color.holo_red_dark))
        } else {
            tvStatus.text = if (ApiClient.hasKey()) "To'xtatilgan" else "Kalit kiriting"
            statusDot.setBackgroundResource(R.drawable.dot_red)
            btnToggle.text = if (ApiClient.hasKey()) "▶  ISHGA TUSHIRISH" else "🔑  KALIT KIRITISH"
            btnToggle.setBackgroundColor(ContextCompat.getColor(this, android.R.color.holo_green_dark))
        }
        tvStats.text = "Yuborildi: ${SmsWorkerService.sentCount}  |  Xato: ${SmsWorkerService.failCount}"
        if (SmsWorkerService.lastLog.isNotEmpty()) {
            tvLastAction.text = SmsWorkerService.lastLog
        }
    }

    private fun appendLog(line: String) {
        logLines.add(line)
        if (logLines.size > maxLogLines) logLines.removeAt(0)
        tvLog.text = logLines.joinToString("\n")
        scrollLog.post { scrollLog.fullScroll(ScrollView.FOCUS_DOWN) }
        updateUI()
    }
}
