<?php

namespace App\Ente\Firma;

use App\Account\Entity\User;
use App\Documenti\DocumentService;
use App\Documenti\Repository\CategoriaRepository;
use App\Ente\Entity\Collegamento;
use App\Ente\Entity\FirmaCodice;
use App\Ente\Entity\Referente;
use App\Ente\Entity\RichiestaDestinatario;
use App\Ente\FileCifratoService;
use App\Ente\RichiesteService;
use App\Ente\RisposteService;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\TroppiTentativi;
use App\Shared\Mail\Posta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Firma con codice (FES): ogni referente firma per sé il PDF dell'ente.
 *
 * 1. chiede il codice: si registra l'impronta del documento che sta firmando
 *    e parte un codice monouso (10 minuti, 5 tentativi) alla SUA email;
 * 2. conferma con codice + dichiarazione esplicita: la firma è registrata con
 *    data, canale, IP e un codice di verifica pubblico;
 * 3. quando hanno firmato tutti quelli richiesti (o uno, se basta uno), si
 *    compone il PDF firmato (originale + attestazione) e la consegna è
 *    completata: niente verifica manuale, il documento è quello dell'ente.
 */
final class FirmaCodiceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FileCifratoService $files,
        private readonly AttestazioneFirmaPdf $pdf,
        private readonly RichiesteService $richieste,
        private readonly DocumentService $documents,
        private readonly CategoriaRepository $categorie,
        private readonly Posta $posta,
    ) {
    }

    /** Referente che l'utente rappresenta in questo destinatario (il suo collegamento), se c'è. */
    public function referenteDi(RichiestaDestinatario $d, User $user): ?Referente
    {
        foreach ($d->getReferenti() as $r) {
            $c = $this->em->getRepository(Collegamento::class)->findOneBy(['referente' => $r]);
            if ($c instanceof Collegamento && $c->isAttivo() && $c->getAccettatoDa()?->getId()->equals($user->getId())) {
                return $r;
            }
        }

        return null;
    }

    public function inviaCodice(RichiestaDestinatario $d, Referente $referente, ?User $user): FirmaCodice
    {
        $this->assertFirmabile($d, $referente);
        $originale = $this->files->read($d->getRichiesta()->getAllegato());
        $email = $user?->getEmail() ?? (string) $referente->getEmail();
        $firma = $this->inAttesa($d, $referente) ?? new FirmaCodice($d, $referente, $user, $referente->getNomeCompleto(), $email, hash('sha256', $originale));
        $codice = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
        $firma->nuovoOtp($this->hashOtp($firma, $codice), $user);
        // Chiedere il codice per firmare vuol dire aver aperto la richiesta.
        $d->segnaVista();
        $this->em->persist($firma);
        $this->em->flush();
        $this->posta->invia($email, 'Il tuo codice per firmare su CartellaViva', 'emails/firma_codice.html.twig', ['ente' => $d->getRichiesta()->getTenant()->getName(), 'codice' => $codice, 'nome' => $referente->getNome()]);

        return $firma;
    }

    /**
     * @return bool true se con questa firma la consegna è completa
     */
    public function firma(RichiestaDestinatario $d, Referente $referente, string $codice, bool $accetto, ?User $user, ?string $ip, ?string $userAgent): bool
    {
        $this->assertFirmabile($d, $referente);
        if (!$accetto) {
            throw new DatiNonValidi('accetto: conferma di aver letto il documento e di volerlo firmare.');
        }
        $firma = $this->inAttesa($d, $referente) ?? throw new Conflitto('Chiedi prima il codice.');
        if (null !== $user && null !== $firma->getUser() && !$firma->getUser()->getId()->equals($user->getId())) {
            throw new Conflitto('Il codice è stato chiesto da un altro account.');
        }
        // Il documento non deve essere cambiato tra la richiesta del codice e la firma.
        if (!hash_equals($firma->getDocumentoSha256(), hash('sha256', $this->files->read($d->getRichiesta()->getAllegato())))) {
            throw new Conflitto('Il documento è cambiato: chiedi un nuovo codice.');
        }
        $esito = $firma->firma($this->hashOtp($firma, trim($codice)), $ip, $userAgent, self::codiceVerifica());
        $this->em->flush();
        match ($esito) {
            'ok' => null,
            'errato' => throw new DatiNonValidi('Codice non corretto.'),
            'bloccato' => throw new TroppiTentativi('Troppi tentativi: chiedi un nuovo codice.'),
            default => throw new DatiNonValidi('Codice scaduto: chiedine uno nuovo.'),
        };
        $this->richieste->logEntrambi('richiesta.firma_codice', $d, $user);

        $firmate = $this->firmate($d);
        $firmatari = array_map(static fn (FirmaCodice $f) => (string) $f->getReferente()->getId(), $firmate);
        $completa = $d->getRichiesta()->bastaUno() || [] === array_diff(array_map(static fn (Referente $r) => (string) $r->getId(), $d->getReferenti()->toArray()), $firmatari);
        if ($completa) {
            $this->completa($d, $firmate, $user);
        }

        return $completa;
    }

    /** @return list<FirmaCodice> firme valide di questo destinatario, in ordine */
    public function firmate(RichiestaDestinatario $d): array
    {
        return $this->em->createQuery('SELECT f FROM '.FirmaCodice::class.' f WHERE f.destinatario = :d AND f.firmataIl IS NOT NULL ORDER BY f.firmataIl ASC')
            ->setParameter('d', $d->getId(), 'uuid')->getResult();
    }

    public function verifica(string $codice): ?FirmaCodice
    {
        $f = $this->em->getRepository(FirmaCodice::class)->findOneBy(['codiceVerifica' => strtoupper(trim($codice))]);

        return $f instanceof FirmaCodice && $f->isFirmata() ? $f : null;
    }

    /** @param list<FirmaCodice> $firme */
    private function completa(RichiestaDestinatario $d, array $firme, ?User $user): void
    {
        $r = $d->getRichiesta();
        $firmato = $this->pdf->componi($this->files->read($r->getAllegato()), $d, $firme);
        $sha = hash('sha256', $firmato);
        foreach ($firme as $f) {
            $f->setDocumentoFirmatoSha256($sha);
        }
        $nome = sprintf('%s - %s (firmato).pdf', $d->getIscritto()->getNomeCompleto(), $r->getTitolo());
        $copiaEnte = $this->files->store($r->getTenant(), $firmato, $nome, true);
        $copiaFamiglia = null;
        if (null !== $d->getFamiglia() && null !== $user && null !== ($categoria = $this->categorie->findSystemBySlug(RisposteService::CATEGORIA))) {
            $persone = [];
            foreach ($d->getReferenti() as $ref) {
                $c = $this->em->getRepository(Collegamento::class)->findOneBy(['referente' => $ref]);
                if ($c instanceof Collegamento && null !== $c->getPersona()) {
                    $persone[(string) $c->getPersona()->getId()] = $c->getPersona();
                }
            }
            $copiaFamiglia = $this->documents->upload($user, $d->getFamiglia(), $categoria, array_values($persone), $firmato, $nome, sprintf('%s – %s (firmato)', $r->getTitolo(), $r->getTenant()->getName()), new \DateTimeImmutable('today'))['documento'];
        }
        $d->rispondi($user, array_map(static fn (FirmaCodice $f) => (string) $f->getReferente()->getId(), $firme), $copiaEnte, $copiaFamiglia, false);
        $this->richieste->scadenza($d)?->chiudi();
        $this->richieste->dopoCompletamento($d);
        $this->em->flush();
        $this->richieste->logEntrambi('richiesta.firmata_con_codice', $d, $user);
    }

    private function assertFirmabile(RichiestaDestinatario $d, Referente $referente): void
    {
        $r = $d->getRichiesta();
        if (!$r->isFirmaConCodice() || null === $r->getAllegato()) {
            throw new Conflitto('Questa richiesta non prevede la firma con codice.');
        }
        if ($r->isAnnullata() || !$d->getStato()->isDaFare()) {
            throw new Conflitto('A questa richiesta non si può più rispondere.');
        }
        if (!$d->getReferenti()->contains($referente)) {
            throw new Conflitto('Non sei tra i firmatari di questa richiesta.');
        }
        foreach ($this->firmate($d) as $f) {
            if ($f->getReferente() === $referente) {
                throw new Conflitto('Hai già firmato questo documento.');
            }
        }
    }

    private function inAttesa(RichiestaDestinatario $d, Referente $referente): ?FirmaCodice
    {
        $f = $this->em->getRepository(FirmaCodice::class)->findOneBy(['destinatario' => $d, 'referente' => $referente, 'firmataIl' => null]);

        return $f instanceof FirmaCodice ? $f : null;
    }

    private function hashOtp(FirmaCodice $f, string $codice): string
    {
        return hash('sha256', 'firma|'.$f->getId()->toRfc4122().'|'.$codice);
    }

    /** 12 caratteri senza ambiguità (niente 0/O, 1/I): circa 60 bit. */
    private static function codiceVerifica(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; ++$i) {
            $out .= $alfabeto[random_int(0, \strlen($alfabeto) - 1)];
        }

        return $out;
    }
}
