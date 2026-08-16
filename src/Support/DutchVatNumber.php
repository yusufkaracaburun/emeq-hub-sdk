<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

/**
 * Normalises a Dutch VAT number for the canonical party payload.
 *
 * A wrong VAT number is worse than none: the bookkeeping stores it on the
 * relation and it ends up on returns. Anything that does not check out is
 * dropped rather than sent, so `vat_number` is either right or absent.
 */
final class DutchVatNumber
{
    /**
     * The number as Hub wants it (NL123456789B01), or null when the input is
     * empty or is not a valid Dutch VAT number.
     */
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $candidate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');

        // Dutch numbers are commonly written and stored without the country code.
        if (preg_match('/^[0-9]{9}B[0-9]{2}$/', $candidate) === 1) {
            $candidate = 'NL'.$candidate;
        }

        return self::isValid($candidate) ? $candidate : null;
    }

    /**
     * Both check digits the Belastingdienst has issued under: the original
     * eleven-test and the mod-97 scheme that replaced it for numbers derived
     * from a citizen service number.
     */
    public static function isValid(string $candidate): bool
    {
        if (preg_match('/^NL[0-9]{9}B[0-9]{2}$/', $candidate) !== 1) {
            return false;
        }

        return self::passesMod97($candidate) || self::passesEleven($candidate);
    }

    private static function passesMod97(string $candidate): bool
    {
        $expanded = '';

        foreach (str_split($candidate) as $character) {
            $expanded .= ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
        }

        $remainder = 0;

        foreach (str_split($expanded) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }

    private static function passesEleven(string $candidate): bool
    {
        $digits = substr($candidate, 2, 9);
        $sum = 0;

        for ($position = 0; $position < 8; $position++) {
            $sum += (int) $digits[$position] * (9 - $position);
        }

        $sum -= (int) $digits[8];

        return $sum > 0 && $sum % 11 === 0;
    }
}
