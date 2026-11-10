<?php

namespace App\Ente\Enum;

/**
 * Stato di un referente visto dall'ente. L'ente non scopre mai se l'email ha
 * già un account: vede solo se l'invito è partito e se è stato accettato.
 */
enum ReferenteStato: string
{
    /** Inserito (a mano o da import), invito non ancora confermato dall'operatore. */
    case DA_INVITARE = 'da_invitare';
    case INVITATO = 'invitato';
    case COLLEGATO = 'collegato';
    case RIFIUTATO = 'rifiutato';
    /** Chi ha ricevuto l'invito dice di non essere il referente: l'ente corregge l'email. */
    case NON_SONO_IO = 'non_sono_io';
    /** La famiglia ha interrotto il collegamento. */
    case REVOCATO = 'revocato';
}
