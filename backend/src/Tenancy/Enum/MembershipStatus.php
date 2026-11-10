<?php

namespace App\Tenancy\Enum;

enum MembershipStatus: string
{
    /** Unico stato che concede accesso ai dati del tenant. */
    case ACTIVE = 'active';

    /** Accesso sospeso: la membership resta tracciata ma non concede nulla. */
    case SUSPENDED = 'suspended';
}
