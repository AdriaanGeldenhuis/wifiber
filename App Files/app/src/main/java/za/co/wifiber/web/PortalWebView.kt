package za.co.wifiber.web

import android.annotation.SuppressLint
import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.os.Environment
import android.util.Log
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout

/**
 * State surfaced to the parent so the chrome (TopAppBar, drawer, FAB)
 * can react to navigation that happens inside the WebView.
 */
class PortalWebState {
    var canGoBack: Boolean by mutableStateOf(false)
        internal set
    var currentUrl: String by mutableStateOf("")
        internal set
    var pageTitle: String by mutableStateOf("")
        internal set
    var progress: Int by mutableStateOf(0)
        internal set
    var isLoading: Boolean by mutableStateOf(false)
        internal set

    internal var webView: WebView? = null

    fun loadUrl(url: String) { webView?.loadUrl(url) }
    fun reload() { webView?.reload() }
    fun goBack(): Boolean {
        val wv = webView ?: return false
        return if (wv.canGoBack()) { wv.goBack(); true } else false
    }
}

@Composable
fun rememberPortalWebState(): PortalWebState = remember { PortalWebState() }

/**
 * Hosts the live customer portal in a tuned WebView. Handles file uploads,
 * downloads, error pages, swipe-to-refresh and routes outbound links
 * (tel:, mailto:, external sites) through the OS.
 */
@SuppressLint("SetJavaScriptEnabled")
@Composable
fun PortalWebView(
    initialUrl: String,
    state: PortalWebState,
    expectedHost: String,
    modifier: Modifier = Modifier,
    onError: (String) -> Unit = {}
) {
    var lastError by remember { mutableStateOf<String?>(null) }

    var fileChooserCallback by remember { mutableStateOf<ValueCallback<Array<Uri>>?>(null) }
    val fileChooserLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val cb = fileChooserCallback
        fileChooserCallback = null
        if (cb == null) return@rememberLauncherForActivityResult
        val data = result.data
        val uris: Array<Uri>? = when {
            result.resultCode != android.app.Activity.RESULT_OK -> null
            data?.clipData != null -> Array(data.clipData!!.itemCount) { i -> data.clipData!!.getItemAt(i).uri }
            data?.data != null -> arrayOf(data.data!!)
            else -> null
        }
        cb.onReceiveValue(uris)
    }

    val webViewSavedState = rememberSaveable { Bundle() }

    AndroidView(
        modifier = modifier.fillMaxSize(),
        factory = { ctx ->
            val swipe = SwipeRefreshLayout(ctx).apply {
                layoutParams = ViewGroup.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.MATCH_PARENT
                )
                setColorSchemeColors(0xFF0077B6.toInt(), 0xFF00B4D8.toInt(), 0xFFFFB703.toInt())
            }

            val web = WebView(ctx).apply {
                layoutParams = ViewGroup.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.MATCH_PARENT
                )
                settings.apply {
                    javaScriptEnabled = true
                    domStorageEnabled = true
                    databaseEnabled = true
                    setSupportZoom(true)
                    builtInZoomControls = true
                    displayZoomControls = false
                    useWideViewPort = true
                    loadWithOverviewMode = true
                    mediaPlaybackRequiresUserGesture = false
                    cacheMode = WebSettings.LOAD_DEFAULT
                    mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
                    javaScriptCanOpenWindowsAutomatically = true
                    setSupportMultipleWindows(false)
                    userAgentString = "$userAgentString WiFiberApp/1.0"
                }
                CookieManager.getInstance().setAcceptCookie(true)
                CookieManager.getInstance().setAcceptThirdPartyCookies(this, true)
                isVerticalScrollBarEnabled = true
                isHorizontalScrollBarEnabled = false
                overScrollMode = WebView.OVER_SCROLL_NEVER

                webViewClient = object : WebViewClient() {
                    override fun shouldOverrideUrlLoading(
                        view: WebView,
                        request: WebResourceRequest
                    ): Boolean = handleUrl(ctx, view, request.url, expectedHost)

                    override fun onPageStarted(view: WebView, url: String?, favicon: android.graphics.Bitmap?) {
                        state.isLoading = true
                        lastError = null
                    }

                    override fun onPageFinished(view: WebView, url: String?) {
                        state.isLoading = false
                        state.currentUrl = url.orEmpty()
                        state.canGoBack = view.canGoBack()
                        state.pageTitle = view.title.orEmpty()
                        // The session cookie only exists after the customer
                        // signs in — keep retrying token registration until
                        // PushTokenRegistrar reports success (it dedupes).
                        runCatching {
                            com.google.firebase.messaging.FirebaseMessaging.getInstance().token
                                .addOnSuccessListener { tok ->
                                    if (!tok.isNullOrBlank()) {
                                        za.co.wifiber.notifications.PushTokenRegistrar
                                            .submit(ctx.applicationContext, tok)
                                    }
                                }
                        }
                        // Sync the signed-in role so the chrome can swap
                        // between client and staff nav sets without
                        // waiting for the next app foreground.
                        za.co.wifiber.notifications.RoleObserver
                            .refresh(ctx.applicationContext)
                    }

                    override fun onReceivedError(
                        view: WebView,
                        request: WebResourceRequest?,
                        error: WebResourceError?
                    ) {
                        if (request?.isForMainFrame == true) {
                            val desc = error?.description?.toString() ?: "unknown error"
                            lastError = desc
                            onError(desc)
                        }
                    }
                }

                webChromeClient = object : WebChromeClient() {
                    override fun onProgressChanged(view: WebView?, newProgress: Int) {
                        state.progress = newProgress
                    }

                    override fun onReceivedTitle(view: WebView?, title: String?) {
                        state.pageTitle = title.orEmpty()
                    }

                    override fun onShowFileChooser(
                        webView: WebView?,
                        filePathCallback: ValueCallback<Array<Uri>>?,
                        fileChooserParams: FileChooserParams?
                    ): Boolean {
                        fileChooserCallback?.onReceiveValue(null)
                        fileChooserCallback = filePathCallback
                        val intent = fileChooserParams?.createIntent()
                            ?: Intent(Intent.ACTION_GET_CONTENT).apply {
                                addCategory(Intent.CATEGORY_OPENABLE)
                                type = "*/*"
                            }
                        return runCatching {
                            fileChooserLauncher.launch(intent)
                            true
                        }.getOrElse {
                            fileChooserCallback = null
                            false
                        }
                    }
                }

                setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
                    enqueueDownload(ctx, url, userAgent, contentDisposition, mimeType)
                }
            }

            swipe.addView(web)
            swipe.setOnRefreshListener {
                web.reload()
                swipe.postDelayed({ swipe.isRefreshing = false }, 800)
            }
            state.webView = web

            // Restore prior state if we have it; otherwise load the initial URL.
            val restored = web.restoreState(webViewSavedState)
            if (restored == null) web.loadUrl(initialUrl)

            swipe
        },
        update = { /* state changes flow through PortalWebState */ }
    )

    DisposableEffect(state) {
        onDispose {
            state.webView?.let { wv ->
                wv.saveState(webViewSavedState)
                state.webView = null
            }
        }
    }

    // Top progress bar.
    val showProgress by remember(state) { derivedStateOf { state.isLoading && state.progress in 1..99 } }
    if (showProgress) {
        Column(modifier = Modifier.fillMaxWidth()) {
            LinearProgressIndicator(
                progress = { state.progress / 100f },
                modifier = Modifier.fillMaxWidth().height(3.dp),
                color = MaterialTheme.colorScheme.tertiary
            )
        }
    }

    // Error overlay.
    lastError?.let { err ->
        Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            Column(
                modifier = Modifier.padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                Text(
                    text = "Couldn’t load the portal",
                    style = MaterialTheme.typography.titleLarge
                )
                Spacer(Modifier.height(8.dp))
                Text(text = err, style = MaterialTheme.typography.bodyMedium)
                Spacer(Modifier.height(16.dp))
                Button(onClick = {
                    lastError = null
                    state.reload()
                }) { Text("Try again") }
            }
        }
    }
}

