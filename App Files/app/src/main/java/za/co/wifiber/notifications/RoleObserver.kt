package za.co.wifiber.notifications

import android.content.Context
import android.util.Log
import android.webkit.CookieManager
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import org.json.JSONObject
import za.co.wifiber.R
import java.net.HttpURLConnection
import java.net.URL

/**
 * Tiny role poller — pings /account/api/whoami.php (using the WebView's
 * session cookie) so the Compose chrome can pick a role-appropriate nav
 * set: clients see the customer portal nav, staff see installs / tickets
 * / map / notifications. Falls silent on auth failure so a logged-out
 * user just keeps the default (client) nav until they sign in.
 */
object RoleObserver {

    private const val TAG = "RoleObserver"
    private val _role = MutableStateFlow("")
    val role: StateFlow<String> = _role

    fun refresh(context: Context) {
        val ctx = context.applicationContext
        Thread {
            runCatching { fetch(ctx) }
                .onFailure { Log.w(TAG, "whoami failed", it) }
        }.start()
    }

    private fun fetch(context: Context) {
        val host = context.getString(R.string.portal_host)
        val endpoint = "https://$host/account/api/whoami.php"

        val cookies = CookieManager.getInstance().getCookie(endpoint)
        if (cookies.isNullOrBlank() || !cookies.contains("wfsess=")) {
            // Not signed in yet — clear so the chrome falls back to its
            // default (client) nav rather than persisting a stale staff
            // role across logouts.
            if (_role.value.isNotEmpty()) _role.value = ""
            return
        }

        val conn = (URL(endpoint).openConnection() as HttpURLConnection).apply {
            requestMethod = "GET"
            connectTimeout = 10_000
            readTimeout = 10_000
            setRequestProperty("Cookie", cookies)
            setRequestProperty("Accept", "application/json")
        }
        try {
            val code = conn.responseCode
            val text = (if (code in 200..299) conn.inputStream else conn.errorStream)
                ?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }
                ?: return
            if (code !in 200..299) {
                Log.i(TAG, "whoami http=$code body=$text")
                return
            }
            val j = runCatching { JSONObject(text) }.getOrNull() ?: return
            val ok = j.optBoolean("ok", false)
            val role = if (ok) j.optString("role", "") else ""
            if (_role.value != role) {
                Log.i(TAG, "role updated -> '$role'")
                _role.value = role
            }
        } finally {
            conn.disconnect()
        }
    }
}
