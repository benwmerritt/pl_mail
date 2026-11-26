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

    public function testOnlyAFortnightIsKept(): void
    {
        $user  = new User();
        $older = [];

        for ($day = 1; $day <= 20; ++$day) {
            $older[] = sprintf('2026-07-%02d', $day);
        }

        $user->setSetting(InboxZeroDays::SETTING, $older);
        $this->days('2026-08-10T09:00:00+00:00')->record($user);

        $kept = $user->getSetting(InboxZeroDays::SETTING);

        self::assertIsArray($kept);
        self::assertCount(14, $kept);
        self::assertSame('2026-08-10', $kept[0]);
        self::assertSame('2026-07-08', $kept[13]);
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
