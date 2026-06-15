package uz.idrokedu.smsgateway

import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.util.concurrent.TimeUnit

object ApiClient {
    // Qiymatlar keys.properties dan BuildConfig orqali keladi (git'da yo'q)
    private val SERVER = BuildConfig.SERVER
    private val API_KEY = BuildConfig.API_KEY

    private val client = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()

    fun call(params: String, deviceId: String): JSONObject? {
        return try {
            val url = "$SERVER?$params&key=$API_KEY&device_id=$deviceId"
            val request = Request.Builder().url(url).build()
            val response = client.newCall(request).execute()
            val body = response.body?.string() ?: return null
            JSONObject(body)
        } catch (e: Exception) {
            null
        }
    }

    fun heartbeat(deviceId: String): Boolean {
        return call("action=heartbeat", deviceId) != null
    }

    fun getTask(deviceId: String): JSONObject? {
        return call("action=get_task", deviceId)
    }

    fun updateTask(id: String, status: String, deviceId: String) {
        call("action=update&id=$id&status=$status", deviceId)
    }

    fun reportIncomingSms(phone: String, message: String, deviceId: String) {
        call("action=incoming_sms&phone=$phone&msg=$message", deviceId)
    }

    fun reportError(msg: String, deviceId: String) {
        call("action=error&msg=$msg", deviceId)
    }
}
