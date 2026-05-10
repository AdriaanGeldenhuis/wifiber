package za.co.wifiber.nav

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material.icons.filled.Build
import androidx.compose.material.icons.filled.Dashboard
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Inbox
import androidx.compose.material.icons.filled.Map
import androidx.compose.material.icons.filled.NetworkCheck
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Password
import androidx.compose.material.icons.filled.Payments
import androidx.compose.material.icons.filled.People
import androidx.compose.material.icons.filled.Receipt
import androidx.compose.material.icons.filled.Router
import androidx.compose.material.icons.filled.SupportAgent
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material.icons.filled.Wifi
import androidx.compose.ui.graphics.vector.ImageVector

/**
 * Audience for a nav entry. Drives which items show up in the bottom
 * bar / drawer given the role of the currently signed-in user.
 *
 *   Client — only customers see it
 *   Staff  — only admin / billing / support / technician / noc see it
 *   Both   — shared (e.g. /account/profile.php is the same page for
 *            everyone, so we don't need two duplicate entries)
 */
enum class Audience { Client, Staff, Both }

/**
 * One enum for every URL the app knows how to navigate to. Mirrors
 * account/_layout.php and admin/_layout.php so the menus stay in sync
 * with the website without round-tripping the server-rendered chrome.
 */
enum class PortalDestination(
    val key: String,
    val title: String,
    val path: String,
    val icon: ImageVector,
    val group: PortalGroup,
    val audience: Audience,
    val showInBottomBar: Boolean = false
) {
    /* -------- Client portal -------- */
    Dashboard("dashboard", "Dashboard", "/account/", Icons.Filled.Dashboard, PortalGroup.Home, Audience.Client, true),
    Service("service", "Service & equipment", "/account/service.php", Icons.Filled.Wifi, PortalGroup.Service, Audience.Client, true),
    LinkHealth("link-health", "Link health", "/account/link-health.php", Icons.Filled.NetworkCheck, PortalGroup.Service, Audience.Client),
    Invoices("invoices", "Invoices", "/account/invoices.php", Icons.Filled.Receipt, PortalGroup.Billing, Audience.Client, true),
    Payments("payments", "Payments", "/account/payments.php", Icons.Filled.Payments, PortalGroup.Billing, Audience.Client),
    Statement("statement", "Statement", "/account/statement.php", Icons.Filled.Description, PortalGroup.Billing, Audience.Client),
    Tickets("tickets", "Support tickets", "/account/tickets.php", Icons.Filled.SupportAgent, PortalGroup.Support, Audience.Client, true),
    Notifications("notifications", "Notifications", "/account/notifications.php", Icons.Filled.Notifications, PortalGroup.Support, Audience.Client),

    /* -------- Staff (admin panel) -------- */
    StaffDashboard("staff.dashboard", "Dashboard", "/admin/", Icons.Filled.Dashboard, PortalGroup.Home, Audience.Staff, true),
    StaffInstalls("staff.installs", "Installs", "/admin/installs.php", Icons.Filled.Build, PortalGroup.Operations, Audience.Staff, true),
    StaffAlignment("staff.alignment", "Alignment", "/admin/align.php", Icons.Filled.NetworkCheck, PortalGroup.Operations, Audience.Staff, true),
    StaffTickets("staff.tickets", "Tickets", "/admin/tickets.php", Icons.Filled.SupportAgent, PortalGroup.Operations, Audience.Staff, true),
    StaffMap("staff.map", "Network map", "/admin/map.php", Icons.Filled.Map, PortalGroup.Network, Audience.Staff, true),
    StaffNotifications("staff.notifications", "Notifications", "/admin/notifications.php", Icons.Filled.Notifications, PortalGroup.Operations, Audience.Staff),
    StaffClients("staff.clients", "Clients", "/admin/clients.php", Icons.Filled.People, PortalGroup.Operations, Audience.Staff),
    StaffInbox("staff.inbox", "Inbox", "/admin/inbox.php", Icons.Filled.Inbox, PortalGroup.Operations, Audience.Staff),
    StaffOutages("staff.outages", "Outages", "/admin/outages.php", Icons.Filled.Warning, PortalGroup.Network, Audience.Staff),
    StaffSites("staff.sites", "Sites", "/admin/sites.php", Icons.Filled.Map, PortalGroup.Network, Audience.Staff),
    StaffDevices("staff.devices", "Devices", "/admin/devices.php", Icons.Filled.Router, PortalGroup.Network, Audience.Staff),

    /* -------- Shared -------- */
    Profile("profile", "My profile", "/account/profile.php", Icons.Filled.AccountCircle, PortalGroup.Account, Audience.Both, true),
    Password("password", "Change password", "/account/password.php", Icons.Filled.Password, PortalGroup.Account, Audience.Both);

    fun urlOn(host: String): String = "https://$host$path"

    fun isFor(audience: Audience): Boolean =
        this.audience == audience || this.audience == Audience.Both

    companion object {
        /** Map a server role string to the audience whose nav we should render. */
        fun audienceFor(role: String): Audience =
            if (role.isBlank() || role == "client") Audience.Client else Audience.Staff

        /** URL-based audience — the WebView's current path is the most
         *  reliable signal we have: anything under /admin/ is staff,
         *  anything else is client. Used in preference to the server role
         *  fetch so the bar swaps the moment the page loads, with no
         *  dependency on /account/api/whoami.php being reachable. */
        fun audienceForUrl(url: String): Audience {
            val path = runCatching { android.net.Uri.parse(url).path ?: "" }.getOrDefault("")
            return if (path.startsWith("/admin/") || path == "/admin") Audience.Staff
                   else Audience.Client
        }

        /** Bottom bar items for the given audience (left-to-right). */
        fun bottomBarFor(audience: Audience): List<PortalDestination> =
            entries.filter { it.showInBottomBar && it.isFor(audience) }

        /** Resolve the active destination, scoped to the audience so the
         *  shared Profile/Password entries don't get mis-attributed. */
        fun fromUrl(url: String, audience: Audience): PortalDestination? {
            val rawPath = runCatching { android.net.Uri.parse(url).path ?: "" }.getOrDefault("")
            val path = if (rawPath == "/account") "/account/" else rawPath
            return entries
                .filter { it.isFor(audience) }
                .sortedByDescending { it.path.length }
                .firstOrNull { dest ->
                    when (dest) {
                        Dashboard      -> path == "/account/" || path == "/account/index.php"
                        StaffDashboard -> path == "/admin/"   || path == "/admin/index.php"
                        else           -> path.endsWith(dest.path) || path.endsWith(dest.path + "/")
                    }
                }
        }

        /** Default destination for the audience — used when fromUrl returns null. */
        fun defaultFor(audience: Audience): PortalDestination =
            if (audience == Audience.Staff) StaffDashboard else Dashboard
    }
}

enum class PortalGroup(val title: String) {
    Home("Overview"),
    Service("My service"),
    Billing("Billing"),
    Support("Support"),
    Operations("Operations"),
    Network("Network"),
    Account("Account")
}
