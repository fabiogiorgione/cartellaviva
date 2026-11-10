<?php

namespace App\Ente\Controller;

use App\Documenti\Anteprima\AnteprimaException;
use App\Documenti\UploadPolicy;
use App\Ente\EnteAccess;
use App\Ente\Entity\FileCifrato;
use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Iscritto;
use App\Ente\Entity\ModelloRichiesta;
use App\Ente\Entity\Richiesta;
use App\Ente\Entity\RichiestaDestinatario;
use App\Ente\Enum\DestinatarioStato;
use App\Ente\Enum\RichiestaTipo;
use App\Ente\FileCifratoService;
use App\Ente\RegoleRichiesta;
use App\Ente\RichiesteService;
use App\Shared\Audit\AuditLogger;
use App\Shared\Doctrine\PerId;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Http\JsonBodyTrait;
use App\Shared\Http\RispostaFile;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use ZipStream\ZipStream;

/**
 * Richieste lato ente. Visibilità: segreteria (ente:tutti_i_gruppi), operatori
 * del gruppo destinatario, chi l'ha creata. Tutti i file escono decifrati in
 * memoria e ogni lettura è registrata (richiesta_ente è "sensitive").
 */
final class RichiesteEnteController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly EnteAccess $access,
        private readonly EntityManagerInterface $em,
        private readonly RichiesteService $richieste,
        private readonly FileCifratoService $files,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Corpo: {gruppoId} oppure {iscrittoIds: [...]}. */
    #[Route('/api/enti/{tenantId}/richieste/anteprima', name: 'api_ente_richieste_anteprima', methods: ['POST'], priority: 10)]
    public function anteprima(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'richiesta_ente:send');
        [, $iscritti] = $this->destinazione($ente, $this->body($request));
        $piano = $this->richieste->pianifica($iscritti);
        unset($piano['piano']);

        return $this->json($piano);
    }

    /**
     * Multipart: tipo, titolo, istruzioni?, entroIl?, firmatari (tutti|uno),
     * gruppoId | iscrittoIds[] , file (PDF, obbligatorio per la firma).
     */
    #[Route('/api/enti/{tenantId}/richieste', name: 'api_ente_richieste_crea', methods: ['POST'])]
    public function crea(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'richiesta_ente:send');
        $data = $request->request->all();
        $tipo = RichiestaTipo::tryFrom((string) ($data['tipo'] ?? '')) ?? throw new UnprocessableEntityHttpException('tipo: firma, invio o presa_visione.');
        $titolo = $this->str($data, 'titolo', 200) ?? throw new UnprocessableEntityHttpException('titolo: obbligatorio.');
        [$gruppo, $iscritti] = $this->destinazione($ente, $data);

        // Da un modello: regole (validità, rinnovo, conservazione) e PDF se non se ne carica un altro.
        $modello = null;
        if (null !== ($mid = $this->str($data, 'modelloId', 36))) {
            $modello = PerId::trova($this->em, ModelloRichiesta::class, $mid);
            if (!$modello instanceof ModelloRichiesta || !$modello->getTenant()->getId()->equals($ente->getId())) {
                throw new NotFoundHttpException('Modello non trovato.');
            }
        }
        $regole = null !== $modello
            ? RegoleRichiesta::daModello($modello)
            : new RegoleRichiesta(null, filter_var($data['chiediValidita'] ?? false, \FILTER_VALIDATE_BOOL), self::intPositivo($data, 'rinnovoGiorni'), self::intPositivo($data, 'conservazioneMesi'), null,
                $this->str($data, 'modalitaFirma', 10) ?? Richiesta::FIRMA_CARTACEA);

        $caricato = null;
        $file = $request->files->get('file');
        if ($file instanceof UploadedFile) {
            if (!$file->isValid()) {
                throw new UnprocessableEntityHttpException('file: caricamento non riuscito.');
            }
            $caricato = $this->files->store($ente, (string) file_get_contents($file->getPathname()), $file->getClientOriginalName(), RichiestaTipo::FIRMA === $tipo);
        }
        try {
            $richiesta = $this->richieste->invia($ente, $this->access->currentUser(), $tipo, $titolo, $this->str($data, 'istruzioni', 2000), $this->date($data, 'entroIl'), $this->str($data, 'firmatari', 10) ?? Richiesta::FIRMATARI_TUTTI, $gruppo, $iscritti, $caricato ?? $modello?->getAllegato(), $regole);
        } catch (\Throwable $e) {
            if (null !== $caricato) {
                $this->files->delete($caricato);
            }
            throw $e;
        }

        return $this->json($this->riepilogo($richiesta), Response::HTTP_CREATED);
    }

    /**
     * Coda della verifica affiancata: le risposte "da verificare" delle richieste
     * visibili all'utente (tutto l'ente o i suoi gruppi), dalla più vecchia.
     * ?richiestaId= limita a una richiesta. Solo metadati: il file si apre a parte.
     */
    #[Route('/api/enti/{tenantId}/da-verificare', name: 'api_ente_da_verificare', methods: ['GET'])]
    public function daVerificare(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'richiesta_ente:verify');
        $solo = (string) $request->query->get('richiestaId', '');
        $criteri = ['tenant' => $ente, 'annullataIl' => null];
        if ('' !== $solo && Uuid::isValid($solo)) {
            $criteri['id'] = Uuid::fromString($solo);
        }
        $coda = [];
        foreach ($this->em->getRepository(Richiesta::class)->findBy($criteri, ['createdAt' => 'ASC'], 200) as $r) {
            if (!$this->visibile($r)) {
                continue;
            }
            foreach ($r->getDestinatari() as $d) {
                if (DestinatarioStato::DA_VERIFICARE !== $d->getStato()) {
                    continue;
                }
                $file = $d->getRisposta();
                $coda[] = [
                    'richiesta' => [
                        'id' => (string) $r->getId(),
                        'tipo' => $r->getTipo()->value,
                        'titolo' => $r->getTitolo(),
                        'istruzioni' => $r->getIstruzioni(),
                        'firmatari' => $r->getFirmatari(),
                        'chiediValidita' => $r->isChiediValidita(),
                        'haAllegato' => null !== $r->getAllegato(),
                        'gruppo' => $r->getGruppo()?->getNome(),
                    ],
                    'destinatario' => [
                        'id' => (string) $d->getId(),
                        'iscritto' => $d->getIscritto()->getNomeCompleto(),
                        'referenti' => array_values(array_map(static fn ($ref) => ['nome' => $ref->getNomeCompleto(), 'firmato' => \in_array((string) $ref->getId(), $d->getFirmatari(), true)], $d->getReferenti()->toArray())),
                        'messaggioFamiglia' => $d->getMessaggioFamiglia(),
                        'rispostaIl' => $d->getRispostaIl()?->format(\DATE_ATOM),
                        'validoFino' => $d->getValidoFino()?->format('Y-m-d'),
                        'viaLink' => $d->isViaLink(),
                        'file' => null !== $file ? ['nome' => $file->getNomeFile(), 'mimeType' => $file->getMimeType(), 'dimensione' => $file->getDimensione()] : null,
                    ],
                ];
            }
        }
        usort($coda, static fn ($a, $b) => (string) $a['destinatario']['rispostaIl'] <=> (string) $b['destinatario']['rispostaIl']);

        return $this->json($coda);
    }

    /** ?aperte=1 per le sole non annullate con risposte mancanti o da verificare. */
    #[Route('/api/enti/{tenantId}/richieste', name: 'api_ente_richieste', methods: ['GET'])]
    public function elenco(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'richiesta_ente:read');
        $tutte = $this->em->getRepository(Richiesta::class)->findBy(['tenant' => $ente], ['createdAt' => 'DESC'], 200);
        $visibili = array_values(array_filter($tutte, $this->visibile(...)));
        $righe = array_map($this->riepilogo(...), $visibili);
        if ($request->query->getBoolean('aperte')) {
            $righe = array_values(array_filter($righe, static fn ($r) => !$r['annullata'] && ($r['totali']['mancanti'] + $r['totali']['daVerificare']) > 0));
        }

        return $this->json($righe);
    }

    #[Route('/api/enti/{tenantId}/richieste/{id}', name: 'api_ente_richiesta', methods: ['GET'])]
    public function dettaglio(string $tenantId, string $id): JsonResponse
    {
        $richiesta = $this->richiesta($tenantId, $id, 'richiesta_ente:read');

        return $this->json($this->riepilogo($richiesta) + ['avanzamento' => $this->richieste->avanzamento($richiesta)['iscritti'], 'nonRaggiunti' => $richiesta->getNonRaggiunti()]);
    }

    #[Route('/api/enti/{tenantId}/richieste/{id}/allegato', name: 'api_ente_richiesta_allegato', methods: ['GET'])]
    public function allegato(string $tenantId, string $id): Response
    {
        $richiesta = $this->richiesta($tenantId, $id, 'richiesta_ente:read');

        return $this->fileResponse($richiesta->getAllegato() ?? throw new NotFoundHttpException('Nessun allegato.'));
    }

    #[Route('/api/enti/{tenantId}/richieste/{id}/destinatari/{did}/file', name: 'api_ente_risposta_file', methods: ['GET'])]
    public function risposta(string $tenantId, string $id, string $did): Response
    {
        $d = $this->destinatario($this->richiesta($tenantId, $id, 'richiesta_ente:read'), $did);
        $this->audit->log('richiesta.risposta_scaricata', AuditOutcome::SUCCESS, resourceType: 'ente_richiesta_destinatario', resourceId: (string) $d->getId(), tenantId: $d->getRichiesta()->getTenant()->getId());

        return $this->fileResponse($d->getRisposta() ?? throw new NotFoundHttpException('Nessun file consegnato.'));
    }

    /**
     * Anteprima della risposta (verifica affiancata): PDF e immagini come sono,
     * Office → PDF e HEIC → JPEG (copia cifrata creata alla prima apertura).
     * 409 con il motivo se la copia non si può creare: resta "Apri a parte".
     */
    #[Route('/api/enti/{tenantId}/richieste/{id}/destinatari/{did}/anteprima', name: 'api_ente_risposta_anteprima', methods: ['GET'])]
    public function rispostaAnteprima(string $tenantId, string $id, string $did): Response
    {
        $d = $this->destinatario($this->richiesta($tenantId, $id, 'richiesta_ente:read'), $did);
        $file = $d->getRisposta() ?? throw new NotFoundHttpException('Nessun file consegnato.');
        try {
            [$contenuto, $tipo] = $this->files->readAnteprima($file);
        } catch (AnteprimaException $e) {
            return $this->json(['anteprimaErrore' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        $this->audit->log('richiesta.risposta_vista', AuditOutcome::SUCCESS, resourceType: 'ente_richiesta_destinatario', resourceId: (string) $d->getId(), tenantId: $d->getRichiesta()->getTenant()->getId());

        return RispostaFile::inline($contenuto, $tipo, 'anteprima.'.UploadPolicy::extensionFor($tipo));
    }

    /** Corpo: {"esito": "accetta"|"rifirma", "motivo"?: "..."}. */
    #[Route('/api/enti/{tenantId}/richieste/{id}/destinatari/{did}/verifica', name: 'api_ente_risposta_verifica', methods: ['POST'])]
    public function verifica(string $tenantId, string $id, string $did, Request $request): JsonResponse
    {
        $richiesta = $this->richiesta($tenantId, $id, 'richiesta_ente:verify');
        $d = $this->destinatario($richiesta, $did);
        $body = $this->body($request);
        $esito = (string) ($body['esito'] ?? '');
        if (!\in_array($esito, ['accetta', 'rifirma'], true)) {
            throw new UnprocessableEntityHttpException('esito: accetta o rifirma.');
        }
        $this->richieste->verifica($d, 'accetta' === $esito, $this->str($body, 'motivo', 500), $this->access->currentUser());

        return $this->json($this->riepilogo($richiesta));
    }

    /** Corpo facoltativo: {"destinatarioIds": [...]} (default: tutti quelli che mancano). */
    #[Route('/api/enti/{tenantId}/richieste/{id}/sollecito', name: 'api_ente_richiesta_sollecito', methods: ['POST'])]
    public function sollecito(string $tenantId, string $id, Request $request): JsonResponse
    {
        $richiesta = $this->richiesta($tenantId, $id, 'richiesta_ente:send');
        $ids = $this->body($request)['destinatarioIds'] ?? null;
        $solo = \is_array($ids) ? array_map(fn ($did) => $this->destinatario($richiesta, (string) $did), $ids) : null;

        return $this->json(['sollecitati' => $this->richieste->sollecita($richiesta, $solo, $this->access->currentUser())]);
    }

    #[Route('/api/enti/{tenantId}/richieste/{id}/annulla', name: 'api_ente_richiesta_annulla', methods: ['POST'])]
    public function annulla(string $tenantId, string $id): JsonResponse
    {
        $richiesta = $this->richiesta($tenantId, $id, 'richiesta_ente:send');
        $this->richieste->annulla($richiesta, $this->access->currentUser());

        return $this->json($this->riepilogo($richiesta));
    }

    /** ZIP delle risposte accettate, generato al volo (niente copie in chiaro sul server). */
    #[Route('/api/enti/{tenantId}/richieste/{id}/zip', name: 'api_ente_richiesta_zip', methods: ['GET'])]
    public function zip(string $tenantId, string $id): Response
    {
        $richiesta = $this->richiesta($tenantId, $id, 'richiesta_ente:read');
        $completate = array_filter($richiesta->getDestinatari()->toArray(), static fn (RichiestaDestinatario $d) => DestinatarioStato::COMPLETATA === $d->getStato() && null !== $d->getRisposta());
        $this->audit->log('richiesta.zip_scaricato', AuditOutcome::SUCCESS, resourceType: 'ente_richiesta', resourceId: (string) $richiesta->getId(), tenantId: $richiesta->getTenant()->getId(), context: ['file' => \count($completate)]);

        $response = new StreamedResponse(function () use ($completate): void {
            $zip = new ZipStream(outputStream: fopen('php://output', 'w'), sendHttpHeaders: false, defaultEnableZeroHeader: true, flushOutput: true);
            $usati = [];
            foreach ($completate as $d) {
                $nome = $d->getRisposta()->getNomeFile();
                for ($i = 2; isset($usati[$nome]); ++$i) {
                    $nome = preg_replace('/(\.[a-z0-9]+)?$/i', " ($i)$1", $d->getRisposta()->getNomeFile(), 1);
                }
                $usati[$nome] = true;
                $zip->addFile(fileName: $nome, data: $this->files->read($d->getRisposta()));
            }
            $zip->finish();
        });
        return RispostaFile::intestazioni($response, 'application/zip', 'risposte.zip');
    }

    // ---- Helpers ----------------------------------------------------------

    private static function intPositivo(array $data, string $key): ?int
    {
        $v = $data[$key] ?? null;
        if (null === $v || '' === $v) {
            return null;
        }
        $n = filter_var($v, \FILTER_VALIDATE_INT);
        if (false === $n || $n < 1 || $n > 3650) {
            throw new UnprocessableEntityHttpException($key.': numero non valido.');
        }

        return $n;
    }

    /** @return array<string, mixed> */
    private function riepilogo(Richiesta $r): array
    {
        $av = $this->richieste->avanzamento($r);

        return [
            'id' => (string) $r->getId(),
            'tipo' => $r->getTipo()->value,
            'titolo' => $r->getTitolo(),
            'istruzioni' => $r->getIstruzioni(),
            'entroIl' => $r->getEntroIl()?->format('Y-m-d'),
            'firmatari' => $r->getFirmatari(),
            'gruppo' => null !== $r->getGruppo() ? ['id' => (string) $r->getGruppo()->getId(), 'nome' => $r->getGruppo()->getNome()] : null,
            'allegato' => null !== $r->getAllegato() ? ['nome' => $r->getAllegato()->getNomeFile(), 'dimensione' => $r->getAllegato()->getDimensione()] : null,
            'creataDa' => $r->getCreatoDa()?->getFullName(),
            'createdAt' => $r->getCreatedAt()->format(\DATE_ATOM),
            'annullata' => $r->isAnnullata(),
            'modello' => null !== $r->getModello() ? ['id' => (string) $r->getModello()->getId(), 'nome' => $r->getModello()->getNome()] : null,
            'chiediValidita' => $r->isChiediValidita(),
            'modalitaFirma' => $r->getModalitaFirma(),
            'rinnovoGiorni' => $r->getRinnovoGiorni(),
            'conservazioneMesi' => $r->getConservazioneMesi(),
            // Nata da un rinnovo automatico (documento in scadenza).
            'rinnovo' => null !== $r->getRinnovoDi(),
            'totali' => $av['totali'],
        ];
    }

    private function visibile(Richiesta $r): bool
    {
        if ($this->access->vedeTutto($r->getTenant())) {
            return true;
        }
        if (null !== $r->getCreatoDa() && $r->getCreatoDa()->getId()->equals($this->access->currentUser()->getId())) {
            return true;
        }

        return null !== $r->getGruppo() && $this->access->puoVedereGruppo($r->getGruppo());
    }

    private function richiesta(string $tenantId, string $id, string $permission): Richiesta
    {
        $ente = $this->access->requireEnte($tenantId, $permission);
        $r = PerId::trova($this->em, Richiesta::class, $id);
        if (!$r instanceof Richiesta || !$r->getTenant()->getId()->equals($ente->getId()) || !$this->visibile($r)) {
            throw new NotFoundHttpException('Richiesta non trovata.');
        }
        $this->access->require($permission, $r);

        return $r;
    }

    private function destinatario(Richiesta $richiesta, string $did): RichiestaDestinatario
    {
        foreach ($richiesta->getDestinatari() as $d) {
            if ((string) $d->getId() === $did) {
                return $d;
            }
        }
        throw new NotFoundHttpException('Destinatario non trovato.');
    }

    /**
     * @return array{0: ?Gruppo, 1: list<Iscritto>}
     */
    private function destinazione(Tenant $ente, array $data): array
    {
        $gid = $this->str($data, 'gruppoId', 36);
        if (null !== $gid) {
            $gruppo = PerId::trova($this->em, Gruppo::class, $gid);
            if (!$gruppo instanceof Gruppo || !$gruppo->getTenant()->getId()->equals($ente->getId()) || !$this->access->puoVedereGruppo($gruppo)) {
                throw new NotFoundHttpException('Gruppo non trovato.');
            }
            $iscritti = $this->em->createQuery('SELECT i FROM '.Iscritto::class.' i WHERE :g MEMBER OF i.gruppi ORDER BY i.cognome, i.nome')->setParameter('g', $gruppo->getId(), 'uuid')->getResult();

            return [$gruppo, $iscritti];
        }
        $ids = $data['iscrittoIds'] ?? [];
        if (\is_string($ids)) {
            $ids = json_decode($ids, true) ?? explode(',', $ids);
        }
        $iscritti = [];
        foreach (\is_array($ids) ? $ids : [] as $iid) {
            $i = PerId::trova($this->em, Iscritto::class, $iid);
            if (!$i instanceof Iscritto || !$i->getTenant()->getId()->equals($ente->getId()) || !$this->access->puoVedereIscritto($i)) {
                throw new NotFoundHttpException('Iscritto non trovato.');
            }
            $iscritti[(string) $i->getId()] = $i;
        }
        if ([] === $iscritti) {
            throw new UnprocessableEntityHttpException('Indica un gruppo o gli iscritti destinatari.');
        }
        // Senza gruppo la richiesta la vedono solo la segreteria e chi la crea.
        return [null, array_values($iscritti)];
    }

    private function fileResponse(FileCifrato $file): Response
    {
        return RispostaFile::allegato($this->files->read($file), $file->getMimeType(), $file->getNomeFile());
    }
}
