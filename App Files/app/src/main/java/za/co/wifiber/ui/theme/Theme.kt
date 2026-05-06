package za.co.wifiber.ui.theme

import android.app.Activity
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat

private val LightColors = lightColorScheme(
    primary = BrandPrimary,
    onPrimary = Color.White,
    primaryContainer = BrandSecondary,
    onPrimaryContainer = Color.White,
    secondary = BrandPrimaryDark,
    onSecondary = Color.White,
    tertiary = BrandTertiary,
    onTertiary = Color.Black,
    background = SurfaceLight,
    onBackground = OnSurfaceLight,
    surface = Color.White,
    onSurface = OnSurfaceLight,
    error = BrandError,
    onError = Color.White
)

private val DarkColors = darkColorScheme(
    primary = BrandSecondary,
    onPrimary = Color.Black,
    primaryContainer = BrandPrimaryDark,
    onPrimaryContainer = Color.White,
    secondary = BrandPrimary,
    onSecondary = Color.White,
    tertiary = BrandTertiary,
    onTertiary = Color.Black,
    background = SurfaceDark,
    onBackground = OnSurfaceDark,
    surface = Color(0xFF1E293B),
    onSurface = OnSurfaceDark,
    error = BrandError,
    onError = Color.White
)

@Composable
fun WiFiberTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit
) {
    val colorScheme = if (darkTheme) DarkColors else LightColors
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            val window = (view.context as Activity).window
            window.statusBarColor = colorScheme.primary.toArgb()
            WindowCompat.getInsetsController(window, view)
                .isAppearanceLightStatusBars = false
        }
    }
    MaterialTheme(
        colorScheme = colorScheme,
        typography = Typography,
        content = content
    )
}
