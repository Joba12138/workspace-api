<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;

class ShanghaiTime
{
    public const TZ = 'Asia/Shanghai';

    public static function format(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return \Carbon\Carbon::parse($value)
            ->timezone(self::TZ)
            ->format('Y-m-d\TH:i:sP');
    }

    public static function parse(?string $value): ?\Carbon\Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return \Carbon\Carbon::parse($value, self::TZ);
    }
}
