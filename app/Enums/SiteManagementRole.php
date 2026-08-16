<?php

namespace App\Enums;

enum SiteManagementRole: string
{
    case None = 'NONE';
    case HorusPrimaryGlobal = 'HORUS_PRIMARY_GLOBAL';
    case HorusPrimaryCountry = 'HORUS_PRIMARY_COUNTRY';
    case HorusExclusiveGlobal = 'HORUS_EXCLUSIVE_GLOBAL';
    case HorusExclusiveCountry = 'HORUS_EXCLUSIVE_COUNTRY';

    public function relationship(): ?string
    {
        return match ($this) {
            self::None => null,
            self::HorusPrimaryGlobal, self::HorusPrimaryCountry => 'PRIMARY',
            self::HorusExclusiveGlobal, self::HorusExclusiveCountry => 'EXCLUSIVE',
        };
    }

    public function isCountryScoped(): bool
    {
        return in_array($this, [self::HorusPrimaryCountry, self::HorusExclusiveCountry], true);
    }
}
