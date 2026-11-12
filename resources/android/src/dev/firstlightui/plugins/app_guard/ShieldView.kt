package dev.firstlightui.plugins.app_guard

import android.app.Activity
import android.content.res.Configuration
import android.graphics.BitmapFactory
import android.graphics.drawable.BitmapDrawable
import android.graphics.drawable.Drawable
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.widget.FrameLayout
import android.widget.ImageView

/**
 * How the shield draws its image. FILL covers the screen and crops, like the splash screen; CENTER fits
 * the image in a square of CENTER_SIDE_DP in the middle of the system background.
 */
enum class ShieldFit {
    FILL,
    CENTER;

    companion object {
        fun parse(value: String): ShieldFit? = entries.firstOrNull { it.name.equals(value, ignoreCase = true) }
    }
}

/** What AppGuard.SetShieldImage last set. Paths are absolute files under the app's public/ directory. */
data class ShieldImage(val path: String?, val darkPath: String?, val fit: ShieldFit)

internal object ShieldView {
    private const val CENTER_SIDE_DP = 120f

    private class Artwork(val drawable: Drawable, val fit: ShieldFit)

    /** The opaque shield, with the app's image, else its splash, else its icon, else no image. */
    fun make(activity: Activity, image: ShieldImage, backgroundColor: Int): View {
        val shield = FrameLayout(activity).apply {
            setBackgroundColor(backgroundColor)
            importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO_HIDE_DESCENDANTS
        }

        val artwork = custom(activity, image) ?: splash(activity) ?: icon(activity) ?: return shield

        val view = ImageView(activity).apply {
            setImageDrawable(artwork.drawable)
            scaleType = if (artwork.fit == ShieldFit.FILL) ImageView.ScaleType.CENTER_CROP else ImageView.ScaleType.FIT_CENTER
        }

        val params = if (artwork.fit == ShieldFit.FILL) {
            FrameLayout.LayoutParams(FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT)
        } else {
            val side = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, CENTER_SIDE_DP, activity.resources.displayMetrics).toInt()
            FrameLayout.LayoutParams(side, side, Gravity.CENTER)
        }

        shield.addView(view, params)

        return shield
    }

    private fun custom(activity: Activity, image: ShieldImage): Artwork? {
        val light = image.path ?: return null
        val night = (activity.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
        val candidates = listOfNotNull(image.darkPath.takeIf { night }, light)
        val drawable = candidates.firstNotNullOfOrNull { decode(activity, it) } ?: return null

        return Artwork(drawable, image.fit)
    }

    /** NativePHP builds public/splash.png and splash-dark.png into the `splash` drawable, with a night variant. */
    private fun splash(activity: Activity): Artwork? {
        val id = activity.resources.getIdentifier("splash", "drawable", activity.packageName)

        if (id == 0) {
            return null
        }

        return runCatching { activity.getDrawable(id) }.getOrNull()?.let { Artwork(it, ShieldFit.FILL) }
    }

    /** The launcher icon. Adaptive icons draw in the system's mask shape. */
    private fun icon(activity: Activity): Artwork? =
        runCatching { activity.packageManager.getApplicationIcon(activity.applicationInfo) }.getOrNull()
            ?.let { Artwork(it, ShieldFit.CENTER) }

    /** Decodes on the main thread, before the first frame, sampled down to about the screen size. */
    private fun decode(activity: Activity, path: String): Drawable? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(path, bounds)

        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) {
            return null
        }

        val metrics = activity.resources.displayMetrics
        val target = maxOf(metrics.widthPixels, metrics.heightPixels)
        var sample = 1

        while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= target) {
            sample *= 2
        }

        val bitmap = BitmapFactory.decodeFile(path, BitmapFactory.Options().apply { inSampleSize = sample }) ?: return null

        return BitmapDrawable(activity.resources, bitmap)
    }
}
