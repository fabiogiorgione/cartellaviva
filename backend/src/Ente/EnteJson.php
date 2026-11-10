<?php

namespace App\Ente;

use App\Ente\Entity\AnnoEnte;
use App\Ente\Entity\EnteProfilo;
use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Iscritto;
use App\Ente\Entity\Referente;
use App\Ente\Entity\Sede;
use App\Tenancy\Entity\Membership;

/**
 * Rappresentazione JSON delle risorse dell'ente. Mai dati della famiglia:
 * di un referente l'ente vede solo ciò che ha inserito e lo stato dell'invito.
 */
final class EnteJson
{
    /** @return array<string, mixed> */
    public static function profilo(EnteProfilo $p): array
    {
        return [
            'tenantId' => (string) $p->getTenant()->getId(),
            'denominazione' => $p->getTenant()->getName(),
            'tipo' => $p->getTipo()->value,
            'codiceFiscale' => $p->getCodiceFiscale(),
            'codiceMeccanografico' => $p->getCodiceMeccanografico(),
            'numeroRunts' => $p->getNumeroRunts(),
            'emailIstituzionale' => $p->getEmailIstituzionale(),
            'sedeLegale' => $p->getSedeLegale(),
            'stato' => $p->getStato()->value,
            'verificato' => $p->isVerificato(),
            'emailConfermataIl' => $p->getEmailConfermataIl()?->format(\DATE_ATOM),
            'notaPortale' => $p->getNotaPortale(),
            'createdAt' => $p->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function anno(AnnoEnte $a): array
    {
        return ['id' => (string) $a->getId(), 'nome' => $a->getNome(), 'archiviato' => $a->isArchiviato()];
    }

    /** @return array<string, mixed> */
    public static function sede(Sede $s): array
    {
        return ['id' => (string) $s->getId(), 'nome' => $s->getNome(), 'indirizzo' => $s->getIndirizzo()];
    }

    /** @return array<string, mixed> */
    public static function gruppo(Gruppo $g, ?int $iscritti = null): array
    {
        return [
            'id' => (string) $g->getId(),
            'nome' => $g->getNome(),
            'tipo' => $g->getTipo(),
            'annoId' => (string) $g->getAnno()->getId(),
            'anno' => $g->getAnno()->getNome(),
            'archiviato' => $g->getAnno()->isArchiviato(),
            'sede' => null !== $g->getSede() ? self::sede($g->getSede()) : null,
            'operatori' => array_values(array_map(static fn (Membership $m) => [
                'membershipId' => (string) $m->getId(),
                'nome' => $m->getUser()->getFullName(),
            ], $g->getOperatori()->toArray())),
            'iscritti' => $iscritti,
        ];
    }

    /** @return array<string, mixed> */
    public static function referente(Referente $r): array
    {
        return [
            'id' => (string) $r->getId(),
            'nome' => $r->getNome(),
            'cognome' => $r->getCognome(),
            'email' => $r->getEmail(),
            'relazione' => $r->getRelazione(),
            'stato' => $r->getStato()->value,
            'invitatoIl' => $r->getInvitatoIl()?->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function iscritto(Iscritto $i): array
    {
        return [
            'id' => (string) $i->getId(),
            'nome' => $i->getNome(),
            'cognome' => $i->getCognome(),
            'dataNascita' => $i->getDataNascita()?->format('Y-m-d'),
            'gruppi' => array_values(array_map(static fn (Gruppo $g) => ['id' => (string) $g->getId(), 'nome' => $g->getNome(), 'anno' => $g->getAnno()->getNome(), 'archiviato' => $g->getAnno()->isArchiviato()], $i->getGruppi()->toArray())),
            'referenti' => array_values(array_map(self::referente(...), $i->getReferenti()->toArray())),
        ];
    }
}
