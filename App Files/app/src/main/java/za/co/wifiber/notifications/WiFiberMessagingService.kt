package za.co.wifiber.notifications

import android.app.PendingIntent
import android.content.Intent
import android.net.Uri
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import za.co.wifiber.MainActivity
import za.co.wifiber.R

/**
 * Receives push payloads from Firebase Cloud Messaging.
 *
 * Payload shape we render — either an FCM "notification" block or a "data"
 * block with `title`, `body`, optional `url` (deep link into the portal).
 */
class WiFiberMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        Log.i(TAG, "FCM token: $token")
        // The portal exposes /account/api/register-device.php — when the
        // server endpoint is wired up, register the token there. Until
        // then we just log it for testing via the Firebase Console.
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val title = message.notification?.title
            ?: message.data["title"]
            ?: getString(R.string.app_name)
        val body = message.notification?.body
            ?: message.data["body"]
            ?: ""
        val url = message.data["url"]

        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            if (!url.isNullOrBlank()) {
                action = Intent.ACTION_VIEW
                data = Uri.parse(url)
            }
        }
        val pi = PendingIntent.getActivity(
            this,
            url?.hashCode() ?: 0,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val notif = NotificationCompat.Builder(this, getString(R.string.notif_channel_id))
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setContentIntent(pi)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()

        runCatching {
            NotificationManagerCompat.from(this).notify(message.hashCode(), notif)
        }
    }

    companion object {
        private const val TAG = "WiFiberFcm"
    }
}
