package com.wojtjanowski.plugins.appguard

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNull

/** The same cases run against the Swift policy in AppGuardPolicyTests.swift. Change both together. */
class AppGuardPolicyTest {
    @Test
    fun noReturnWithoutAMatchingBackground() {
        val policy = AppGuardPolicy()

        assertNull(policy.enterForeground(5_000))
    }

    @Test
    fun reportsWholeSecondsAndStaysOpenJustUnderTheTimeout() {
        val policy = AppGuardPolicy().apply { shieldAfterSeconds = 300 }

        policy.enterBackground(1_000)

        assertEquals(AppGuardPolicy.Return(299, false), policy.enterForeground(300_999))
    }

    @Test
    fun shieldsAtExactlyTheTimeout() {
        val policy = AppGuardPolicy().apply { shieldAfterSeconds = 300 }

        policy.enterBackground(1_000)

        assertEquals(AppGuardPolicy.Return(300, true), policy.enterForeground(301_000))
    }

    @Test
    fun immediateTimeoutShieldsEveryReturn() {
        val policy = AppGuardPolicy().apply { shieldAfterSeconds = 0 }

        policy.enterBackground(5_000)

        assertEquals(AppGuardPolicy.Return(0, true), policy.enterForeground(5_400))
    }

    @Test
    fun disabledShieldNeverShields() {
        val policy = AppGuardPolicy()

        policy.enterBackground(0)

        assertEquals(AppGuardPolicy.Return(3_600, false), policy.enterForeground(3_600_000))
    }

    @Test
    fun shieldStaysUpUntilReleased() {
        val policy = AppGuardPolicy().apply { shieldAfterSeconds = 60 }

        policy.enterBackground(0)
        assertEquals(AppGuardPolicy.Return(120, true), policy.enterForeground(120_000))

        policy.enterBackground(130_000)
        assertEquals(AppGuardPolicy.Return(1, true), policy.enterForeground(131_000))

        policy.releaseShield()
        assertFalse(policy.isShieldUp)

        policy.enterBackground(140_000)
        assertEquals(AppGuardPolicy.Return(1, false), policy.enterForeground(141_000))
    }

    @Test
    fun repeatedBackgroundKeepsTheEarliestTime() {
        val policy = AppGuardPolicy()

        policy.enterBackground(0)
        policy.enterBackground(50_000)

        assertEquals(AppGuardPolicy.Return(70, false), policy.enterForeground(70_000))
    }

    @Test
    fun clockGoingBackwardsCountsAsNoTimeAway() {
        val policy = AppGuardPolicy().apply { shieldAfterSeconds = 30 }

        policy.enterBackground(10_000)

        assertEquals(AppGuardPolicy.Return(0, false), policy.enterForeground(5_000))
    }

    @Test
    fun aReturnConsumesTheBackground() {
        val policy = AppGuardPolicy()

        policy.enterBackground(0)
        policy.enterForeground(1_000)

        assertNull(policy.enterForeground(2_000))
    }
}
