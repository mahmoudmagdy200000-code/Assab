<?php

namespace App\Support;

/**
 * The one generator for every password the system MAILS to a human.
 *
 * `Str::password()` draws symbols from a wide set, so it happily produced
 * `%OP43zSVf}-h` — which the mobile login screen then refuses to accept, because
 * the app enforces «8+ characters, a-z, A-Z, 0-9 and one of $ & @» on the field
 * itself and leaves the Log In button disabled. The emailed credential was
 * therefore un-typeable and the account unusable (reported 2026-08-04).
 *
 * Guarantees, by construction rather than by retry:
 *  - length >= 8 (default 12);
 *  - at least one lowercase, one uppercase, one digit and one of `$ & @`;
 *  - no other punctuation, so nothing the app rejects — and nothing that breaks
 *    when the password travels through a URL, a shell or an Excel cell;
 *  - no look-alike characters (0/O, 1/l/I), which is where the "the password in
 *    the email does not work" support tickets actually come from.
 */
final class TemporaryPassword
{
    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';   // no l

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';    // no I, O

    private const DIGITS = '23456789';                   // no 0, 1

    /** The exact set the app accepts — do not widen without the app widening first. */
    private const SYMBOLS = '$&@';

    public static function generate(int $length = 12): string
    {
        $length = max(8, $length);

        // One guaranteed character per required class first…
        $chars = [
            self::pick(self::LOWER),
            self::pick(self::UPPER),
            self::pick(self::DIGITS),
            self::pick(self::SYMBOLS),
        ];

        // …then fill the rest from the whole pool.
        $pool = self::LOWER.self::UPPER.self::DIGITS.self::SYMBOLS;
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = self::pick($pool);
        }

        // Fisher-Yates with a CSPRNG: str_shuffle() uses the seeded Mt19937
        // generator and is not safe for secrets.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** True when a password would be accepted by the mobile login screen. */
    public static function satisfiesPolicy(string $password): bool
    {
        return mb_strlen($password) >= 8
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1
            && preg_match('/[$&@]/', $password) === 1;
    }

    private static function pick(string $set): string
    {
        return $set[random_int(0, strlen($set) - 1)];
    }
}
