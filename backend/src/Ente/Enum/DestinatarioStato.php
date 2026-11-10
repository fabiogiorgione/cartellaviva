<?php

namespace App\Ente\Enum;

/**
 * Stato della richiesta per UNA famiglia (uguale per ente e famiglia).
 * "Scaduta" non è uno stato: è "entro il" superato con la richiesta ancora aperta.
 */
enum DestinatarioStato: string
{
    case INVIATA = 'inviata';
    case VISTA = 'vista';
    case DA_VERIFICARE = 'da_verificare';
    case COMPLETATA = 'completata';
    case DA_RIFIRMARE = 'da_rifirmare';
    /** La famiglia non firma / non autorizza (risposta valida, con messaggio). */
    case RIFIUTATA = 'rifiutata';
    /** Annullata dall'ente, o non più necessaria (basta un referente e ha risposto un'altra famiglia). */
    case ANNULLATA = 'annullata';

    /** La famiglia deve ancora fare qualcosa. */
    public function isDaFare(): bool
    {
        return \in_array($this, [self::INVIATA, self::VISTA, self::DA_RIFIRMARE], true);
    }

    public function isConclusa(): bool
    {
        return \in_array($this, [self::COMPLETATA, self::RIFIUTATA, self::ANNULLATA], true);
    }
}
