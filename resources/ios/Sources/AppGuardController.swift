import UIKit

/// Called once at launch by NativePHP's generated plugin registration.
func appGuardInit() {
    AppGuardController.shared.start()
}

/// Owns the lifecycle observers, the privacy blur and the return shield.
/// Use only on the main thread; bridge functions hop to it with DispatchQueue.main.async.
final class AppGuardController {
    static let shared = AppGuardController()

    private static let blurTag = 0x4147_424C
    private static let shieldTag = 0x4147_5348
    private static let backgroundedEvent = "FirstlightUI\\AppGuard\\Events\\Backgrounded"
    private static let foregroundedEvent = "FirstlightUI\\AppGuard\\Events\\Foregrounded"

    private var policy = AppGuardPolicy()
    private var privacyCoverEnabled = false
    private var started = false

    func start() {
        guard !started else {
            return
        }

        started = true
        let center = NotificationCenter.default

        center.addObserver(forName: UIApplication.willResignActiveNotification, object: nil, queue: .main) { [weak self] _ in
            self?.willResignActive()
        }
        center.addObserver(forName: UIApplication.didEnterBackgroundNotification, object: nil, queue: .main) { [weak self] _ in
            self?.didEnterBackground()
        }
        center.addObserver(forName: UIApplication.willEnterForegroundNotification, object: nil, queue: .main) { [weak self] _ in
            self?.willEnterForeground()
        }
        center.addObserver(forName: UIApplication.didBecomeActiveNotification, object: nil, queue: .main) { [weak self] _ in
            self?.didBecomeActive()
        }
    }

    func setPrivacyCover(_ enabled: Bool) {
        privacyCoverEnabled = enabled

        if !enabled {
            removeOverlay(tag: Self.blurTag, animated: false)
        }
    }

    func setShieldTimeout(_ seconds: Int?) {
        policy.shieldAfterSeconds = seconds
    }

    func releaseShield() {
        policy.releaseShield()
        removeOverlay(tag: Self.shieldTag, animated: true)
    }

    private func willResignActive() {
        if privacyCoverEnabled {
            addBlur()
        }
    }

    private func didEnterBackground() {
        if privacyCoverEnabled {
            addBlur()
        }

        policy.enterBackground(atMillis: Self.nowMillis())
        LaravelBridge.shared.send?(Self.backgroundedEvent, [:])
    }

    private func willEnterForeground() {
        guard let result = policy.enterForeground(atMillis: Self.nowMillis()) else {
            return
        }

        if result.shielded {
            addShield()
        }

        LaravelBridge.shared.send?(Self.foregroundedEvent, [
            "secondsAway": result.secondsAway,
            "shielded": result.shielded,
        ])
    }

    private func didBecomeActive() {
        removeOverlay(tag: Self.blurTag, animated: true)
    }

    private func addBlur() {
        for window in windows where window.viewWithTag(Self.blurTag) == nil {
            install(UIVisualEffectView(effect: UIBlurEffect(style: .systemMaterial)), tag: Self.blurTag, in: window)
        }
    }

    private func addShield() {
        for window in windows where window.viewWithTag(Self.shieldTag) == nil {
            let shield = UIView()
            shield.backgroundColor = .systemBackground
            install(shield, tag: Self.shieldTag, in: window)
        }
    }

    private func install(_ view: UIView, tag: Int, in window: UIWindow) {
        view.tag = tag
        view.frame = window.bounds
        view.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        window.addSubview(view)
    }

    /// Clears the tag before fading so a cover requested mid-fade is added fresh instead of being skipped.
    private func removeOverlay(tag: Int, animated: Bool) {
        for window in windows {
            guard let view = window.viewWithTag(tag) else {
                continue
            }

            view.tag = 0

            if animated {
                UIView.animate(withDuration: 0.2, animations: { view.alpha = 0 }, completion: { _ in view.removeFromSuperview() })
            } else {
                view.removeFromSuperview()
            }
        }
    }

    private var windows: [UIWindow] {
        UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .flatMap(\.windows)
    }

    /// CLOCK_MONOTONIC on Darwin keeps counting while the device sleeps, unlike systemUptime.
    private static func nowMillis() -> Int64 {
        Int64(clock_gettime_nsec_np(CLOCK_MONOTONIC) / 1_000_000)
    }
}
