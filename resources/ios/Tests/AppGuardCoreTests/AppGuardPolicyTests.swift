import XCTest
@testable import AppGuardCore

/// The same cases run against the Kotlin policy in AppGuardPolicyTest.kt. Change both together.
final class AppGuardPolicyTests: XCTestCase {
    func testNoReturnWithoutAMatchingBackground() {
        var policy = AppGuardPolicy()

        XCTAssertNil(policy.enterForeground(atMillis: 5_000))
    }

    func testReportsWholeSecondsAndStaysOpenJustUnderTheTimeout() {
        var policy = AppGuardPolicy()
        policy.shieldAfterSeconds = 300

        policy.enterBackground(atMillis: 1_000)

        XCTAssertEqual(policy.enterForeground(atMillis: 300_999), .init(secondsAway: 299, shielded: false))
    }

    func testShieldsAtExactlyTheTimeout() {
        var policy = AppGuardPolicy()
        policy.shieldAfterSeconds = 300

        policy.enterBackground(atMillis: 1_000)

        XCTAssertEqual(policy.enterForeground(atMillis: 301_000), .init(secondsAway: 300, shielded: true))
    }

    func testImmediateTimeoutShieldsEveryReturn() {
        var policy = AppGuardPolicy()
        policy.shieldAfterSeconds = 0

        policy.enterBackground(atMillis: 5_000)

        XCTAssertEqual(policy.enterForeground(atMillis: 5_400), .init(secondsAway: 0, shielded: true))
    }

    func testDisabledShieldNeverShields() {
        var policy = AppGuardPolicy()

        policy.enterBackground(atMillis: 0)

        XCTAssertEqual(policy.enterForeground(atMillis: 3_600_000), .init(secondsAway: 3_600, shielded: false))
    }

    func testShieldStaysUpUntilReleased() {
        var policy = AppGuardPolicy()
        policy.shieldAfterSeconds = 60

        policy.enterBackground(atMillis: 0)
        XCTAssertEqual(policy.enterForeground(atMillis: 120_000), .init(secondsAway: 120, shielded: true))

        policy.enterBackground(atMillis: 130_000)
        XCTAssertEqual(policy.enterForeground(atMillis: 131_000), .init(secondsAway: 1, shielded: true))

        policy.releaseShield()
        XCTAssertFalse(policy.isShieldUp)

        policy.enterBackground(atMillis: 140_000)
        XCTAssertEqual(policy.enterForeground(atMillis: 141_000), .init(secondsAway: 1, shielded: false))
    }

    func testRepeatedBackgroundKeepsTheEarliestTime() {
        var policy = AppGuardPolicy()

        policy.enterBackground(atMillis: 0)
        policy.enterBackground(atMillis: 50_000)

        XCTAssertEqual(policy.enterForeground(atMillis: 70_000), .init(secondsAway: 70, shielded: false))
    }

    func testClockGoingBackwardsCountsAsNoTimeAway() {
        var policy = AppGuardPolicy()
        policy.shieldAfterSeconds = 30

        policy.enterBackground(atMillis: 10_000)

        XCTAssertEqual(policy.enterForeground(atMillis: 5_000), .init(secondsAway: 0, shielded: false))
    }

    func testAReturnConsumesTheBackground() {
        var policy = AppGuardPolicy()

        policy.enterBackground(atMillis: 0)
        _ = policy.enterForeground(atMillis: 1_000)

        XCTAssertNil(policy.enterForeground(atMillis: 2_000))
    }
}
