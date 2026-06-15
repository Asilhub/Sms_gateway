package uz.idrokedu.smsgateway

import android.content.Context
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.net.URLEncoder
import java.util.concurrent.TimeUnit

object ApiClient {
    // SERVER manzili maxfiy emas — BuildConfig'da qoladi.
    private val SERVER = BuildConfig.SERVER

    // API kalit APK ichida YO'Q — foydalanuvchi kiritadi, prefs'da saqlanadi.
    @Volatile var apiKey: String = ""

    private val client = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()

    /** Saqlangan kalitni prefs'dan yuklaydi. App/Service boshida chaqiriladi. */
    fun init(context: Context) {
        val prefs = context.getSharedPreferences("sms_gateway", Context.MODE_PRIVATE)
        apiKey = prefs.getString("api_key", "") ?: ""
    }

    fun saveApiKey(context: Context, key: String) {
        apiKey = key.trim()
        context.getSharedPreferences("sms_gateway", Context.MODE_PRIVATE)
            .edit().putString("api_key", apiKey).apply()
    }

    fun hasKey(): Boolean = apiKey.isNotBlank()

    private fun enc(s: String): String = URLEncoder.encode(s, "UTF-8")

    /** Barcha parametrlar URL-encode qilinadi. */
    private fun call(params: Map<String, String>, deviceId: String): JSONObject? {
        return try {
            val query = StringBuilder()
            for ((k, v) in params) {
                if (query.isNotEmpty()) query.append("&")
                query.append(enc(k)).append("=").append(enc(v))
            }
            query.append("&key=").append(enc(apiKey))
            query.append("&device_id=").append(enc(deviceId))

            val request = Request.Builder().url("$SERVER?$query").build()
            client.newCall(request).execute().use { response ->
                val body = response.body?.string() ?: return null
                JSONObject(body)
            }
        } catch (e: Exception) {
            null
        }
    }

    fun heartbeat(deviceId: String): Boolean =
        call(mapOf("action" to "heartbeat"), deviceId) != null

    fun getTask(deviceId: String): JSONObject? =
        call(mapOf("action" to "get_task"), deviceId)

    fun updateTask(id: String, status: String, deviceId: String) {
        call(mapOf("action" to "update", "id" to id, "status" to status), deviceId)
    }

    fun reportIncomingSms(phone: String, message: String, deviceId: String) {
        call(mapOf("action" to "incoming_sms", "phone" to phone, "msg" to message), deviceId)
    }

    fun reportError(msg: String, deviceId: String) {
        call(mapOf("action" to "error", "msg" to msg), deviceId)
    }

    /** Kalitsiz versiya tekshiruvi (server'da 'version' kalit talab qilmaydi). */
    fun getLatestVersion(): JSONObject? = call(mapOf("action" to "version"), "")
}
