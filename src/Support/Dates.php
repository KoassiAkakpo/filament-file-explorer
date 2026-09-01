<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How a date is written on screen.
 *
 * Every date the explorer shows goes through here — the inspector, the details
 * and column headers of the row views, the lightbox, the trash, the version
 * history and the share panel. One owner rather than a `format()` call at each
 * site, for the reason the sort list and the kind predicate have one: the
 * fourteen sites were already two spellings ('Y/m/d H:i' and Carbon's own
 * toDayDateTimeString(), which is English whatever the panel's locale is), and
 * a fifteenth would have been a third.
 *
 * Formatting is `translatedFormat()`, never `format()`. The two agree exactly
 * on a numeric pattern like the default, and differ on every pattern with a
 * word in it — which is the whole point: `j F Y` has to read "1 septembre 2026"
 * under a French locale and "1 September 2026" under an English one. The
 * escaping rule is PHP's own, so a literal letter is backslashed ('\à' is not
 * needed, '\l\e' is).
 *
 * The pattern may be one string for every locale, or an array keyed by locale
 * with a 'default' entry for the rest:
 *
 *   'format' => 'Y/m/d H:i',
 *   'format' => ['en' => 'M j, Y g:i A', 'fr' => 'd/m/Y H:i', 'default' => 'Y/m/d H:i'],
 *
 * The locale is the application's unless one is named, which is what makes a
 * panel able to write French dates in an English application.
 */
final class Dates
{
    /**
     * What the explorer wrote before this was configurable, and what an
     * unreadable setting falls back to.
     */
    public const DEFAULT_FORMAT = 'Y/m/d H:i';

    /**
     * Written where a date is missing. Not an empty string: a blank cell in a
     * row of dates reads as a rendering fault rather than as an absence.
     */
    public const PLACEHOLDER = '—';

    /**
     * The one date formatter. Null in, null out — the callers that show a
     * placeholder ask for one, the callers that hide the row do not.
     */
    public static function format(DateTimeInterface|string|null $date, ?string $fallback = null): ?string
    {
        $date = self::carbon($date);

        if (! $date instanceof CarbonInterface) {
            return $fallback;
        }

        return $date->locale(self::locale())->translatedFormat(self::pattern());
    }

    /**
     * Same, with the placeholder for a missing date. The rows of the trash and
     * the inspector always have a cell to fill.
     */
    public static function formatOrPlaceholder(DateTimeInterface|string|null $date): string
    {
        return self::format($date, self::PLACEHOLDER) ?? self::PLACEHOLDER;
    }

    /**
     * The pattern for the locale in force, resolved through StandaloneSettings
     * so a panel's own setting wins over config.
     */
    public static function pattern(): string
    {
        $format = StandaloneSettings::dateFormat();

        if (is_string($format)) {
            return $format === '' ? self::DEFAULT_FORMAT : $format;
        }

        if (! is_array($format)) {
            return self::DEFAULT_FORMAT;
        }

        $locale = self::locale();

        // The language on its own too ('fr' for 'fr_CA'), so a config keyed by
        // language keeps answering for a regional locale.
        $candidates = [$locale, str_replace('-', '_', $locale), explode('_', str_replace('-', '_', $locale))[0], 'default'];

        foreach ($candidates as $candidate) {
            $pattern = $format[$candidate] ?? null;

            if (is_string($pattern) && $pattern !== '') {
                return $pattern;
            }
        }

        return self::DEFAULT_FORMAT;
    }

    /**
     * The locale the month and day names are written in: the panel's or
     * config's when one is named, the application's otherwise.
     */
    public static function locale(): string
    {
        $locale = StandaloneSettings::dateLocale();

        return $locale ?? (string) app()->getLocale();
    }

    private static function carbon(DateTimeInterface|string|null $date): ?CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date;
        }

        if ($date instanceof DateTimeInterface) {
            return Carbon::instance($date);
        }

        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        // Custom properties store a string, and one written by an older version
        // of the package — or by hand — is not necessarily parseable.
        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            return null;
        }
    }
}
