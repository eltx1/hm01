<?php

namespace App\Enums;

enum GamCredentialType: string
{
    case ServiceAccount = 'SERVICE_ACCOUNT';
    case OAuth2 = 'OAUTH2';
}
