<?php

namespace App\Account;

use App\Shared\Errore\NonConsentito;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Registrazione libera, aperta o chiusa (REGISTRAZIONE_APERTA).
 *
 * Chiusa: nessuno crea un account da solo, né con email e password né con il primo
 * accesso Google. Si entra solo su invito (accettazione dell'invito in famiglia) o
 * con un account creato dal portale (gestione manuale, superadmin).
 */
final class Registrazione
{
    public const MESSAGGIO_CHIUSA = 'La registrazione è chiusa: su questo server si entra solo su invito.';

    public function __construct(
        #[Autowire(env: 'bool:REGISTRAZIONE_APERTA')]
        private readonly bool $aperta,
    ) {
    }

    public function isAperta(): bool
    {
        return $this->aperta;
    }

    /** @throws NonConsentito se la registrazione è chiusa (HTTP 403) */
    public function richiediAperta(): void
    {
        if (!$this->aperta) {
            throw new NonConsentito(self::MESSAGGIO_CHIUSA);
        }
    }
}
