<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Entity\Mail\Message;

/**
 * "The email `m` wears a label that …", as DQL for a WHERE clause.
 *
 * Asked through the email's own labels rather than as
 * `label MEMBER OF m.labels`. Doctrine writes MEMBER OF as a sub-query over
 * label with the message_label lookup nested inside it, which Postgres cannot
 * flatten: it hashes the whole of message_label for each such condition, and a
 * mail page asks eight counts with one or two of them apiece. This form is a
 * join Postgres plans as a semi-join starting from the label, and the inbox
 * count over 69,000 emails went from 100 ms to 50.
 */
final class WearsLabel
{
    /**
     * @param string $alias     the label's name in $condition; it names the
     *                          sub-query's email too, so unique in the query
     * @param string $condition DQL on that label, such as `binned.role = :role`
     */
    public static function where(string $alias, string $condition): string
    {
        return sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$sEmail JOIN %2$sEmail.labels %2$s WHERE %2$sEmail = m AND %3$s)',
            Message::class,
            $alias,
            $condition,
        );
    }
}
