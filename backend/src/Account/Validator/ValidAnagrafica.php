<?php

namespace App\Account\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Regole di coerenza dell'anagrafica.
 * Il gruppo di validazione ("privato" / "azienda") seleziona le regole.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidAnagrafica extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
