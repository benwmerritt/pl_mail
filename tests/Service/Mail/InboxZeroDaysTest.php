<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\User\User;
use App\Service\Mail\InboxZeroDays;
use App\Service\User\UserTimezoneResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Which days the inbox-zero strip fills in (BMAIL-15).
 *
 * The day is the user's own calendar day, so the clock below sits where UTC
 * and Berlin disagree about the date: 23:30 UTC on 10 August is already the
 * 11th in Berlin.
 */
final class InboxZeroDaysTest extends TestCase
{
    public function testTodayIsTheUsersCalendarDay(): void
    {
        $user = new User();

        $this->days('2026-08-10T23:30:00+00:00')->record($user);

        self::assertSame(['2026-08-11'], $user->getSetting(InboxZeroDays::SETTING));
    }

    public function testRecordingTwiceInADayKeepsOneDay(): void
    {
        $user = new User();
        $days = $this->days('2026-08-10T09:00:00+00:00');

        $days->record($user);
        $days->record($user);

        self::assertSame(['2026-08-10'], $user->getSetting(InboxZeroDays::SETTING));
    }

    public function testOnlyTheWeekTheStripDrawsIsKept(): void
    {
        $user = new User();
        $user->setSetting(InboxZeroDays::SETTING, ['2026-08-09', '2026-08-04', '2026-08-03', '2026-07-20']);

        $this->days('2026-08-10T09:00:00+00:00')->record($user);

        self::assertSame(['2026-08-10', '2026-08-09', '2026-08-04'], $user->getSetting(InboxZeroDays::SETTING));
    }

    public function testTheWeekRunsOldestFirstAndEndsToday(): void
    {
        $user = new User();
        $user->setSetting(InboxZeroDays::SETTING, ['2026-08-10', '2026-08-04', '2026-08-03', 'not a day']);

        $week = $this->days('2026-08-10T09:00:00+00:00')->week($user, 'en');

        self::assertCount(InboxZeroDays::SHOWN, $week);
        self::assertSame(
            [true, false, false, false, false, false, true],
            array_column($week, 'reached'),
            '4 August is the first of the seven; 3 August is outside them.',
        );
        self::assertSame([false, false, false, false, false, false, true], array_column($week, 'today'));
        self::assertSame('T', $week[0]['initial'], '4 August 2026 is a Tuesday.');
        self::assertStringContainsString('August', $week[0]['name']);
    }

    private function days(string $now): InboxZeroDays
    {
        return new InboxZeroDays(new UserTimezoneResolver('Europe/Berlin'), new MockClock($now));
    }
}
