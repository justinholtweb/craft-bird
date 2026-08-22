<?php

namespace justinholtweb\bird\helpers;

/**
 * Money on the wire.
 *
 * Moneybird accepts numbers as JSON numbers or as strings and answers with strings (`"363.0"`).
 * Everything Bird sends goes out as a string with a plain `.` decimal separator: a float encoded
 * by `json_encode()` can arrive as `19.989999999999998`, and Moneybird stores what it is given.
 */
abstract class Money
{
    /**
     * Round to cents and render as a decimal string.
     */
    public static function amount(float|int|string|null $value, int $decimals = 2): string
    {
        return number_format(round((float)$value, $decimals), $decimals, '.', '');
    }

    /**
     * Unit prices carry more precision than cents: a line of 3 at €9.99 has to divide back out
     * without shedding a cent on the way, so the quantity split is kept to four decimals.
     */
    public static function unitPrice(float $total, float $qty): string
    {
        if ($qty == 0.0) {
            return self::amount($total, 4);
        }

        return self::amount($total / $qty, 4);
    }

    public static function toFloat(mixed $value): float
    {
        if (is_string($value)) {
            // Moneybird speaks Dutch to humans but always `.` to APIs; a comma here would be a
            // value that came from somewhere else.
            $value = str_replace(',', '.', $value);
        }

        return (float)$value;
    }

    /**
     * Whether two amounts are the same to the cent.
     */
    public static function equal(float $a, float $b, float $epsilon = 0.005): bool
    {
        return abs($a - $b) < $epsilon;
    }
}
