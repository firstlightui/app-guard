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
