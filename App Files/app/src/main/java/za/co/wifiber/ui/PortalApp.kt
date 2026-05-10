package za.co.wifiber.ui

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Logout
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.OpenInBrowser
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Share
import androidx.compose.material.icons.filled.SupportAgent
import androidx.compose.material3.Divider
import androidx.compose.material3.DrawerValue
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalDrawerSheet
import androidx.compose.material3.ModalNavigationDrawer
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.NavigationDrawerItem
import androidx.compose.material3.NavigationDrawerItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.rememberDrawerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import za.co.wifiber.R
import za.co.wifiber.nav.Audience
import za.co.wifiber.nav.PortalDestination
import za.co.wifiber.nav.PortalGroup
import za.co.wifiber.notifications.RoleObserver
import za.co.wifiber.web.PortalWebView
import za.co.wifiber.web.rememberPortalWebState

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PortalApp(
    portalHost: String,
    portalBaseUrl: String,
    supportPhone: String,
    deepLinkUrl: String?,
    onDeepLinkConsumed: () -> Unit,
    onSignOut: () -> Unit
) {
    val webState = rememberPortalWebState()
    val drawerState = rememberDrawerState(initialValue = DrawerValue.Closed)
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var moreMenuOpen by remember { mutableStateOf(false) }

    // Role drives which nav set we render. RoleObserver pings
    // /account/api/whoami.php on every page load (see PortalWebView's
    // onPageFinished) so this stays in sync with the WebView session.
    val userRole by RoleObserver.role.collectAsState()
    val isStaff = PortalDestination.audienceFor(userRole) == Audience.Staff

    val activeDestination by remember(webState, userRole) {
        derivedStateOf {
            PortalDestination.fromUrl(webState.currentUrl, userRole)
                ?: PortalDestination.defaultFor(userRole)
        }
    }
    val bottomBarItems = remember(userRole) { PortalDestination.bottomBarFor(userRole) }

    LaunchedEffect(deepLinkUrl) {
        val target = deepLinkUrl ?: return@LaunchedEffect
        webState.loadUrl(target)
        onDeepLinkConsumed()
    }

    BackHandler(enabled = drawerState.isOpen) {
        scope.launch { drawerState.close() }
    }
    BackHandler(enabled = !drawerState.isOpen && webState.canGoBack) {
        webState.goBack()
    }

    ModalNavigationDrawer(
        drawerState = drawerState,
        // Open only via the menu button — swipe gestures were getting
        // confused with vertical scrolls inside the WebView.
        gesturesEnabled = drawerState.isOpen,
        drawerContent = {
            PortalDrawer(
                active = activeDestination,
                isStaff = isStaff,
                onNavigate = { dest ->
                    scope.launch { drawerState.close() }
                    webState.loadUrl(dest.urlOn(portalHost))
                },
                onSignOut = {
                    scope.launch { drawerState.close() }
                    webState.loadUrl("https://$portalHost/account/logout.php")
                    onSignOut()
                }
            )
        }
    ) {
        Scaffold(
            topBar = {
                TopAppBar(
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = MaterialTheme.colorScheme.surface,
                        titleContentColor = MaterialTheme.colorScheme.onSurface,
                        navigationIconContentColor = MaterialTheme.colorScheme.onSurface,
                        actionIconContentColor = MaterialTheme.colorScheme.onSurface
                    ),
                    title = {
                        Image(
                            painter = painterResource(id = R.drawable.logo_brand),
                            contentDescription = "WiFiber",
                            modifier = Modifier.height(36.dp)
                        )
                    },
                    navigationIcon = {
                        IconButton(onClick = { scope.launch { drawerState.open() } }) {
                            Icon(Icons.Filled.Menu, contentDescription = stringRes(R.string.action_menu))
                        }
                    },
                    actions = {
                        IconButton(onClick = { webState.reload() }) {
                            Icon(Icons.Filled.Refresh, contentDescription = stringRes(R.string.action_refresh))
                        }
                        IconButton(onClick = { moreMenuOpen = true }) {
                            Icon(Icons.Filled.MoreVert, contentDescription = stringRes(R.string.action_more))
                        }
                        DropdownMenu(
                            expanded = moreMenuOpen,
                            onDismissRequest = { moreMenuOpen = false }
                        ) {
                            DropdownMenuItem(
                                text = { Text(stringRes(R.string.action_open_in_browser)) },
                                leadingIcon = { Icon(Icons.Filled.OpenInBrowser, null) },
                                onClick = {
                                    moreMenuOpen = false
                                    val url = webState.currentUrl.ifEmpty { portalBaseUrl }
                                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
                                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                    runCatching { context.startActivity(intent) }
                                }
                            )
                            DropdownMenuItem(
                                text = { Text(stringRes(R.string.action_share)) },
                                leadingIcon = { Icon(Icons.Filled.Share, null) },
                                onClick = {
                                    moreMenuOpen = false
                                    val send = Intent(Intent.ACTION_SEND).apply {
                                        type = "text/plain"
                                        putExtra(Intent.EXTRA_TEXT, webState.currentUrl.ifEmpty { portalBaseUrl })
                                        putExtra(Intent.EXTRA_SUBJECT, "WiFiber portal")
                                    }
                                    val chooser = Intent.createChooser(send, "Share via")
                                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                    runCatching { context.startActivity(chooser) }
                                }
                            )
                            DropdownMenuItem(
                                text = { Text(stringRes(R.string.action_call_support)) },
                                leadingIcon = { Icon(Icons.Filled.Call, null) },
                                onClick = {
                                    moreMenuOpen = false
                                    val dial = Intent(Intent.ACTION_DIAL, Uri.parse("tel:$supportPhone"))
                                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                    runCatching { context.startActivity(dial) }
                                }
                            )
                            Divider()
                            DropdownMenuItem(
                                text = { Text(stringRes(R.string.nav_logout)) },
                                leadingIcon = { Icon(Icons.Filled.Logout, null) },
                                onClick = {
                                    moreMenuOpen = false
                                    webState.loadUrl("https://$portalHost/account/logout.php")
                                    onSignOut()
                                }
                            )
                        }
                    }
                )
            },
            bottomBar = {
                NavigationBar(containerColor = MaterialTheme.colorScheme.surface) {
                    bottomBarItems.forEach { dest ->
                        NavigationBarItem(
                            selected = activeDestination == dest ||
                                    (dest == PortalDestination.Invoices && activeDestination == PortalDestination.Payments) ||
                                    (dest == PortalDestination.Invoices && activeDestination == PortalDestination.Statement),
                            onClick = { webState.loadUrl(dest.urlOn(portalHost)) },
                            icon = { Icon(dest.icon, contentDescription = dest.title) },
                            label = { Text(shortLabel(dest)) },
                            colors = NavigationBarItemDefaults.colors(
                                selectedIconColor = MaterialTheme.colorScheme.primary,
                                selectedTextColor = MaterialTheme.colorScheme.primary,
                                indicatorColor = MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.25f)
                            )
                        )
                    }
                }
            },
            floatingActionButton = {
                // The FAB is customer-shaped ("New ticket"). For staff
                // it'd cover the install/ticket lists they actually
                // need to see, so we hide it.
                if (!isStaff) {
                    ExtendedFloatingActionButton(
                        onClick = { webState.loadUrl(PortalDestination.Tickets.urlOn(portalHost)) },
                        containerColor = MaterialTheme.colorScheme.tertiary,
                        contentColor = MaterialTheme.colorScheme.onTertiary,
                        icon = { Icon(Icons.Filled.SupportAgent, contentDescription = null) },
                        text = { Text(stringRes(R.string.action_new_ticket)) }
                    )
                }
            }
        ) { padding ->
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(padding)
            ) {
                PortalWebView(
                    initialUrl = portalBaseUrl,
                    state = webState,
                    expectedHost = portalHost
                )
            }
        }
    }
}

