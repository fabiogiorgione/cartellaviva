<?php

namespace App\Ente;

use App\Account\Entity\User;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Repository\CategoriaRepository;
use App\Ente\Entity\Collegamento;
use App\Ente\Entity\RichiestaDestinatario;
use App\Ente\Enum\RichiestaTipo;
use App\Shared\Doctrine\PerId;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Risposta della famiglia a una richiesta: consegna "a due copie".
 *
 * - l'ENTE riceve una copia cifrata con la sua chiave (FileCifrato), che
 *   conserva anche se la famiglia poi interrompe il collegamento;
 * - la FAMIGLIA conserva la sua copia nella categoria "Condivisi con enti"
 *   (sensibile, cifrata con la chiave della famiglia), collegata alla persona
 *   abbinata. Se invia un documento già nel suo archivio non lo duplica.
 *
 * Nessun accesso dell'ente all'archivio: passa solo il file scelto.
 */
final class RisposteService
{
    public const CATEGORIA = 'condivisi_enti';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantAccess $access,
        private readonly DocumentService $documents,
        private readonly CategoriaRepository $categorie,
        private readonly FileCifratoService $files,
        private readonly RichiesteService $richieste,
    ) {
    }

    public function segnaVista(RichiestaDestinatario $d, User $user): void
    {
        if (null === $d->getVistaIl()) {
            $d->segnaVista();
            $this->em->flush();
            $this->richieste->logEntrambi('richiesta.vista', $d, $user);
        }
    }

    /**
     * @param list<string> $firmatari id dei referenti (di questa famiglia) che hanno firmato
     */
    public function rispondi(RichiestaDestinatario $d, User $user, array $firmatari, ?string $contents, ?string $nomeFile, ?string $documentoId, ?\DateTimeImmutable $validoFino = null): void
    {
        $this->assertApribile($d);
        $richiesta = $d->getRichiesta();
        LinkRispostaService::assertValidita($richiesta->getTipo(), $richiesta->isChiediValidita(), $validoFino);
        $validi = array_map(static fn ($r) => (string) $r->getId(), $d->getReferenti()->toArray());
        $firmatari = array_values(array_unique(array_filter($firmatari, static fn ($id) => \in_array($id, $validi, true))));
        if ([] === $firmatari) {
            throw new DatiNonValidi(RichiestaTipo::PRESA_VISIONE === $richiesta->getTipo() ? 'firmatari: indica chi ha preso visione.' : 'firmatari: indica chi ha firmato.');
        }

        $copiaEnte = null;
        $copiaFamiglia = null;
        if (RichiestaTipo::PRESA_VISIONE !== $richiesta->getTipo()) {
            if (null !== $documentoId) {
                if (RichiestaTipo::INVIO !== $richiesta->getTipo()) {
                    throw new DatiNonValidi('documentoId: dall\'archivio si può scegliere solo per le richieste di invio.');
                }
                $copiaFamiglia = PerId::trova($this->em, Documento::class, $documentoId);
                if (!$copiaFamiglia instanceof Documento || !$copiaFamiglia->getTenant()->getId()->equals($d->getFamiglia()->getId())) {
                    throw new DatiNonValidi('documentoId: documento non trovato.');
                }
                $this->access->require('documento:read', $copiaFamiglia);
                $contents = $this->documents->readContent($copiaFamiglia);
                $nomeFile = $copiaFamiglia->getNomeFileOriginale();
            }
            if (null === $contents) {
                throw new DatiNonValidi('file: allega la scansione o il file da consegnare.');
            }
            $nome = $this->nomeConsegna($d, $nomeFile ?? 'consegna.pdf');
            $copiaEnte = $this->files->store($richiesta->getTenant(), $contents, $nome);
            if (null === $copiaFamiglia) {
                $categoria = $this->categorie->findSystemBySlug(self::CATEGORIA) ?? throw new \LogicException('Categoria condivisi_enti mancante: esegui app:categorie:sync.');
                $copiaFamiglia = $this->documents->upload($user, $d->getFamiglia(), $categoria, $this->persone($d), $contents, $nome,
                    sprintf('%s – %s', $richiesta->getTitolo(), $richiesta->getTenant()->getName()), new \DateTimeImmutable('today'))['documento'];
            }
            unset($contents);
        }

        $d->rispondi($user, $firmatari, $copiaEnte, $copiaFamiglia, $richiesta->getTipo()->richiedeVerifica());
        $d->setValidoFino($validoFino);
        $scadenza = $this->richieste->scadenza($d);
        if ($richiesta->getTipo()->richiedeVerifica()) {
            $scadenza?->segnaInVerifica();
        } else {
            $scadenza?->chiudi();
            $this->richieste->dopoCompletamento($d);
        }
        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            if (null !== $copiaEnte) {
                $this->files->delete($copiaEnte);
            }
            throw $e;
        }
        $this->richieste->logEntrambi('richiesta.risposta', $d, $user);
    }

    /** "Non posso firmare" / "non autorizzo": risposta valida, con messaggio all'ente. */
    public function rifiuta(RichiestaDestinatario $d, User $user, string $messaggio): void
    {
        $this->assertApribile($d);
        $messaggio = trim($messaggio);
        if ('' === $messaggio) {
            throw new DatiNonValidi('messaggio: spiega all\'ente il motivo.');
        }
        $d->rifiuta($user, mb_substr($messaggio, 0, 1000));
        $this->richieste->scadenza($d)?->chiudi();
        $this->em->flush();
        $this->richieste->logEntrambi('richiesta.rifiutata', $d, $user);
    }

    private function assertApribile(RichiestaDestinatario $d): void
    {
        if ($d->getRichiesta()->isAnnullata() || !$d->getStato()->isDaFare()) {
            throw new Conflitto('A questa richiesta non si può più rispondere.');
        }
        $this->access->require('richiesta:respond', $d);
    }

    /** @return list<MembroFamiglia> persone abbinate dalla famiglia a questo iscritto */
    private function persone(RichiestaDestinatario $d): array
    {
        $persone = [];
        foreach ($d->getReferenti() as $r) {
            $c = $this->em->getRepository(Collegamento::class)->findOneBy(['referente' => $r]);
            if ($c instanceof Collegamento && null !== $c->getPersona() && $c->getFamiglia()->getId()->equals($d->getFamiglia()->getId())) {
                $persone[(string) $c->getPersona()->getId()] = $c->getPersona();
            }
        }

        return array_values($persone);
    }

    private function nomeConsegna(RichiestaDestinatario $d, string $nomeFile): string
    {
        $ext = pathinfo($nomeFile, \PATHINFO_EXTENSION) ?: 'pdf';

        return sprintf('%s - %s.%s', $d->getIscritto()->getNomeCompleto(), $d->getRichiesta()->getTitolo(), strtolower($ext));
    }
}
