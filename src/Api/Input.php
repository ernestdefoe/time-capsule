<?php

namespace ErnestDefoe\TimeCapsule\Api;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Settings\SettingsRepositoryInterface;

/** Reading a form that came as JSON or as multipart (letters with photos). */
final class Input
{
    /** @param array<string, mixed> $body */
    public static function text(array $body, string $key): string
    {
        return is_string($body[$key] ?? null) ? trim(str_replace("\r\n", "\n", $body[$key])) : '';
    }

    /** @param array<string, mixed> $body */
    public static function flag(array $body, string $key): bool
    {
        return in_array($body[$key] ?? null, [true, 1, '1', 'true', 'on'], true);
    }

    /**
     * An open date: ISO 8601 with its offset (the browser sends the member's
     * local midnight), at least MIN_HOURS away and no further than the admin's
     * limit in years.
     *
     * @param array<string, mixed> $body
     */
    public static function opensAt(array $body, SettingsRepositoryInterface $settings): ?Carbon
    {
        $value = $body['opensAt'] ?? null;
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return null;
        }

        try {
            $at = Carbon::parse($value)->utc();
        } catch (InvalidFormatException) {
            // Shaped like a date but not one (month 13): the form says the date is wrong.
            return null;
        }

        if ($at < Carbon::now()->addHours(Settings::MIN_HOURS) || $at > Carbon::now()->addYears(self::maxYears($settings))->addDays(2)) {
            return null;
        }

        return $at;
    }

    /**
     * What the letter forms need to know, the same on every page that has them.
     *
     * @return array{maxYears: int, maxPhotos: int, maxLength: int, minHours: int, founded: string}
     */
    public static function limits(SettingsRepositoryInterface $settings): array
    {
        return [
            'maxYears' => self::maxYears($settings),
            'maxPhotos' => Settings::MAX_PHOTOS,
            'maxLength' => Settings::MAX_LENGTH,
            'minHours' => Settings::MIN_HOURS,
            'founded' => (string) $settings->get(Settings::FOUNDED),
        ];
    }

    public static function maxYears(SettingsRepositoryInterface $settings): int
    {
        return max(1, min(100, (int) $settings->get(Settings::MAX_YEARS)));
    }

    public static function words(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text);
    }
}
