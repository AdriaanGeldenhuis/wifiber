package za.co.wifiber

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.core.content.ContextCompat
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.flow.MutableStateFlow
import za.co.wifiber.firebase.FirebaseSetup
import za.co.wifiber.notifications.PushTokenRegistrar
import za.co.wifiber.notifications.RoleObserver
import za.co.wifiber.ui.PortalApp
import za.co.wifiber.ui.theme.WiFiberTheme

class MainActivity : ComponentActivity() {

    private val pendingDeepLink = MutableStateFlow<String?>(null)

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (!granted) {
            Toast.makeText(
                this,
                "Notifications are off — turn them on in Settings to get account alerts.",
                Toast.LENGTH_LONG
            ).show()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        pendingDeepLink.value = extractTargetUrl(intent)
        ensureNotificationPermission()
        registerFcmTokenIfPossible()

        setContent {
            WiFiberTheme {
                val deepLink by pendingDeepLink.collectAsState()
                PortalApp(
                    portalHost = getString(R.string.portal_host),
                    portalBaseUrl = getString(R.string.portal_base_url),
                    supportPhone = getString(R.string.support_phone),
                    deepLinkUrl = deepLink,
                    onDeepLinkConsumed = { pendingDeepLink.value = null },
                    onSignOut = {
                        // Hit the portal logout endpoint inside the WebView so the
                        // session cookie is cleared server-side.
                    }
                )
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        extractTargetUrl(intent)?.let { pendingDeepLink.value = it }
    }

    override fun onResume() {
        super.onResume()
        // Try to push the current FCM token to the portal each time the
        // app comes to the front — first call after login is when the
        // session cookie is finally available.
        registerFcmTokenIfPossible()
        // Re-fetch the signed-in role too, so a tech who logged in on
        // the web doesn't open the app to a stale client-shaped nav.
        RoleObserver.refresh(applicationContext)
    }

    private fun extractTargetUrl(intent: Intent?): String? {
        if (intent == null) return null
        val data = intent.data ?: return null
        val raw = data.toString()
        return when {
            data.scheme.equals("wifiber", ignoreCase = true) -> {
                val path = data.path?.ifEmpty { "/account/" } ?: "/account/"
                "https://${getString(R.string.portal_host)}$path"
            }
            raw.startsWith("https://${getString(R.string.portal_host)}/account") -> raw
            else -> null
        }
    }

    private fun ensureNotificationPermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        val granted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.POST_NOTIFICATIONS
        ) == PackageManager.PERMISSION_GRANTED
        if (!granted) notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    private fun registerFcmTokenIfPossible() {
        if (!FirebaseSetup.isReady) return
        runCatching {
            FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
                val token = task.result
                if (task.isSuccessful && !token.isNullOrBlank()) {
                    PushTokenRegistrar.submit(applicationContext, token)
                }
            }
        }
    }
}
