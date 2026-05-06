package za.co.wifiber.nav

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material.icons.filled.Article
import androidx.compose.material.icons.filled.CreditCard
import androidx.compose.material.icons.filled.Dashboard
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Logout
import androidx.compose.material.icons.filled.NetworkCheck
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Password
import androidx.compose.material.icons.filled.Payments
import androidx.compose.material.icons.filled.Receipt
import androidx.compose.material.icons.filled.SignalWifi4Bar
import androidx.compose.material.icons.filled.SupportAgent
import androidx.compose.material.icons.filled.Wifi
import androidx.compose.ui.graphics.vector.ImageVector

/**
 * Mirrors the customer-portal navigation in account/_layout.php so the
 * app's menus stay in sync with the website without round-tripping the
 * page chrome.
 */
enum class PortalDestination(
    val key: String,
    val title: String,
    val path: String,
    val icon: ImageVector,
    val group: PortalGroup,
    val showInBottomBar: Boolean = false
) {
    Dashboard("dashboard", "Dashboard", "/account/", Icons.Filled.Dashboard, PortalGroup.Home, true),
    Service("service", "Service & equipment", "/account/service.php", Icons.Filled.Wifi, PortalGroup.Service, true),
    LinkHealth("link-health", "Link health", "/account/link-health.php", Icons.Filled.NetworkCheck, PortalGroup.Service),
    Invoices("invoices", "Invoices", "/account/invoices.php", Icons.Filled.Receipt, PortalGroup.Billing, true),
    Payments("payments", "Payments", "/account/payments.php", Icons.Filled.Payments, PortalGroup.Billing),
    Statement("statement", "Statement", "/account/statement.php", Icons.Filled.Description, PortalGroup.Billing),
    Tickets("tickets", "Support tickets", "/account/tickets.php", Icons.Filled.SupportAgent, PortalGroup.Support, true),
    Notifications("notifications", "Notifications", "/account/notifications.php", Icons.Filled.Notifications, PortalGroup.Support),
    Profile("profile", "My profile", "/account/profile.php", Icons.Filled.AccountCircle, PortalGroup.Account, true),
    Password("password", "Change password", "/account/password.php", Icons.Filled.Password, PortalGroup.Account);

    fun urlOn(host: String): String = "https://$host$path"

    companion object {
        val bottomBar: List<PortalDestination> = entries.filter { it.showInBottomBar }

        fun fromUrl(url: String): PortalDestination? {
            val path = runCatching { android.net.Uri.parse(url).path ?: "" }.getOrDefault("")
            val normalised = if (path == "/account") "/account/" else path
            // Longest path wins so "/account/invoices.php" doesn't match "/account/".
            return entries
                .sortedByDescending { it.path.length }
                .firstOrNull { dest ->
                    if (dest == Dashboard) normalised == "/account/" || normalised == "/account/index.php"
                    else normalised.endsWith(dest.path) || normalised.endsWith(dest.path + "/")
                }
        }
    }
}

enum class PortalGroup(val title: String) {
    Home("Overview"),
    Service("My service"),
    Billing("Billing"),
    Support("Support"),
    Account("Account")
}
