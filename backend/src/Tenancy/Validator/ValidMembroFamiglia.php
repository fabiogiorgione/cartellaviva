<?php

namespace App\Tenancy\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Regole che dipendono dal tenant: codice fiscale unico tra i membri del tenant,
 * account collegato che sia un membro attivo dello stesso tenant.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidMembroFamiglia extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
