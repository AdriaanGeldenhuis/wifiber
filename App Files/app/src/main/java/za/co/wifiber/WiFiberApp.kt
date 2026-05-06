package za.co.wifiber

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import androidx.core.content.getSystemService
import za.co.wifiber.firebase.FirebaseSetup

class WiFiberApp : Application() {
    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        FirebaseSetup.initialiseIfPossible(this)
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val mgr = getSystemService<NotificationManager>() ?: return
        val id = getString(R.string.notif_channel_id)
        if (mgr.getNotificationChannel(id) != null) return
        val channel = NotificationChannel(
            id,
            getString(R.string.notif_channel_name),
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = getString(R.string.notif_channel_desc)
            enableVibration(true)
            enableLights(true)
        }
        mgr.createNotificationChannel(channel)
    }
}
