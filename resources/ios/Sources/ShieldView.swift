import UIKit

/// How the shield draws its image. `fill` covers the screen and crops, like the splash screen;
/// `center` fits the image in a square of `centerSide` points in the middle of the system background.
enum ShieldFit: String {
    case fill
    case center
}

/// What AppGuard.SetShieldImage last set. Paths are absolute files under the app's public/ directory.
struct ShieldImage {
    let path: String?
    let darkPath: String?
    let fit: ShieldFit
}

/// The opaque view the return shield puts over every window.
final class ShieldView: UIView {
    struct Artwork {
        let image: UIImage
        let fit: ShieldFit
        /// The app icon gets the system's rounded-square corners, as on the home screen.
        let isIcon: Bool
    }

    static let centerSide: CGFloat = 120

    private let fit: ShieldFit
    private let imageView = UIImageView()

    init(artwork: Artwork?) {
        fit = artwork?.fit ?? .fill
        super.init(frame: .zero)

        backgroundColor = .systemBackground
        accessibilityElementsHidden = true

        guard let artwork else {
            return
        }

        imageView.image = artwork.image
        imageView.clipsToBounds = true
        imageView.contentMode = artwork.fit == .fill ? .scaleAspectFill : .scaleAspectFit

        if artwork.isIcon {
            imageView.layer.cornerRadius = Self.centerSide * 0.2237
            imageView.layer.cornerCurve = .continuous
        }

        addSubview(imageView)
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) {
        fatalError("init(coder:) is not supported")
    }

    override func layoutSubviews() {
        super.layoutSubviews()

        switch fit {
        case .fill:
            imageView.frame = bounds
        case .center:
            let side = min(Self.centerSide, bounds.width, bounds.height)
            imageView.frame = CGRect(x: (bounds.width - side) / 2, y: (bounds.height - side) / 2, width: side, height: side)
        }
    }
}
