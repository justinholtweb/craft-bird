<?php

namespace justinholtweb\bird\models;

use Craft;

/**
 * How an order's VAT should be booked.
 *
 * The treatment is *observed*, not decided: Commerce's tax engine has already worked out what to
 * charge, and re-deciding it here would give a shop two answers that can disagree. What Bird adds
 * is the reason — Moneybird needs a different 0% rate for "reverse-charged", "exported" and
 * "genuinely zero-rated", and only the reason tells them apart.
 */
abstract class TaxTreatment
{
    /** Customer in the shop's own country. */
    public const DOMESTIC = 'domestic';

    /** Business elsewhere in the EU with a VAT number: VAT reverse-charged to them. */
    public const EU_REVERSE_CHARGE = 'eu_reverse_charge';

    /** Consumer elsewhere in the EU, charged their own country's rate under the One Stop Shop. */
    public const EU_OSS = 'eu_oss';

    /** Consumer elsewhere in the EU, charged the shop's home rate (below the OSS threshold). */
    public const EU_HOME_RATE = 'eu_home_rate';

    /** Customer outside the EU: outside the scope of EU VAT. */
    public const EXPORT = 'export';

    /** No usable address at all — a digital order with nothing but an email address. */
    public const UNKNOWN = 'unknown';

    public static function label(string $treatment): string
    {
        return match ($treatment) {
            self::DOMESTIC => Craft::t('bird', 'Domestic'),
            self::EU_REVERSE_CHARGE => Craft::t('bird', 'EU reverse charge'),
            self::EU_OSS => Craft::t('bird', 'EU consumer (OSS)'),
            self::EU_HOME_RATE => Craft::t('bird', 'EU consumer (home rate)'),
            self::EXPORT => Craft::t('bird', 'Export outside the EU'),
            default => Craft::t('bird', 'Unknown'),
        };
    }
}
