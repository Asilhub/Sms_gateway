package uz.idrokedu.smsgateway

import android.Manifest
import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.PowerManager
import android.provider.Settings
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.ImageButton
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import com.google.android.material.button.MaterialButton
import java.io.File

class MainActivity : AppCompatActivity() {

    private lateinit var tvStatus: TextView
    private lateinit var tvSent: TextView
    private lateinit var tvFailed: TextView
    private lateinit var tvLastAction: TextView
    private lateinit var tvDeviceId: TextView
    private lateinit var tvLog: TextView
    private lateinit var scrollLog: ScrollView
    private lateinit var btnToggle: MaterialButton
    private lateinit var btnSettings: ImageButton
    private lateinit var statusDot: android.view.View

    private var deviceId: String = ""

    private val logLines = mutableListOf<String>()
    private val maxLogLines = 100

    private var updateDialog: AlertDialog? = null
    private var downloadReceiver: BroadcastReceiver? = null
    private val updateApkName = "SmsGateway-update.apk"

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
        tvSent = findViewById(R.id.tvSent)
        tvFailed = findViewById(R.id.tvFailed)
        tvLastAction = findViewById(R.id.tvLastAction)
        tvDeviceId = findViewById(R.id.tvDeviceId)
        tvLog = findViewById(R.id.tvLog)
        scrollLog = findViewById(R.id.scrollLog)
        btnToggle = findViewById(R.id.btnToggle)
        btnSettings = findViewById(R.id.btnSettings)
        statusDot = findViewById(R.id.statusDot)

        ApiClient.init(this)

        // Device ID
        val prefs = getSharedPreferences("sms_gateway", MODE_PRIVATE)
        var savedId = prefs.getString("device_id", null)
        if (savedId == null) {
            savedId = generateDeviceId()
            prefs.edit().putString("device_id", savedId).apply()
        }
        deviceId = savedId
        tvDeviceId.text = "Qurilma: $deviceId  •  v${BuildConfig.VERSION_NAME}"
        // Uzoq bosib API kalitni o'zgartirish (tezkor yo'l)
        tvDeviceId.setOnLongClickListener { showKeyDialog(); true }

        // Sozlamalar menyusi
        btnSettings.setOnClickListener { showSettingsMenu() }

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

    /**
     * Barqaror qurilma ID si: ANDROID_ID asosida — qayta o'rnatishda ham bir xil qoladi,
     * shuning uchun botda takror qurilma paydo bo'lmaydi. Mavjud ID saqlanib qoladi.
     */
    private fun generateDeviceId(): String {
        val brand = Build.BRAND.lowercase().replace(" ", "_")
        val model = Build.MODEL.lowercase().replace(" ", "_")
        val androidId = try {
            Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID) ?: ""
        } catch (_: Exception) { "" }
        val suffix = if (androidId.isNotBlank())
            Integer.toHexString(androidId.hashCode()).takeLast(6)
        else (1000..9999).random().toString()
        return "${brand}_${model}_$suffix"
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

    // ---- SOZLAMALAR MENYUSI ----

    private fun showSettingsMenu() {
        val items = arrayOf(
            "🔑  API kalitni o'zgartirish",
            "🔄  Yangilanishni tekshirish",
            "🔋  Batareya optimizatsiyasi",
            "🔔  Bildirishnoma sozlamalari",
            "📋  Qurilma ID nusxalash",
            "🧹  Jurnalni tozalash",
            "ℹ️  Ilova haqida"
        )
        AlertDialog.Builder(this)
            .setTitle("⚙️ Sozlamalar")
            .setItems(items) { _, which ->
                when (which) {
                    0 -> showKeyDialog()
                    1 -> { appendLog("🔄 Yangilanish tekshirilmoqda..."); checkForUpdate(manual = true) }
                    2 -> openBatterySettings()
                    3 -> openNotificationSettings()
                    4 -> copyDeviceId()
                    5 -> clearLog()
                    6 -> showAbout()
                }
            }
            .setNegativeButton("Yopish", null)
            .show()
    }

    private fun openBatterySettings() {
        try {
            startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
        } catch (_: Exception) {
            requestBatteryOptimization()
        }
    }

