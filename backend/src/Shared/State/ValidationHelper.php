<?php

namespace App\Shared\State;

use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Valida un'entità e, in caso di errori, risponde 422 con le violazioni
 * rinominate sui campi dell'API (es. "anagrafica.dataDiNascita" -> "dataNascita").
 */
final class ValidationHelper
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /**
     * @param list<string>          $groups
     * @param array<string, string> $pathMap prefisso/percorso entità => campo API
     */
    public function validateOrFail(object $entity, array $groups, array $pathMap = []): void
    {
        $violations = $this->validator->validate($entity, null, $groups);
        if (0 === \count($violations)) {
            return;
        }

        $mapped = new ConstraintViolationList();
        foreach ($violations as $v) {
            $path = $v->getPropertyPath();
            foreach ($pathMap as $from => $to) {
                if ($path === $from || str_starts_with($path, $from)) {
                    $path = $to.substr($path, \strlen($from));
                    break;
                }
            }
            $mapped->add(new ConstraintViolation($v->getMessage(), $v->getMessageTemplate(), $v->getParameters(), $v->getRoot(), $path, $v->getInvalidValue(), $v->getPlural(), $v->getCode(), $v->getConstraint()));
        }

        // Eccezione di API Platform: risposta 422 con l'elenco "violations" (propertyPath, message).
        throw new ValidationException($mapped);
    }
}