private fun handleUrl(
    context: Context,
    view: WebView,
    uri: Uri,
    expectedHost: String
): Boolean {
    val scheme = uri.scheme.orEmpty().lowercase()
    return when {
        scheme == "tel" || scheme == "mailto" || scheme == "sms" || scheme == "geo" -> {
            launchExternal(context, Intent(Intent.ACTION_VIEW, uri))
            true
        }
        scheme == "intent" -> {
            runCatching {
                val intent = Intent.parseUri(uri.toString(), Intent.URI_INTENT_SCHEME)
                launchExternal(context, intent)
            }
            true
        }
        scheme == "wifiber" -> {
            // Custom deep link — strip wifiber://account/foo to /account/foo.
            val path = uri.path.orEmpty().ifEmpty { "/account/" }
            view.loadUrl("https://$expectedHost$path")
            true
        }
        scheme == "http" || scheme == "https" -> {
            val host = uri.host.orEmpty()
            if (host.equals(expectedHost, ignoreCase = true) ||
                host.endsWith(".$expectedHost", ignoreCase = true)
            ) false
            else {
                launchExternal(context, Intent(Intent.ACTION_VIEW, uri))
                true
            }
        }
        else -> {
            launchExternal(context, Intent(Intent.ACTION_VIEW, uri))
            true
        }
    }
}

private fun launchExternal(context: Context, intent: Intent) {
    intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    runCatching { ContextCompat.startActivity(context, intent, null) }
        .onFailure { Toast.makeText(context, "No app to open this link", Toast.LENGTH_SHORT).show() }
}

private fun enqueueDownload(
    context: Context,
    url: String,
    userAgent: String?,
    contentDisposition: String?,
    mimeType: String?
) {
    runCatching {
        val filename = URLUtil.guessFileName(url, contentDisposition, mimeType)
        val request = DownloadManager.Request(Uri.parse(url)).apply {
            setMimeType(mimeType)
            addRequestHeader("User-Agent", userAgent)
            addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url))
            setTitle(filename)
            setDescription("Downloading from www.wifiber.co.za")
            setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, filename)
            setAllowedOverMetered(true)
            setAllowedOverRoaming(true)
        }
        val dm = context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        dm.enqueue(request)
        Toast.makeText(context, "Downloading $filename", Toast.LENGTH_SHORT).show()
    }.onFailure {
        Log.w("PortalWebView", "Download failed", it)
        Toast.makeText(context, "Download failed", Toast.LENGTH_SHORT).show()
    }
}
