/// Decides when the return shield goes up. A pure value type so it can be unit tested off-device;
/// AppGuardPolicy.kt implements the same rules for Android.
/// Times are milliseconds on a monotonic clock that keeps counting while the device sleeps.
struct AppGuardPolicy {
    struct Return: Equatable {
        let secondsAway: Int
        let shielded: Bool
    }

    /// Seconds in the background after which the shield goes up on return; nil disables the shield.
    var shieldAfterSeconds: Int?

    private(set) var isShieldUp = false

    private var backgroundedAt: Int64?

    /// Records the background time. A repeated call before the app returns keeps the earlier time.
    mutating func enterBackground(atMillis now: Int64) {
        if backgroundedAt == nil {
            backgroundedAt = now
        }
    }

    /// Returns nil when no background was recorded since the last return, such as on a cold start.
    mutating func enterForeground(atMillis now: Int64) -> Return? {
        guard let since = backgroundedAt else {
            return nil
        }

        backgroundedAt = nil
        let secondsAway = Int(max(0, now - since) / 1_000)

        if let limit = shieldAfterSeconds, secondsAway >= limit {
            isShieldUp = true
        }

        return Return(secondsAway: secondsAway, shielded: isShieldUp)
    }

    mutating func releaseShield() {
        isShieldUp = false
    }
}
