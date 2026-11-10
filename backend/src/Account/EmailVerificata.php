<?php

namespace App\Account;

use App\Account\Entity\User;
use App\Shared\Errore\NonConsentito;

/**
 * Finché l'email non è verificata l'account funziona per sé, ma non coinvolge
 * altre persone: niente inviti (in famiglia o dall'ente), niente collegamenti
 * con gli enti, niente registrazione di un ente.
 */
final class EmailVerificata
{
    public const MESSAGGIO = 'Conferma prima il tuo indirizzo email: apri il link che ti abbiamo mandato (puoi farlo rinviare dal tuo profilo).';

    public static function richiedi(User $user): void
    {
        if (!$user->isEmailVerificata()) {
            throw new NonConsentito(self::MESSAGGIO);
        }
    }
}
