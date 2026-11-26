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
 * Only the days the strip draws are kept: a week of short strings, written
 * at most once a day, by MailController::inbox() when it counts Primary empty.
 */
final readonly class InboxZeroDays
{
    public const string SETTING = 'mail.inbox_zero_days';

    /** Shown and kept: today and the six before it. */
    public const int SHOWN = 7;

    public function __construct(
        private UserTimezoneResolver $zones,
        private ClockInterface $clock,
    ) {
    }

    /** Today, in the user's calendar, is a day the inbox reached zero. Idempotent. */
    public function record(User $user): void
    {
        $today = $this->today($user);
        $date  = $today->format('Y-m-d');
        $days  = $this->days($user);

        if (true === in_array($date, $days, true)) {
            return;
        }

        $oldest = $today->modify(sprintf('-%d days', self::SHOWN - 1))->format('Y-m-d');
        $days   = array_filter([$date, ...$days], static fn (string $day): bool => $day >= $oldest);
        rsort($days);

        $user->setSetting(self::SETTING, $days);
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
