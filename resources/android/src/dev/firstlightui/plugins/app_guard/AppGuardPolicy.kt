package dev.firstlightui.plugins.app_guard

/**
 * Decides when the return shield goes up. Pure Kotlin so it can be unit tested on the JVM;
 * AppGuardPolicy.swift implements the same rules for iOS.
 * Times are milliseconds from SystemClock.elapsedRealtime(), which keeps counting while the device sleeps.
 */
class AppGuardPolicy {
    data class Return(val secondsAway: Long, val shielded: Boolean)

    /** Seconds in the background after which the shield goes up on return; null disables the shield. */
    var shieldAfterSeconds: Long? = null

    var isShieldUp: Boolean = false
        private set

    private var backgroundedAt: Long? = null

    /** Records the background time. A repeated call before the app returns keeps the earlier time. */
    fun enterBackground(nowMillis: Long) {
        if (backgroundedAt == null) {
            backgroundedAt = nowMillis
        }
    }

    /** Returns null when no background was recorded since the last return, such as on a cold start. */
    fun enterForeground(nowMillis: Long): Return? {
        val since = backgroundedAt ?: return null
        backgroundedAt = null

        val secondsAway = maxOf(0L, nowMillis - since) / 1_000
        val limit = shieldAfterSeconds

        if (limit != null && secondsAway >= limit) {
            isShieldUp = true
        }

        return Return(secondsAway, isShieldUp)
    }

    fun releaseShield() {
        isShieldUp = false
    }
}
