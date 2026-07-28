<?php

namespace App\Enums;

enum ConfigEnvironment: string
{
    case Preview = 'PREVIEW';
    case Test = 'TEST';
    case Production = 'PRODUCTION';

    public function filename(): string
    {
        return strtolower($this->value).'.json';
    }
}
