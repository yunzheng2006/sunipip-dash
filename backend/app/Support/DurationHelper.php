<?php

namespace App\Support;

use Carbon\Carbon;

class DurationHelper
{
    // unit: 1=天, 2=周, 3=月(30天), 4=年(365天)
    public static function toDays(int $duration, int $unit): int
    {
        return match ($unit) {
            1 => $duration,
            2 => $duration * 7,
            3 => $duration * 30,
            4 => $duration * 365,
            default => $duration * 30,
        };
    }

    public static function toMonths(int $duration, int $unit): float
    {
        return match ($unit) {
            1 => $duration / 30,
            2 => $duration * 7 / 30,
            4 => $duration * 12,
            default => $duration,
        };
    }

    public static function addToDate(Carbon $date, int $duration, int $unit): Carbon
    {
        return $date->copy()->addDays(self::toDays($duration, $unit));
    }
}
