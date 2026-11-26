<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\User\User;
use App\Service\User\UserTimezoneResolver;
use Symfony\Component\Clock\ClockInterface;

/**
 * The days this week the inbox was emptied (BMAIL-15), for the strip under
 * the inbox-zero picture.
 *
 * "Reached zero" is the user's own calendar day, in their timezone, on which
 * the inbox they read as their inbox (Primary, or the one list with the tabs
 * off) had nothing left in it. Kept as dates in the user's settings bag rather
 * than a table: a fortnight of short strings, written at most once a day.
 *
 * A day is recorded by the page, not here: the list empties in the browser
 * when the last row is archived, and only the browser sees that moment.
 */
final readonly class InboxZeroDays
{
    public const string SETTING = 'mail.inbox_zero_days';

    /** Shown: today and the six before it. */
    public const int SHOWN = 7;

    /** Kept: twice what is shown, so the strip never loses a day it draws. */
    private const int KEPT = 14;

    public function __construct(
        private UserTimezoneResolver $zones,
        private ClockInterface $clock,
    ) {
    }

    /** Today, in the user's calendar, is a day the inbox reached zero. Idempotent. */
    public function record(User $user): void
    {
        $today = $this->today($user)->format('Y-m-d');
        $days  = $this->days($user);

        if (true === in_array($today, $days, true)) {
            return;
        }

        $days[] = $today;
        rsort($days);

        $user->setSetting(self::SETTING, array_slice($days, 0, self::KEPT));
    }

    /**
     * The last seven days, oldest first, each with whether the inbox reached zero on it.
     *
     * @return list<array{initial: string, name: string, reached: bool, today: bool}>
     */
    public function week(User $user, string $locale): array
    {
        $today   = $this->today($user);
        $reached = array_flip($this->days($user));
        $zone    = $today->getTimezone();
        $initial = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, null, 'EEEEE');
        $name    = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, $zone);
        $week    = [];

        for ($back = self::SHOWN - 1; $back >= 0; --$back) {
            $day = $today->modify(sprintf('-%d days', $back));

            $week[] = [
                'initial' => (string) $initial->format($day),
                'name'    => (string) $name->format($day),
                'reached' => true === array_key_exists($day->format('Y-m-d'), $reached),
                'today'   => 0 === $back,
            ];
        }

        return $week;
    }

    private function today(User $user): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->zones->resolve($user))->setTime(12, 0);
    }

    /** @return list<string> */
    private function days(User $user): array
    {
        $stored = $user->getSetting(self::SETTING, []);

        return array_values(array_filter(
            true === is_array($stored) ? $stored : [],
            static fn (mixed $day): bool => true === is_string($day) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $day),
        ));
    }
}
