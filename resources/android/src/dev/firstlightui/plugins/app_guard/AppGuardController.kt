package dev.firstlightui.plugins.app_guard

import android.app.Activity
import android.app.Application
import android.content.Context
import android.content.res.Configuration
import android.graphics.Color
import android.os.Bundle
import android.os.SystemClock
import android.util.TypedValue
import android.view.Choreographer
import android.view.View
import android.view.ViewGroup
import android.view.WindowManager
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.nativephp.mobile.ui.nativerender.NativeUIBridge
import org.json.JSONObject

/** Called by NativePHP's generated plugin registration each time MainActivity is created. */
fun appGuardInit(context: Context) {
    val application = context.applicationContext as? Application ?: return
    AppGuardController.start(application)
}

/**
 * Owns lifecycle tracking, FLAG_SECURE and the return shield.
 *
 * Lifecycle callbacks run on the main thread. Bridge functions run on the PHP thread, so the policy is
 * guarded by its own lock and window work goes through runOnUiThread.
 */
object AppGuardController : Application.ActivityLifecycleCallbacks {
    private const val SHIELD_TAG = "app-guard-shield"
    private const val BACKGROUNDED_EVENT = "FirstlightUI\\AppGuard\\Events\\Backgrounded"
    private const val FOREGROUNDED_EVENT = "FirstlightUI\\AppGuard\\Events\\Foregrounded"

    /** Longest wait for a new screen before the shield fades anyway, for a release that changes nothing. */
    private const val RELEASE_TIMEOUT_MS = 1_000L

    /**
     * How long the shield stays up after the new screen's tree is published: NativePHP's stack slides
     * screens in over 250 ms, and the screen being replaced shows until the slide ends.
     */
    private const val SETTLE_AFTER_PUBLICATION_MS = 300L

    private val policy = AppGuardPolicy()
    private var registered = false
    private var startedActivities = 0

    @Volatile
    private var privacyCoverEnabled = false

    @Volatile
    private var shieldImage = ShieldImage(null, null, ShieldFit.FILL)

    @Synchronized
    fun start(application: Application) {
        if (registered) {
            return
        }

        registered = true
        application.registerActivityLifecycleCallbacks(this)
    }

    fun setPrivacyCover(activity: Activity, enabled: Boolean) {
        privacyCoverEnabled = enabled
        activity.runOnUiThread { applySecureFlag(activity) }
    }

    fun setShieldTimeout(seconds: Long?) {
        synchronized(policy) { policy.shieldAfterSeconds = seconds }
    }

    fun setShieldImage(image: ShieldImage) {
        shieldImage = image
    }

    /**
     * Read on the PHP thread, so the publication count predates the tree the caller is about to
     * publish: a lock screen releases the shield from mount(), before its first render.
     */
    fun releaseShield(activity: Activity) {
        synchronized(policy) { policy.releaseShield() }
        val publication = NativeUIBridge.treePublicationId.longValue
        activity.runOnUiThread { removeShield(activity, publication) }
    }

    override fun onActivityStarted(activity: Activity) {
        applySecureFlag(activity)

        val result = if (startedActivities == 0) {
            synchronized(policy) { policy.enterForeground(SystemClock.elapsedRealtime()) }
        } else {
            null
        }

        startedActivities++

        if (synchronized(policy) { policy.isShieldUp }) {
            addShield(activity)
        }

        if (result != null) {
            val payload = JSONObject()
                .put("secondsAway", result.secondsAway)
                .put("shielded", result.shielded)
            NativeElementBridge.sendNativeEvent(FOREGROUNDED_EVENT, payload.toString())
        }
    }

    override fun onActivityStopped(activity: Activity) {
        startedActivities = maxOf(0, startedActivities - 1)

        if (startedActivities == 0 && !activity.isChangingConfigurations) {
            synchronized(policy) { policy.enterBackground(SystemClock.elapsedRealtime()) }
            NativeElementBridge.sendNativeEvent(BACKGROUNDED_EVENT, "{}")
        }
    }

    override fun onActivityResumed(activity: Activity) {
        applySecureFlag(activity)
    }

    override fun onActivityCreated(activity: Activity, savedInstanceState: Bundle?) {}

    override fun onActivityPaused(activity: Activity) {}

    override fun onActivitySaveInstanceState(activity: Activity, outState: Bundle) {}

    override fun onActivityDestroyed(activity: Activity) {}

    private fun applySecureFlag(activity: Activity) {
        if (privacyCoverEnabled) {
            activity.window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        } else {
            activity.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
        }
    }

    private fun addShield(activity: Activity) {
        val decor = activity.window.decorView as ViewGroup

        if (decor.findViewWithTag<View>(SHIELD_TAG) != null) {
            return
        }

        val shield = ShieldView.make(activity, shieldImage, backgroundColor(activity)).apply {
            tag = SHIELD_TAG
            isClickable = true
            isFocusable = true
            translationZ = 1_000f
        }

        decor.addView(shield, ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
    }

    /**
     * Fades the shield once a tree published after [publication] has slid into place, or after
     * RELEASE_TIMEOUT_MS. Fading at once showed the screen being locked for a few frames, because the
     * lock screen's tree reaches the renderer 200 to 300 ms after the release and then slides in.
     *
     * Clears the tag first so a shield requested meanwhile is added fresh instead of being skipped.
     */
    private fun removeShield(activity: Activity, publication: Long) {
        val decor = activity.window.decorView as ViewGroup
        val shield = decor.findViewWithTag<View>(SHIELD_TAG) ?: return

        shield.tag = null
        var deadline = SystemClock.uptimeMillis() + RELEASE_TIMEOUT_MS
        var published = false

        Choreographer.getInstance().postFrameCallback(object : Choreographer.FrameCallback {
            override fun doFrame(frameTimeNanos: Long) {
                val now = SystemClock.uptimeMillis()

                if (!published && NativeUIBridge.treePublicationId.longValue != publication) {
                    published = true
                    deadline = minOf(deadline, now + SETTLE_AFTER_PUBLICATION_MS)
                }

                if (now < deadline) {
                    Choreographer.getInstance().postFrameCallback(this)

                    return
                }

                shield.animate().alpha(0f).setDuration(200).withEndAction { decor.removeView(shield) }.start()
            }
        })
    }

    private fun backgroundColor(activity: Activity): Int {
        val value = TypedValue()
        val resolved = activity.theme.resolveAttribute(android.R.attr.colorBackground, value, true)

        if (resolved && value.type in TypedValue.TYPE_FIRST_COLOR_INT..TypedValue.TYPE_LAST_COLOR_INT) {
            return value.data
        }

        val night = (activity.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES

        return if (night) Color.BLACK else Color.WHITE
    }
}
