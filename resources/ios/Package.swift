// swift-tools-version: 5.9
// Builds only the pure shield policy so it can be unit tested off-device.
// NativePHP never copies this file or Tests/ into the app.
import PackageDescription

let package = Package(
    name: "AppGuardCore",
    targets: [
        .target(name: "AppGuardCore", path: "Sources", sources: ["AppGuardPolicy.swift"]),
        .testTarget(name: "AppGuardCoreTests", dependencies: ["AppGuardCore"], path: "Tests/AppGuardCoreTests"),
    ]
)
