<?php

namespace App\Ente;

use App\Account\Entity\User;
use App\Documenti\DocumentService;
use App\Documenti\Repository\CategoriaRepository;
use App\Ente\Entity\Collegamento;
use App\Ente\Entity\RichiestaDestinatario;
use App\Ente\Enum\DestinatarioStato;
use App\Ente\Enum\RichiestaTipo;
use App\Scadenze\Entity\Scadenza;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonConsentito;
use App\Shared\Errore\NonPiuValido;
use App\Shared\Errore\TroppiTentativi;
use App\Shared\Mail\Posta;
use App\Shared\TokenCasuale;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Risposta dal link sicuro, senza account.
 *
 * Il link vale per UNA richiesta e scade; da solo non mostra nulla: serve un
 * codice inviato all'email del referente, che apre una sessione di 2 ore.
 * La copia consegnata resta all'ente; chi vuole tenerne una crea l'account e
 * "collega" il link: le risposte passate (anche di altre richieste dello
 * stesso referente) vengono copiate nella sua famiglia, in "Condivisi con enti".
 */
final class LinkRispostaService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RichiesteService $richieste,
        private readonly FileCifratoService $files,
        private readonly DocumentService $documents,
        private readonly CategoriaRepository $categorie,
        private readonly CollegamentoService $collegamenti,
        private readonly Posta $posta,
    ) {
    }

    public function apri(string $token): RichiestaDestinatario
    {
        $d = '' !== $token ? $this->em->getRepository(RichiestaDestinatario::class)->findOneBy(['linkTokenHash' => RichiesteService::hashLink($token)]) : null;
        if (!$d instanceof RichiestaDestinatario || !$d->isViaLink() || !$d->isLinkValido()) {
            throw new NonPiuValido('Link scaduto o non più valido: chiedi all\'ente di inviarne un altro.');
        }

        return $d;
    }

    /** "e•••@gmail.com": abbastanza per riconoscere la casella, non per leggerla. */
    public static function emailMascherata(string $email): string
    {
        [$nome, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($nome, 0, 1).'•••@'.$dominio;
    }

    public function inviaCodice(RichiestaDestinatario $d): void
    {
        $codice = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
        $d->nuovoOtp($this->hashOtp($d, $codice));
        $this->em->flush();
        $this->posta->invia((string) $d->getReferenteLink()?->getEmail(), 'Il tuo codice per rispondere su CartellaViva', 'emails/ente_codice.html.twig', ['ente' => $d->getRichiesta()->getTenant()->getName(), 'codice' => $codice]);
    }

    /** Codice corretto: restituisce il token di sessione (2 ore). */
    public function verificaCodice(RichiestaDestinatario $d, string $codice): string
    {
        $sessione = TokenCasuale::nuovo();
        $esito = $d->verificaOtp($this->hashOtp($d, trim($codice)), self::hashSessione($sessione));
        $this->em->flush();
        match ($esito) {
            'ok' => $this->richieste->logEntrambi('richiesta.link_aperto', $d, null),
            'errato' => throw new DatiNonValidi('Codice non corretto.'),
            'bloccato' => throw new TroppiTentativi('Troppi tentativi: chiedi un nuovo codice.'),
            default => throw new DatiNonValidi('Codice scaduto: chiedine uno nuovo.'),
        };
        if (null === $d->getVistaIl()) {
            $d->segnaVista();
            $this->em->flush();
        }

        return $sessione;
    }

    public function assertSessione(RichiestaDestinatario $d, string $sessione): void
    {
        if ('' === $sessione || !$d->isSessioneValida(self::hashSessione($sessione))) {
            throw new NonConsentito('Sessione scaduta: inserisci di nuovo il codice.');
        }
    }

    public function rispondi(RichiestaDestinatario $d, ?string $contents, ?string $nomeFile, ?\DateTimeImmutable $validoFino): void
    {
        $this->assertApribile($d);
        $richiesta = $d->getRichiesta();
        self::assertValidita($richiesta->getTipo(), $richiesta->isChiediValidita(), $validoFino);
        $copia = null;
        if (RichiestaTipo::PRESA_VISIONE !== $richiesta->getTipo()) {
            if (null === $contents) {
                throw new DatiNonValidi('file: allega la scansione o il file da consegnare.');
            }
            $ext = strtolower(pathinfo($nomeFile ?? 'consegna.pdf', \PATHINFO_EXTENSION) ?: 'pdf');
            $copia = $this->files->store($richiesta->getTenant(), $contents, sprintf('%s - %s.%s', $d->getIscritto()->getNomeCompleto(), $richiesta->getTitolo(), $ext));
        }
        // Senza account non c'è un utente autore: chi risponde è il referente del link.
        $d->rispondi(null, [(string) $d->getReferenteLink()?->getId()], $copia, null, $richiesta->getTipo()->richiedeVerifica());
        $d->setValidoFino($validoFino);
        if (!$richiesta->getTipo()->richiedeVerifica()) {
            $this->richieste->dopoCompletamento($d);
        }
        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            if (null !== $copia) {
                $this->files->delete($copia);
            }
            throw $e;
        }
        $this->richieste->logEntrambi('richiesta.risposta_link', $d, null);
    }

    public function rifiuta(RichiestaDestinatario $d, string $messaggio): void
    {
        $this->assertApribile($d);
        $messaggio = trim($messaggio);
        if ('' === $messaggio) {
            throw new DatiNonValidi('messaggio: spiega all\'ente il motivo.');
        }
        $d->rifiuta(null, mb_substr($messaggio, 0, 1000));
        $this->em->flush();
        $this->richieste->logEntrambi('richiesta.rifiutata_link', $d, null);
    }

    /**
     * Dopo l'invio, il referente crea l'account (con la stessa email) e collega
     * il link: diventa referente collegato della famiglia scelta e le sue
     * risposte passate arrivano in "Condivisi con enti".
     *
     * @param array{nome?: string, cognome?: string}|null $nuovaPersona
     *
     * @return int risposte passate alla famiglia
     */
    public function collegaAccount(RichiestaDestinatario $d, User $user, Tenant $famiglia, ?string $personaId, ?array $nuovaPersona): int
    {
        $referente = $d->getReferenteLink() ?? throw new Conflitto('Link non collegabile.');
        // Stessi controlli dell'invito: stessa email, permesso collegamento:manage nella famiglia.
        $collegamento = $this->collegamenti->accetta($referente, $user, $famiglia, $personaId, $nuovaPersona);

        $passate = 0;
        $categoria = $this->categorie->findSystemBySlug(RisposteService::CATEGORIA);
        foreach ($this->em->getRepository(RichiestaDestinatario::class)->findBy(['referenteLink' => $referente, 'famiglia' => null]) as $vecchia) {
            $copia = null;
            if (null !== $vecchia->getRisposta() && null !== $categoria) {
                $r = $vecchia->getRichiesta();
                $copia = $this->documents->upload($user, $famiglia, $categoria, null !== $collegamento->getPersona() ? [$collegamento->getPersona()] : [], $this->files->read($vecchia->getRisposta()),
                    $vecchia->getRisposta()->getNomeFile(), sprintf('%s – %s', $r->getTitolo(), $r->getTenant()->getName()), $vecchia->getRispostaIl() ?? new \DateTimeImmutable('today'))['documento'];
            }
            $vecchia->passaAFamiglia($famiglia, $copia);
            // Le richieste ancora aperte ora si gestiscono dall'app, con la loro scadenza.
            $entro = $vecchia->getRichiesta()->getEntroIl();
            if ($vecchia->getStato()->isDaFare() && null !== $entro && !$vecchia->getRichiesta()->isAnnullata()) {
                $this->em->persist(Scadenza::perRichiesta($vecchia, $user, sprintf('%s (%s)', $vecchia->getRichiesta()->getTitolo(), $vecchia->getRichiesta()->getTenant()->getName()), $entro, $collegamento->getPersona()));
            }
            ++$passate;
        }
        $this->em->flush();
        $this->richieste->logEntrambi('richiesta.link_collegato', $d, $user);

        return $passate;
    }

    /** @return array<string, mixed> cosa vede chi ha inserito il codice */
    public function vista(RichiestaDestinatario $d): array
    {
        $r = $d->getRichiesta();

        return [
            'ente' => ['nome' => $r->getTenant()->getName(), 'verificato' => true],
            'tipo' => $r->getTipo()->value,
            'titolo' => $r->getTitolo(),
            'istruzioni' => $r->getIstruzioni(),
            'iscritto' => $d->getIscritto()->getNomeCompleto(),
            'gruppo' => $r->getGruppo()?->getNome(),
            'firmatari' => $r->getFirmatari(),
            'entroIl' => $r->getEntroIl()?->format('Y-m-d'),
            'chiediValidita' => $r->isChiediValidita(),
            'modalitaFirma' => $r->getModalitaFirma(),
            'stato' => $r->isAnnullata() ? 'annullata' : $d->getStato()->value,
            'motivoEnte' => $d->getMotivoEnte(),
            'allegato' => null !== $r->getAllegato() ? ['nome' => $r->getAllegato()->getNomeFile(), 'dimensione' => $r->getAllegato()->getDimensione()] : null,
            'email' => self::emailMascherata((string) $d->getReferenteLink()?->getEmail()),
            'linkScadenza' => $d->getLinkScadenza()?->format(\DATE_ATOM),
        ];
    }

    public static function assertValidita(RichiestaTipo $tipo, bool $chiedi, ?\DateTimeImmutable $validoFino): void
    {
        if (!$chiedi || RichiestaTipo::PRESA_VISIONE === $tipo) {
            return;
        }
        if (null === $validoFino) {
            throw new DatiNonValidi('validoFino: indica fino a quando è valido il documento.');
        }
        if ($validoFino < new \DateTimeImmutable('today')) {
            throw new DatiNonValidi('validoFino: il documento risulta già scaduto.');
        }
    }

    public static function hashSessione(string $sessione): string
    {
        return hash('sha256', 'link-sessione|'.$sessione);
    }

    private function assertApribile(RichiestaDestinatario $d): void
    {
        if ($d->getRichiesta()->isAnnullata() || !$d->getStato()->isDaFare()) {
            throw new Conflitto('A questa richiesta non si può più rispondere.');
        }
    }

    private function hashOtp(RichiestaDestinatario $d, string $codice): string
    {
        return hash('sha256', 'otp|'.$d->getId()->toRfc4122().'|'.$codice);
    }
}
