import UIKit
import UniformTypeIdentifiers

/// Bridge functions called from PHP through nativephp_call(). They run on the PHP thread,
/// so anything touching UIKit hops to the main queue.
enum AppGuardFunctions {

    /// AppGuard.SetPrivacyCover {"enabled": bool}
    class SetPrivacyCover: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let enabled = parameters["enabled"] as? Bool else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "enabled must be a boolean")
            }

            DispatchQueue.main.async {
                AppGuardController.shared.setPrivacyCover(enabled)
            }

            return BridgeResponse.success(data: ["enabled": enabled])
        }
    }

    /// AppGuard.SetShieldTimeout {"seconds": int|null}; null disables the shield.
    class SetShieldTimeout: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let value = parameters["seconds"]
            let seconds: Int?

            if value == nil || value is NSNull {
                seconds = nil
            } else if let number = value as? Int, number >= 0 {
                seconds = number
            } else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "seconds must be a non-negative integer or null")
            }

            DispatchQueue.main.async {
                AppGuardController.shared.setShieldTimeout(seconds)
            }

            return BridgeResponse.success()
        }
    }

    /// AppGuard.ReleaseShield {}
    class ReleaseShield: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            DispatchQueue.main.async {
                AppGuardController.shared.releaseShield()
            }

            return BridgeResponse.success()
        }
    }

    /// AppGuard.SetShieldImage {"image": string|null, "imageDark": string|null, "fit": "fill"|"center"}
    /// Paths are absolute. A null image uses the splash screen, then the app icon.
    class SetShieldImage: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let image = optionalString(parameters["image"]), let imageDark = optionalString(parameters["imageDark"]),
                  let fit = ShieldFit(rawValue: parameters["fit"] as? String ?? ShieldFit.fill.rawValue) else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "image and imageDark must be strings or null, and fit fill or center")
            }

            DispatchQueue.main.async {
                AppGuardController.shared.setShieldImage(ShieldImage(path: image, darkPath: imageDark, fit: fit))
            }

            return BridgeResponse.success()
        }

        /// .some(nil) for a missing or null value, nil for a value of the wrong type.
        private func optionalString(_ value: Any?) -> String?? {
            if value == nil || value is NSNull {
                return .some(nil)
            }

            return (value as? String).map { .some($0) }
        }
    }

    /// AppGuard.CopySensitive {"text": string, "expiresIn": int}
    /// localOnly keeps the text out of Universal Clipboard; the expiration removes it after expiresIn seconds.
    class CopySensitive: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let text = parameters["text"] as? String, !text.isEmpty,
                  let expiresIn = parameters["expiresIn"] as? Int, expiresIn > 0 else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "text and a positive expiresIn are required")
            }

            DispatchQueue.main.async {
                UIPasteboard.general.setItems(
                    [[UTType.utf8PlainText.identifier: text]],
                    options: [
                        .localOnly: true,
                        .expirationDate: Date().addingTimeInterval(TimeInterval(expiresIn)),
                    ]
                )
            }

            return BridgeResponse.success()
        }
    }
}
