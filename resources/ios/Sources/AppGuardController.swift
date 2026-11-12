import Combine
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

    /// Longest wait for a new screen before the shield fades anyway, for a release that changes nothing.
    private static let releaseTimeout: TimeInterval = 0.5

    /// Time after the new screen's tree is published for SwiftUI to draw it under the shield.
    private static let drawDelay: TimeInterval = 0.05

    private var pendingRelease: AnyCancellable?

    private var policy = AppGuardPolicy()
    private var privacyCoverEnabled = false
    private var shieldImage = ShieldImage(path: nil, darkPath: nil, fit: .fill)
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

    func setShieldImage(_ image: ShieldImage) {
        shieldImage = image
    }

    /// Fades the shield once a tree published after the release has been drawn, or after releaseTimeout.
    /// A lock screen releases the shield from mount(), before its first render, so fading at once would
    /// show the screen being locked until the lock screen's tree arrives.
    func releaseShield() {
        policy.releaseShield()

        var released = false
        let release = { [weak self] in
            guard !released else {
                return
            }

            released = true
            self?.pendingRelease = nil
            self?.removeOverlay(tag: Self.shieldTag, animated: true)
        }

        // dropFirst skips the tree already on screen; the next value is the one published after the release.
        pendingRelease = NativeUIBridge.shared.$currentTree
            .dropFirst()
            .first()
            .sink { _ in
                DispatchQueue.main.asyncAfter(deadline: .now() + Self.drawDelay, execute: release)
            }
        DispatchQueue.main.asyncAfter(deadline: .now() + Self.releaseTimeout, execute: release)
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
        let artwork = shieldArtwork()

        for window in windows where window.viewWithTag(Self.shieldTag) == nil {
            install(ShieldView(artwork: artwork), tag: Self.shieldTag, in: window)
        }
    }

    /// The app's own image, else its splash (NativePHP's LaunchImage asset), else its icon, else nothing.
    private func shieldArtwork() -> ShieldView.Artwork? {
        if let path = shieldImage.path, let light = UIImage(contentsOfFile: path) {
            return ShieldView.Artwork(image: Self.adaptive(light: light, darkPath: shieldImage.darkPath), fit: shieldImage.fit, isIcon: false)
        }

        if let splash = UIImage(named: "LaunchImage") {
            return ShieldView.Artwork(image: splash, fit: .fill, isIcon: false)
        }

        if let icon = Self.appIcon() {
            return ShieldView.Artwork(image: icon, fit: .center, isIcon: true)
        }

        return nil
    }

    /// One image that follows the light or dark appearance, like an asset catalog entry.
    private static func adaptive(light: UIImage, darkPath: String?) -> UIImage {
        guard let darkPath, let dark = UIImage(contentsOfFile: darkPath) else {
            return light
        }

        let asset = UIImageAsset()
        asset.register(light, with: UITraitCollection(userInterfaceStyle: .light))
        asset.register(dark, with: UITraitCollection(userInterfaceStyle: .dark))

        return asset.image(with: UITraitCollection.current)
    }

    private static func appIcon() -> UIImage? {
        let info = Bundle.main.infoDictionary
        let primary = (info?["CFBundleIcons"] as? [String: Any])?["CFBundlePrimaryIcon"] as? [String: Any]
        let names = ((primary?["CFBundleIconFiles"] as? [String]) ?? []).reversed() + [primary?["CFBundleIconName"] as? String, "AppIcon"].compactMap { $0 }

        return names.lazy.compactMap { UIImage(named: $0) }.first
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
