<?php

namespace App\Ente\Controller;

use App\Ente\DatiModello;
use App\Ente\EnteAccess;
use App\Ente\ModelliService;
use App\Ente\Repository\RichiestaDestinatarioRepository;
use App\Ente\Entity\ModelloRichiesta;
use App\Ente\Entity\Richiesta;
use App\Ente\Entity\RichiestaDestinatario;
use App\Ente\Enum\RichiestaTipo;
use App\Shared\Doctrine\PerId;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Modelli e regole" dell'ente e documenti ricevuti in scadenza.
 * Leggere: richiesta_ente:read; modificare: richiesta_ente:send. Le ricorrenze
 * (invio a tutto l'ente) richiedono anche ente:tutti_i_gruppi.
 */
final class ModelliController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly EnteAccess $access,
        private readonly EntityManagerInterface $em,
        private readonly ModelliService $modelli,
        private readonly RichiestaDestinatarioRepository $destinatari,
    ) {
    }

    #[Route('/api/enti/{tenantId}/modelli', name: 'api_ente_modelli', methods: ['GET'])]
    public function elenco(string $tenantId): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'richiesta_ente:read');

        return $this->json(array_map(self::vista(...), $this->em->getRepository(ModelloRichiesta::class)->findBy(['tenant' => $ente], ['nome' => 'ASC'])));
    }

    /**
     * Multipart o JSON: nome, tipo, titolo, istruzioni?, firmatari, chiediValidita?,
     * rinnovoGiorni?, conservazioneMesi?, giorniRisposta?, ricorrenzaGiorno?,
     * ricorrenzaMese?, file? (PDF). Con id nel percorso aggiorna.
     */
    #[Route('/api/enti/{tenantId}/modelli', name: 'api_ente_modello_crea', methods: ['POST'])]
    #[Route('/api/enti/{tenantId}/modelli/{id}', name: 'api_ente_modello_aggiorna', methods: ['POST'])]
    public function salva(string $tenantId, Request $request, ?string $id = null): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'richiesta_ente:send');
        $data = $request->files->count() > 0 || [] !== $request->request->all() ? $request->request->all() : $this->body($request);
        $tipo = RichiestaTipo::tryFrom((string) ($data['tipo'] ?? '')) ?? throw new UnprocessableEntityHttpException('tipo: firma, invio o presa_visione.');
        $nome = $this->str($data, 'nome', 120) ?? throw new UnprocessableEntityHttpException('nome: obbligatorio.');
        $titolo = $this->str($data, 'titolo', 200) ?? throw new UnprocessableEntityHttpException('titolo: obbligatorio.');
        $dati = new DatiModello(
            nome: $nome,
            tipo: $tipo,
            titolo: $titolo,
            istruzioni: $this->str($data, 'istruzioni', 2000),
            firmatari: $this->str($data, 'firmatari', 10) ?? Richiesta::FIRMATARI_TUTTI,
            chiediValidita: filter_var($data['chiediValidita'] ?? false, \FILTER_VALIDATE_BOOL),
            rinnovoGiorni: self::int($data, 'rinnovoGiorni', 1, 365),
            conservazioneMesi: self::int($data, 'conservazioneMesi', 1, 600),
            giorniRisposta: self::int($data, 'giorniRisposta', 1, 120) ?? 14,
            ricorrenzaGiorno: self::int($data, 'ricorrenzaGiorno', 1, 31),
            ricorrenzaMese: self::int($data, 'ricorrenzaMese', 1, 12),
            modalitaFirma: $this->str($data, 'modalitaFirma', 10) ?? Richiesta::FIRMA_CARTACEA,
        );
        // L'invio ricorrente va a tutto l'ente: serve poter vedere tutti i gruppi.
        if (null !== $dati->ricorrenzaGiorno && !$this->access->vedeTutto($ente)) {
            throw new UnprocessableEntityHttpException('ricorrenza: l\'invio ricorrente a tutto l\'ente lo imposta la segreteria.');
        }
        $file = $request->files->get('file');
        $pdf = $file instanceof UploadedFile ? ['contenuto' => (string) file_get_contents($file->getPathname()), 'nome' => $file->getClientOriginalName()] : null;
        $modello = $this->modelli->salva($ente, null !== $id ? $this->modello($ente, $id) : null, $dati, $pdf, $this->access->currentUser());

        return $this->json(self::vista($modello), null === $id ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/api/enti/{tenantId}/modelli/{id}', name: 'api_ente_modello_elimina', methods: ['DELETE'])]
    public function elimina(string $tenantId, string $id): Response
    {
        $ente = $this->access->requireEnte($tenantId, 'richiesta_ente:send');
        $this->modelli->elimina($this->modello($ente, $id));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Documenti consegnati che scadono entro N giorni (default 30), nell'ambito dell'utente. */
    #[Route('/api/enti/{tenantId}/documenti-in-scadenza', name: 'api_ente_documenti_scadenza', methods: ['GET'])]
    public function inScadenza(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'richiesta_ente:read');
        $giorni = max(1, min(365, $request->query->getInt('giorni', 30)));
        $righe = $this->destinatari->inScadenza($ente, new \DateTimeImmutable(sprintf('+%d days', $giorni)));
        $visibili = array_filter($righe, fn (RichiestaDestinatario $d) => $this->access->vedeTutto($ente) || (null !== $d->getRichiesta()->getGruppo() && $this->access->puoVedereGruppo($d->getRichiesta()->getGruppo())));

        return $this->json(array_values(array_map(static fn (RichiestaDestinatario $d) => [
            'iscritto' => ['id' => (string) $d->getIscritto()->getId(), 'nome' => $d->getIscritto()->getNomeCompleto()],
            'richiestaId' => (string) $d->getRichiesta()->getId(),
            'titolo' => $d->getRichiesta()->getTitolo(),
            'validoFino' => $d->getValidoFino()?->format('Y-m-d'),
            'scaduto' => $d->getValidoFino() < new \DateTimeImmutable('today'),
            'rinnovoAutomatico' => null !== $d->getRichiesta()->getRinnovoGiorni(),
            'rinnovataIl' => $d->getRinnovataIl()?->format(\DATE_ATOM),
        ], $visibili)));
    }

    /** @return array<string, mixed> */
    public static function vista(ModelloRichiesta $m): array
    {
        return [
            'id' => (string) $m->getId(),
            'nome' => $m->getNome(),
            'tipo' => $m->getTipo()->value,
            'titolo' => $m->getTitolo(),
            'istruzioni' => $m->getIstruzioni(),
            'firmatari' => $m->getFirmatari(),
            'allegato' => null !== $m->getAllegato() ? ['nome' => $m->getAllegato()->getNomeFile(), 'dimensione' => $m->getAllegato()->getDimensione()] : null,
            'chiediValidita' => $m->isChiediValidita(),
            'modalitaFirma' => $m->getModalitaFirma(),
            'rinnovoGiorni' => $m->getRinnovoGiorni(),
            'conservazioneMesi' => $m->getConservazioneMesi(),
            'giorniRisposta' => $m->getGiorniRisposta(),
            'ricorrenzaGiorno' => $m->getRicorrenzaGiorno(),
            'ricorrenzaMese' => $m->getRicorrenzaMese(),
            'prossimoInvio' => $m->getProssimoInvio()?->format('Y-m-d'),
        ];
    }

    private function modello(Tenant $ente, string $id): ModelloRichiesta
    {
        $m = PerId::trova($this->em, ModelloRichiesta::class, $id);
        if (!$m instanceof ModelloRichiesta || !$m->getTenant()->getId()->equals($ente->getId())) {
            throw new NotFoundHttpException('Modello non trovato.');
        }

        return $m;
    }

    private static function int(array $data, string $key, int $min, int $max): ?int
    {
        $v = $data[$key] ?? null;
        if (null === $v || '' === $v) {
            return null;
        }
        $n = filter_var($v, \FILTER_VALIDATE_INT);
        if (false === $n || $n < $min || $n > $max) {
            throw new UnprocessableEntityHttpException(sprintf('%s: numero tra %d e %d.', $key, $min, $max));
        }

        return $n;
    }
}