    private fun openNotificationSettings() {
        try {
            val intent = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                    .putExtra(Settings.EXTRA_APP_PACKAGE, packageName)
            } else {
                Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
                    .setData(Uri.parse("package:$packageName"))
            }
            startActivity(intent)
        } catch (_: Exception) {
            Toast.makeText(this, "Ochib bo'lmadi", Toast.LENGTH_SHORT).show()
        }
    }

    private fun copyDeviceId() {
        val cm = getSystemService(CLIPBOARD_SERVICE) as ClipboardManager
        cm.setPrimaryClip(ClipData.newPlainText("device_id", deviceId))
        Toast.makeText(this, "Qurilma ID nusxalandi", Toast.LENGTH_SHORT).show()
    }

    private fun clearLog() {
        logLines.clear()
        tvLog.text = "Tayyor..."
    }

    private fun showAbout() {
        val keyState = if (ApiClient.hasKey()) "kiritilgan ✅" else "yo'q ❌"
        val msg = "📱 SMS Gateway\n\n" +
            "Versiya: v${BuildConfig.VERSION_NAME} (kod ${BuildConfig.VERSION_CODE})\n" +
            "Qurilma: $deviceId\n" +
            "API kalit: $keyState\n\n" +
            "Telefonni SMS shlyuziga aylantiradi. Server bilan avtomatik bog'lanadi va " +
            "yangi versiya chiqsa o'zi yangilanadi."
        AlertDialog.Builder(this)
            .setTitle("ℹ️ Ilova haqida")
            .setMessage(msg)
            .setPositiveButton("Yopish", null)
            .show()
    }

    // ---- MAJBURIY YANGILANISH ----

    private fun checkForUpdate(manual: Boolean = false) {
        if (updateDialog?.isShowing == true) return
        Thread {
            val info = ApiClient.getLatestVersion()
            if (info == null) {
                if (manual) runOnUiThread { appendLog("⚠️ Server bilan aloqa yo'q") }
                return@Thread
            }
            val latest = info.optInt("latest_code", 0)
            val url = info.optString("url", "")
            if (latest > BuildConfig.VERSION_CODE && url.isNotEmpty()) {
                val name = info.optString("latest_name", "")
                val force = info.optBoolean("force", false)
                runOnUiThread { showUpdateDialog(name, url, force) }
            } else if (manual) {
                runOnUiThread { appendLog("✅ Eng so'nggi versiya: v${BuildConfig.VERSION_NAME}") }
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
            .setPositiveButton("Yangilash") { _, _ -> startAppUpdate(url) }
        if (!force) b.setNegativeButton("Keyinroq", null)
        updateDialog = b.create()
        updateDialog?.show()
    }

    /** APK'ni ilovaning o'zi yuklab oladi va o'rnatish oynasini ochadi. */
    private fun startAppUpdate(url: String) {
        try {
            // Android 8+ da "noma'lum manbalar"ga ruxsat kerak
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O &&
                !packageManager.canRequestPackageInstalls()) {
                appendLog("⚙️ 'Noma'lum manbalar'ga ruxsat bering, so'ng qayta 'Yangilash'")
                try {
                    startActivity(
                        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:$packageName"))
                    )
                } catch (_: Exception) {}
                return
            }

            val target = File(getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS), updateApkName)
            if (target.exists()) target.delete()

            val dm = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
            val req = DownloadManager.Request(Uri.parse(url))
                .setTitle("SMS Gateway yangilanishi")
                .setDestinationInExternalFilesDir(this, Environment.DIRECTORY_DOWNLOADS, updateApkName)
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            val id = dm.enqueue(req)
            appendLog("⬇️ Yangilanish yuklanmoqda...")
            registerDownloadReceiver(id)
        } catch (e: Exception) {
            // Fallback: brauzerda ochish
            try { startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) } catch (_: Exception) {}
        }
    }

    private fun registerDownloadReceiver(id: Long) {
        downloadReceiver?.let { try { unregisterReceiver(it) } catch (_: Exception) {} }
        downloadReceiver = object : BroadcastReceiver() {
            override fun onReceive(c: Context?, i: Intent?) {
                val got = i?.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L) ?: -1L
                if (got != id) return
                try { unregisterReceiver(this) } catch (_: Exception) {}
                downloadReceiver = null
                installUpdate()
            }
        }
        ContextCompat.registerReceiver(
            this, downloadReceiver!!,
            IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE),
            ContextCompat.RECEIVER_EXPORTED
        )
    }

    private fun installUpdate() {
        try {
            val file = File(getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS), updateApkName)
            if (!file.exists()) { appendLog("⚠️ Yangilanish fayli topilmadi"); return }
            val uri = FileProvider.getUriForFile(this, "$packageName.fileprovider", file)
            val intent = Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(uri, "application/vnd.android.package-archive")
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            startActivity(intent)
        } catch (e: Exception) {
            appendLog("⚠️ O'rnatishda xato: ${e.message}")
        }
    }

    override fun onDestroy() {
        downloadReceiver?.let { try { unregisterReceiver(it) } catch (_: Exception) {} }
        super.onDestroy()
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
            btnToggle.text = "TO'XTATISH"
            btnToggle.setIconResource(R.drawable.ic_stop)
            btnToggle.setBackgroundColor(ContextCompat.getColor(this, R.color.danger))
        } else {
            tvStatus.text = if (ApiClient.hasKey()) "To'xtatilgan" else "Kalit kiriting"
            statusDot.setBackgroundResource(R.drawable.dot_red)
            if (ApiClient.hasKey()) {
                btnToggle.text = "ISHGA TUSHIRISH"
                btnToggle.setIconResource(R.drawable.ic_play)
                btnToggle.setBackgroundColor(ContextCompat.getColor(this, R.color.success))
            } else {
                btnToggle.text = "KALIT KIRITISH"
                btnToggle.setIconResource(R.drawable.ic_settings)
                btnToggle.setBackgroundColor(ContextCompat.getColor(this, R.color.brand))
            }
        }
        tvSent.text = SmsWorkerService.sentCount.toString()
        tvFailed.text = SmsWorkerService.failCount.toString()
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
