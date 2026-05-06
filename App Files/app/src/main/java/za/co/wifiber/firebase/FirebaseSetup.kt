package za.co.wifiber.firebase

import android.content.Context
import android.util.Log
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions

/**
 * Firebase is initialised lazily so the build succeeds even when no
 * google-services.json has been added yet. Drop the values from the
 * Firebase Console into res/values/firebase_options.xml (see the
 * sample file in this directory) and the next launch will pick them up.
 */
object FirebaseSetup {
    private const val TAG = "FirebaseSetup"
    private var initialised = false

    val isReady: Boolean get() = initialised

    fun initialiseIfPossible(context: Context) {
        if (initialised) return
        if (FirebaseApp.getApps(context).isNotEmpty()) {
            initialised = true
            return
        }
        val res = context.resources
        val pkg = context.packageName
        fun str(name: String): String? = res.getIdentifier(name, "string", pkg)
            .takeIf { it != 0 }
            ?.let { id -> context.getString(id).takeIf { it.isNotBlank() } }

        val apiKey = str("firebase_api_key")
        val appId = str("firebase_application_id")
        val projectId = str("firebase_project_id")
        val senderId = str("firebase_sender_id")
        if (apiKey == null || appId == null || projectId == null || senderId == null) {
            Log.i(TAG, "Firebase values missing — push notifications disabled.")
            return
        }
        val opts = FirebaseOptions.Builder()
            .setApiKey(apiKey)
            .setApplicationId(appId)
            .setProjectId(projectId)
            .setGcmSenderId(senderId)
            .apply { str("firebase_storage_bucket")?.let(::setStorageBucket) }
            .apply { str("firebase_database_url")?.let(::setDatabaseUrl) }
            .build()
        runCatching {
            FirebaseApp.initializeApp(context, opts)
            initialised = true
            Log.i(TAG, "Firebase initialised.")
        }.onFailure { Log.w(TAG, "Firebase init failed", it) }
    }
}
