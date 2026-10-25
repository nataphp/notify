<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Core;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nata\I18n\Time;

/**
 * Computes whether a given time falls within configured silent hours and, if so,
 * returns the next allowed send time.
 *
 * Silent hours are used by the bus to queue email/push deliveries instead of sending immediately.
 *
 * Configuration shape:
 * - start: "HH:MM" (24h)
 * - end:   "HH:MM" (24h)
 * - timezone: IANA zone (optional; defaults to PHP's default timezone)
 *
 * @example
 * $next = SilentHours::nextAllowedAt(['start' => '20:00', 'end' => '07:00', 'timezone' => 'UTC']);
 * if ($next !== null) {
 *     // It's currently within silent hours; defer until $next.
 * }
 */
final class SilentHours {
    /**
     * Build a validated window array from loose start/end bounds.
     *
     * Bounds may be "HH:MM" strings or `Time` instances. A `Time` contributes only its
     * wall-clock time — the date is ignored — and its timezone becomes the window's,
     * unless `$timezone` is given. Nothing is converted between zones: the bounds are
     * read as wall-clock times and the resolved timezone says where they are read.
     *
     * Both bounds are validated here so a malformed value fails loudly at the call site,
     * rather than making `nextAllowedAt()` return null further downstream — which reads
     * as "not in silent hours" and would send the notification immediately.
     *
     * @param string|Time $start Window start, e.g. '23:00' (seconds tolerated and ignored).
     * @param string|Time $end Window end; may be earlier than $start, to span midnight.
     * @param string|null $timezone IANA zone the window is evaluated in. Wins over any
     *   timezone carried by the bounds; omit to inherit theirs, or to leave the window
     *   zoneless so `nextAllowedAt()` falls back to PHP's default timezone.
     * @return array{start:string,end:string,timezone?:string} Window in `nextAllowedAt()` shape.
     * @throws InvalidArgumentException When a bound is not a valid 24h time, or when the two
     *   bounds are Time instances in different timezones and no `$timezone` resolves them.
     */
    public static function window(string|Time $start, string|Time $end, ?string $timezone = null): array {
        $window = [
            'start' => self::normalizeBound($start, 'start'),
            'end' => self::normalizeBound($end, 'end'),
        ];

        $zone = $timezone ?? self::inferTimezone($start, $end);
        if ($zone !== null) {
            $window['timezone'] = $zone;
        }

        return $window;
    }

    /**
     * Reduce one bound to a canonical "HH:MM" string.
     *
     * @param string|Time $bound The bound as given by the caller.
     * @param string $label Which bound this is ('start' or 'end'), for the error message.
     * @return string Zero-padded 24h "HH:MM".
     * @throws InvalidArgumentException When a string bound is not a valid 24h time.
     */
    private static function normalizeBound(string|Time $bound, string $label): string {
        if ($bound instanceof Time) {
            return $bound->format('H:i');
        }

        $minutes = self::timeToMinutes($bound);
        if ($minutes === null) {
            throw new InvalidArgumentException(sprintf(
                'Silent hours %s must be a 24h "HH:MM" time, got "%s".',
                $label,
                $bound
            ));
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Derive the window timezone from whichever bounds are Time instances.
     *
     * A window is evaluated in exactly one zone, so two Time bounds disagreeing is
     * ambiguous rather than merely unusual — the caller has to break the tie with an
     * explicit timezone.
     *
     * @param string|Time $start Window start as given by the caller.
     * @param string|Time $end Window end as given by the caller.
     * @return string|null IANA zone name, or null when neither bound carries one.
     * @throws InvalidArgumentException When the two bounds carry different timezones.
     */
    private static function inferTimezone(string|Time $start, string|Time $end): ?string {
        $startZone = $start instanceof Time ? $start->getTimezone() : false;
        $endZone = $end instanceof Time ? $end->getTimezone() : false;

        $startName = $startZone !== false ? $startZone->getName() : null;
        $endName = $endZone !== false ? $endZone->getName() : null;

        if ($startName !== null && $endName !== null && $startName !== $endName) {
            throw new InvalidArgumentException(sprintf(
                'Silent hours start (%s) and end (%s) are in different timezones; pass an '
                    . 'explicit timezone to say which one the window is evaluated in.',
                $startName,
                $endName
            ));
        }

        return $startName ?? $endName;
    }

    /**
     * @param array{start?:string,end?:string,timezone?:string}|null $config
     * @param DateTimeImmutable|null $now Optional clock override (useful for testing).
     * @return DateTimeImmutable|null Next allowed time if currently in silent hours; otherwise null.
     */
    public static function nextAllowedAt(?array $config, ?DateTimeImmutable $now = null): ?DateTimeImmutable {
        if (empty($config) || empty($config['start']) || empty($config['end'])) {
            return null;
        }

        $tz = !empty($config['timezone']) ? (string) $config['timezone'] : date_default_timezone_get();
        $zone = new DateTimeZone($tz);
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone);

        $start = self::timeToMinutes((string) $config['start']);
        $end = self::timeToMinutes((string) $config['end']);
        if ($start === null || $end === null) {
            return null;
        }

        $cur = ((int) $now->format('H')) * 60 + (int) $now->format('i');

        $inSilent = false;
        if ($start < $end) {
            // e.g. 20:00-23:00
            $inSilent = $cur >= $start && $cur < $end;
        } else {
            // spans midnight e.g. 20:00-07:00
            $inSilent = ($cur >= $start) || ($cur < $end);
        }

        if (!$inSilent) {
            return null;
        }

        // Next allowed is today at end (if end is ahead), otherwise tomorrow at end.
        $todayEnd = $now->setTime(intdiv($end, 60), $end % 60, 0);
        if ($start < $end) {
            // silent does not span midnight; end is later today
            return $todayEnd;
        }

        // silent spans midnight: if we're after start, allowed is tomorrow at end; if before end, allowed is today at end.
        if ($cur >= $start) {
            return $todayEnd->modify('+1 day');
        }
        return $todayEnd;
    }

    /**
     * Convert "HH:MM" into minutes since midnight.
     *
     * A trailing ":SS" is accepted and discarded, so a value read straight out of a
     * TIME column ("23:00:00") parses instead of being rejected as malformed.
     *
     * @param string $hhmm 24h time, with or without seconds.
     * @return int|null Minutes since midnight, or null when parsing fails.
     */
    private static function timeToMinutes(string $hhmm): ?int {
        $hhmm = trim($hhmm);
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $hhmm, $matches)) {
            return null;
        }
        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }
        return $hours * 60 + $minutes;
    }
}

