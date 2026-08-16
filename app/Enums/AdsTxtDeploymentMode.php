<?php

namespace App\Enums;

enum AdsTxtDeploymentMode: string
{
    case ManualCopy = 'MANUAL_COPY';
    case ManagedRedirectDelegation = 'MANAGED_REDIRECT_DELEGATION';
}
