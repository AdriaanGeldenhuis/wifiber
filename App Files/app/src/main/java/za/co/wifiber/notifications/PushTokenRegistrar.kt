package za.co.wifiber.notifications

import android.content.Context
import android.os.Build
import android.util.Log
import android.webkit.CookieManager
import org.json.JSONObject
import za.co.wifiber.BuildConfig
import za.co.wifiber.R
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.atomic.AtomicReference

/**
 * Pushes the device's current FCM token to /account/api/push.php so the
 * portal stores it in `device_tokens` and can deliver via FCM HTTP v1.
 *
 * Auth piggybacks on the WebView session cookie, so this only succeeds
 * once the customer has logged in inside the app. Before that we soft-
 * fail and try again on the next call (FCM token rotation, app resume).
 */
object PushTokenRegistrar {

    private const val TAG = "PushTokenRegistrar"
    private val lastSent = AtomicReference<String?>(null)

    fun submit(context: Context, token: String) {
        if (token.isBlank()) return
        // Avoid hammering the server with the same token on every onResume.
        if (lastSent.get() == token) return
        val ctx = context.applicationContext
        Thread {
            runCatching { sync(ctx, token) }
                .onFailure { Log.w(TAG, "register failed", it) }
        }.start()
    }

    private fun sync(context: Context, token: String) {
        val host = context.getString(R.string.portal_host)
        val endpoint = "https://$host/account/api/push.php"

        val cookies = CookieManager.getInstance().getCookie(endpoint)
        if (cookies.isNullOrBlank() || !cookies.contains("wfsess=")) {
            Log.i(TAG, "no portal session yet — will retry on next resume / token rotation")
            return
        }

        val csrf = fetchCsrf(endpoint, cookies)
        if (csrf == null) {
            Log.w(TAG, "could not fetch csrf — auth probably stale")
            return
        }

        val body = JSONObject().apply {
            put("action", "register")
            put("token", token)
            put("platform", "android")
            put("app_version", BuildConfig.VERSION_NAME)
            put("device_label", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
        }

        val (status, payload) = postJson(endpoint, cookies, csrf, body)
        if (status in 200..299 && payload?.optBoolean("ok") == true) {
            lastSent.set(token)
            Log.i(TAG, "FCM token registered (id=${payload.optInt("id")})")
        } else {
            Log.w(TAG, "register failed http=$status body=$payload")
        }
    }

    private fun fetchCsrf(endpoint: String, cookies: String): String? {
        val conn = (URL(endpoint).openConnection() as HttpURLConnection).apply {
            requestMethod = "GET"
            connectTimeout = 10_000
            readTimeout = 10_000
            setRequestProperty("Cookie", cookies)
            setRequestProperty("Accept", "application/json")
        }
        try {
            val code = conn.responseCode
            val text = conn.bodyText() ?: return null
            if (code !in 200..299) {
                Log.i(TAG, "csrf http=$code body=$text")
                return null
            }
            val j = runCatching { JSONObject(text) }.getOrNull() ?: return null
            return j.optString("csrf").takeIf { it.isNotBlank() }
        } finally {
            conn.disconnect()
        }
    }

    private fun postJson(
        endpoint: String,
        cookies: String,
        csrf: String,
        body: JSONObject
    ): Pair<Int, JSONObject?> {
        val conn = (URL(endpoint).openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = 10_000
            readTimeout = 10_000
            doOutput = true
            setRequestProperty("Cookie", cookies)
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("X-CSRF", csrf)
        }
        try {
            conn.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            val code = conn.responseCode
            val text = conn.bodyText() ?: ""
            val parsed = runCatching { JSONObject(text) }.getOrNull()
            return code to parsed
        } finally {
            conn.disconnect()
        }
    }

    private fun HttpURLConnection.bodyText(): String? {
        val stream = if (responseCode in 200..299) inputStream else errorStream
        return stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }
    }
}
