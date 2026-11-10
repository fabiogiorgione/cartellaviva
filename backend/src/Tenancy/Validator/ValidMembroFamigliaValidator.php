<?php

namespace App\Tenancy\Validator;

use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\Repository\MembroFamigliaRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidMembroFamigliaValidator extends ConstraintValidator
{
    public function __construct(
        private readonly MembroFamigliaRepository $membri,
        private readonly MembershipRepository $memberships,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidMembroFamiglia) {
            throw new UnexpectedTypeException($constraint, ValidMembroFamiglia::class);
        }
        if (!$value instanceof MembroFamiglia) {
            return;
        }

        $cf = $value->getAnagrafica()->getCodiceFiscale();
        if ($cf && $this->membri->codiceFiscaleExistsInTenant($value->getTenant(), $cf, $value->getId())) {
            $this->context->buildViolation('Questo codice fiscale è già associato a un altro membro della famiglia')
                ->atPath('anagrafica.codiceFiscale')
                ->addViolation();
        }

        $user = $value->getUser();
        if (null !== $user) {
            $membership = $this->memberships->findOneByUserAndTenant($user, $value->getTenant());
            if (null === $membership || !$membership->isActive()) {
                $this->context->buildViolation('L\'account collegato deve essere un membro attivo della famiglia')
                    ->atPath('userId')
                    ->addViolation();
            }
        }
    }
}
