<?php

namespace App\Time;

use DateTime;
use DateTimeZone;

final class Clock
{
    public const TZ = 'America/Merida';

    public static function today(): string
    {
        return (new DateTime('now', new DateTimeZone(self::TZ)))->format('Y-m-d');
    }

    public static function nowMs(): string
    {
        return (new DateTime('now', new DateTimeZone(self::TZ)))->format('Y-m-d H:i:s.v');
    }
}