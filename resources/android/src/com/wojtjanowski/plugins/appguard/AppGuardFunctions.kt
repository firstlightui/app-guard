package com.wojtjanowski.plugins.appguard

import android.content.ClipData
import android.content.ClipboardManager
import android.os.Handler
import android.os.Looper
import android.os.PersistableBundle
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import org.json.JSONObject
import java.util.UUID

/** Bridge functions called from PHP through nativephp_call(). They run on the PHP thread. */
object AppGuardFunctions {
    /** ClipDescription.EXTRA_IS_SENSITIVE, spelled out because the constant only exists from API 33. */
    private const val EXTRA_IS_SENSITIVE = "android.content.extra.IS_SENSITIVE"

    private val mainHandler by lazy { Handler(Looper.getMainLooper()) }

    /** AppGuard.SetPrivacyCover {"enabled": bool} */
    class SetPrivacyCover(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val enabled = parameters["enabled"] as? Boolean
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "enabled must be a boolean")

            AppGuardController.setPrivacyCover(activity, enabled)

            return BridgeResponse.success(mapOf("enabled" to enabled))
        }
    }

    /** AppGuard.SetShieldTimeout {"seconds": int|null}; null disables the shield. */
    class SetShieldTimeout(@Suppress("UNUSED_PARAMETER") activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val raw = parameters["seconds"]

            val seconds = when {
                raw == null || raw == JSONObject.NULL -> null
                raw is Number && raw.toLong() >= 0 -> raw.toLong()
                else -> return BridgeResponse.error("INVALID_PARAMETERS", "seconds must be a non-negative integer or null")
            }

            AppGuardController.setShieldTimeout(seconds)

            return BridgeResponse.success()
        }
    }

    /** AppGuard.ReleaseShield {} */
    class ReleaseShield(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            AppGuardController.releaseShield(activity)

            return BridgeResponse.success()
        }
    }

    /**
     * AppGuard.CopySensitive {"text": string, "expiresIn": int}
     *
     * Marks the clip sensitive so Android 13+ hides it in the clipboard preview, and clears it after
     * expiresIn seconds if it is still the current clip. Android 10+ hides the clipboard from apps in the
     * background, so a clip that expires while the app is backgrounded stays until the system clears it.
     */
    class CopySensitive(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val text = parameters["text"] as? String
            val expiresIn = (parameters["expiresIn"] as? Number)?.toLong()

            if (text.isNullOrEmpty() || expiresIn == null || expiresIn <= 0) {
                return BridgeResponse.error("INVALID_PARAMETERS", "text and a positive expiresIn are required")
            }

            val clipboard = activity.getSystemService(ClipboardManager::class.java)
            val label = "app-guard:" + UUID.randomUUID()
            val clip = ClipData.newPlainText(label, text).apply {
                description.extras = PersistableBundle().apply { putBoolean(EXTRA_IS_SENSITIVE, true) }
            }

            mainHandler.post {
                clipboard.setPrimaryClip(clip)
                mainHandler.postDelayed({
                    if (clipboard.primaryClipDescription?.label?.toString() == label) {
                        clipboard.clearPrimaryClip()
                    }
                }, expiresIn * 1_000)
            }

            return BridgeResponse.success()
        }
    }
}
