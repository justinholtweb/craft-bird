<?php

namespace justinholtweb\bird\helpers;

/**
 * Which countries the EU VAT rules apply to.
 *
 * The list is the 27 member states as of 2026. It is deliberately a plain constant rather than a
 * lookup against Craft's locale data: `Locale` knows about countries, not about customs unions,
 * and the difference between "in the EU" and "not in the EU" is the difference between charging
 * VAT and not charging it.
 *
 * Territory notes that matter for a webshop, and that a country code alone cannot express:
 *
 * - The **UK** left on 2021-01-01 and is an export. **Northern Ireland** stayed inside the EU VAT
 *   area for goods; its VAT numbers carry the `XI` prefix, which is why `XI` is treated as EU here
 *   even though it is not an ISO country of its own.
 * - **Monaco** (MC) is treated as France for VAT, so it counts as EU.
 * - Some regions of member states are outside the VAT area entirely (the Canary Islands, Ceuta and
 *   Melilla, the French overseas departments, Büsingen and Heligoland, Livigno, the Åland Islands,
 *   Mount Athos). A country code cannot see them, so Bird cannot either — a shop that ships there
 *   needs a postcode rule in Commerce's tax engine, and Bird will faithfully report whatever tax
 *   Commerce ended up charging.
 */
abstract class Eu
{
    /**
     * @var string[]
     */
    public const MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE',
        'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * Non-member codes that nonetheless sit inside the EU VAT area.
     *
     * @var string[]
     */
    public const VAT_AREA_EXTRAS = ['MC', 'XI'];

    public static function isMemberState(?string $countryCode): bool
    {
        if ($countryCode === null || $countryCode === '') {
            return false;
        }

        $code = strtoupper($countryCode);

        return in_array($code, self::MEMBER_STATES, true)
            || in_array($code, self::VAT_AREA_EXTRAS, true);
    }

    /**
     * The country a VAT number belongs to, or null if it is not shaped like one.
     *
     * Only the prefix is read. Bird never claims a number is *valid* — Moneybird runs the VIES
     * check itself when the contact is saved and reports the answer back on the contact as
     * `tax_number_valid`, so duplicating it here would just mean two sources of truth that can
     * disagree.
     */
    public static function countryOfVatNumber(?string $vatNumber): ?string
    {
        $normalized = self::normalizeVatNumber($vatNumber);

        if ($normalized === null || strlen($normalized) < 4) {
            return null;
        }

        $prefix = substr($normalized, 0, 2);

        // Greece bills itself EL on VAT numbers and GR everywhere else.
        if ($prefix === 'EL') {
            return 'GR';
        }

        if (!in_array($prefix, self::MEMBER_STATES, true) && !in_array($prefix, self::VAT_AREA_EXTRAS, true)) {
            return null;
        }

        return $prefix;
    }

    /**
     * Upper-cased, with every separator a human might type stripped out.
     */
    public static function normalizeVatNumber(?string $vatNumber): ?string
    {
        if ($vatNumber === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatNumber) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Whether a VAT number's own country agrees with the address it was given on.
     *
     * A `DE…` number on a French address is the usual sign of a customer pasting the wrong
     * company's details, and it is worth refusing to zero-rate on.
     */
    public static function vatNumberMatchesCountry(?string $vatNumber, ?string $countryCode): bool
    {
        $numberCountry = self::countryOfVatNumber($vatNumber);

        if ($numberCountry === null || $countryCode === null) {
            return false;
        }

        return $numberCountry === strtoupper($countryCode);
    }
}