@Composable
private fun PortalDrawer(
    active: PortalDestination,
    isStaff: Boolean,
    onNavigate: (PortalDestination) -> Unit,
    onSignOut: () -> Unit
) {
    val audience = if (isStaff) Audience.Staff else Audience.Client
    ModalDrawerSheet(
        modifier = Modifier.width(300.dp)
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(20.dp),
            verticalArrangement = Arrangement.spacedBy(4.dp)
        ) {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(bottom = 12.dp),
                contentAlignment = Alignment.CenterStart
            ) {
                Column {
                    Image(
                        painter = painterResource(id = R.drawable.logo_brand),
                        contentDescription = "WiFiber",
                        modifier = Modifier.height(44.dp)
                    )
                    Spacer(Modifier.height(8.dp))
                    Text(
                        text = if (isStaff) "Staff portal" else "Customer portal",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f)
                    )
                }
            }
            Divider()
        }
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 12.dp, vertical = 4.dp)
        ) {
            PortalGroup.entries.forEach { group ->
                val items = PortalDestination.entries.filter { it.group == group && it.isFor(audience) }
                if (items.isEmpty()) return@forEach
                Text(
                    text = group.title,
                    style = MaterialTheme.typography.labelLarge,
                    color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f),
                    modifier = Modifier.padding(start = 16.dp, top = 12.dp, bottom = 4.dp)
                )
                items.forEach { dest ->
                    NavigationDrawerItem(
                        icon = { Icon(dest.icon, contentDescription = null) },
                        label = { Text(dest.title) },
                        selected = dest == active,
                        onClick = { onNavigate(dest) },
                        colors = NavigationDrawerItemDefaults.colors(
                            selectedContainerColor = MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.25f)
                        ),
                        modifier = Modifier.padding(vertical = 2.dp)
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
            Divider()
            NavigationDrawerItem(
                icon = { Icon(Icons.Filled.Logout, contentDescription = null) },
                label = { Text("Sign out") },
                selected = false,
                onClick = onSignOut,
                modifier = Modifier.padding(vertical = 4.dp, horizontal = 4.dp)
            )
            Spacer(Modifier.height(12.dp))
        }
    }
}

private fun shortLabel(dest: PortalDestination): String = when (dest) {
    PortalDestination.Dashboard         -> "Home"
    PortalDestination.Service           -> "Service"
    PortalDestination.Invoices          -> "Billing"
    PortalDestination.Tickets           -> "Support"
    PortalDestination.Profile           -> "Profile"
    PortalDestination.StaffDashboard    -> "Home"
    PortalDestination.StaffInstalls     -> "Installs"
    PortalDestination.StaffTickets      -> "Tickets"
    PortalDestination.StaffMap          -> "Map"
    PortalDestination.StaffNotifications -> "Alerts"
    else -> dest.title
}

@Composable
private fun stringRes(id: Int): String = androidx.compose.ui.res.stringResource(id)
