package uz.idrokedu.smsgateway

import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.net.URLEncoder
import java.util.concurrent.TimeUnit

object ApiClient {
    // Qiymatlar keys.properties dan BuildConfig orqali keladi (git'da yo'q)
    private val SERVER = BuildConfig.SERVER
    private val API_KEY = BuildConfig.API_KEY

    private val client = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()

    private fun enc(s: String): String = URLEncoder.encode(s, "UTF-8")

    /**
     * Barcha parametrlar URL-encode qilinadi — bo'sh joy, &, =, # kabi
     * belgilar so'rovni buzmaydi.
     */
    private fun call(params: Map<String, String>, deviceId: String): JSONObject? {
        return try {
            val query = StringBuilder()
            for ((k, v) in params) {
                if (query.isNotEmpty()) query.append("&")
                query.append(enc(k)).append("=").append(enc(v))
            }
            query.append("&key=").append(enc(API_KEY))
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
}
