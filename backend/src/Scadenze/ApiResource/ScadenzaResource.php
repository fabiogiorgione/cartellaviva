<?php

namespace App\Scadenze\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Enum\ScadenzaRicorrenza;
use App\Scadenze\State\ScadenzaProcessor;
use App\Scadenze\State\ScadenzaProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Scadenziario della famiglia.
 *   GET    /api/tenants/{tenantId}/scadenze            scadenza:read  (?da&a=AAAA-MM-GG&stato&documentoId&membroId)
 *   POST   /api/tenants/{tenantId}/scadenze            scadenza:manage (:own: diventa sua)
 *   GET    /api/tenants/{tenantId}/scadenze/{id}       scadenza:read
 *   PATCH  /api/tenants/{tenantId}/scadenze/{id}       scadenza:manage (own/all)
 *   DELETE /api/tenants/{tenantId}/scadenze/{id}       scadenza:manage (own/all)
 *   POST   /api/tenants/{tenantId}/scadenze/{id}/fatta App\Scadenze\Controller\ScadenzaController
 */
#[ApiResource(
    shortName: 'Scadenza',
    operations: [
        new GetCollection(uriTemplate: '/tenants/{tenantId}/scadenze', uriVariables: ['tenantId'], provider: ScadenzaProvider::class, paginationEnabled: false),
        new Post(uriTemplate: '/tenants/{tenantId}/scadenze', uriVariables: ['tenantId'], read: false, processor: ScadenzaProcessor::class),
        new Get(uriTemplate: '/tenants/{tenantId}/scadenze/{id}', uriVariables: ['tenantId', 'id'], provider: ScadenzaProvider::class),
        new Patch(uriTemplate: '/tenants/{tenantId}/scadenze/{id}', uriVariables: ['tenantId', 'id'], provider: ScadenzaProvider::class, processor: ScadenzaProcessor::class),
        new Delete(uriTemplate: '/tenants/{tenantId}/scadenze/{id}', uriVariables: ['tenantId', 'id'], provider: ScadenzaProvider::class, processor: ScadenzaProcessor::class),
    ],
    normalizationContext: ['groups' => ['scadenza:read']],
    denormalizationContext: ['groups' => ['scadenza:write']],
)]
final class ScadenzaResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['scadenza:read'])]
    public ?string $id = null;

    #[ApiProperty(identifier: false)]
    #[Groups(['scadenza:read'])]
    public ?string $tenantId = null;

    #[Groups(['scadenza:read', 'scadenza:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    public string $titolo = '';

    #[Groups(['scadenza:read', 'scadenza:write'])]
    #[Assert\Length(max: 2000)]
    public ?string $note = null;

    /** AAAA-MM-GG */
    #[Groups(['scadenza:read', 'scadenza:write'])]
    #[Assert\NotBlank]
    #[Assert\Date]
    public ?string $data = null;

    #[Groups(['scadenza:read', 'scadenza:write'])]
    #[Assert\Choice(callback: [self::class, 'ricorrenze'], message: 'Ricorrenza non valida (nessuna, mensile, annuale).')]
    public string $ricorrenza = 'nessuna';

    /** Giorni di anticipo del promemoria via email; null = nessun promemoria. */
    #[Groups(['scadenza:read', 'scadenza:write'])]
    #[Assert\Range(min: 0, max: 365)]
    public ?int $promemoriaGiorni = null;

    #[Groups(['scadenza:read', 'scadenza:write'])]
    public ?string $documentoId = null;

    /** @var array{id: string, titolo: string}|null */
    #[Groups(['scadenza:read'])]
    public ?array $documento = null;

    #[Groups(['scadenza:read'])]
    public bool $documentoRimosso = false;

    #[Groups(['scadenza:read', 'scadenza:write'])]
    public ?string $membroId = null;

    /** @var array{id: string, nome: string}|null */
    #[Groups(['scadenza:read'])]
    public ?array $membro = null;

    #[Groups(['scadenza:read', 'scadenza:write'])]
    public ?string $categoriaId = null;

    /** @var array{id: string, nome: string, icona: string, colore: string}|null */
    #[Groups(['scadenza:read'])]
    public ?array $categoria = null;

    #[Groups(['scadenza:read'])]
    public string $stato = 'aperta';

    #[Groups(['scadenza:read'])]
    public string $origine = 'manuale';

    /** Per origine "richiesta": id della richiesta nella pagina Richieste della famiglia. */
    #[Groups(['scadenza:read'])]
    public ?string $richiestaId = null;

    #[Groups(['scadenza:read'])]
    public bool $scaduta = false;

    /** Giorni alla scadenza (negativo se passata). */
    #[Groups(['scadenza:read'])]
    public int $giorniMancanti = 0;

    #[Groups(['scadenza:read'])]
    public ?\DateTimeImmutable $completataIl = null;

    #[Groups(['scadenza:read'])]
    public ?string $ownerId = null;

    #[Groups(['scadenza:read'])]
    public ?string $ownerName = null;

    /** @return list<string> */
    public static function ricorrenze(): array
    {
        return array_map(static fn (ScadenzaRicorrenza $r) => $r->value, ScadenzaRicorrenza::cases());
    }

    public static function fromEntity(Scadenza $s, ?\DateTimeImmutable $oggi = null): self
    {
        $oggi ??= new \DateTimeImmutable('today');
        $r = new self();
        $r->id = (string) $s->getId();
        $r->tenantId = (string) $s->getTenant()->getId();
        $r->titolo = $s->getTitolo();
        $r->note = $s->getNote();
        $r->data = $s->getData()->format('Y-m-d');
        $r->ricorrenza = $s->getRicorrenza()->value;
        $r->promemoriaGiorni = $s->getPromemoriaGiorni();
        $d = $s->getDocumento();
        $r->documentoId = null !== $d ? (string) $d->getId() : null;
        $r->documento = null !== $d ? ['id' => (string) $d->getId(), 'titolo' => $d->getTitolo()] : null;
        $r->documentoRimosso = $s->isDocumentoRimosso();
        $m = $s->getMembro();
        $r->membroId = null !== $m ? (string) $m->getId() : null;
        $r->membro = null !== $m ? ['id' => (string) $m->getId(), 'nome' => $m->getDisplayName()] : null;
        $c = $s->getCategoria();
        $r->categoriaId = null !== $c ? (string) $c->getId() : null;
        $r->categoria = null !== $c ? ['id' => (string) $c->getId(), 'nome' => $c->getNome(), 'icona' => $c->getIcona(), 'colore' => $c->getColore()] : null;
        $r->stato = $s->getStato()->value;
        $r->origine = $s->getOrigine()->value;
        $r->richiestaId = null !== $s->getRichiesta() ? (string) $s->getRichiesta()->getId() : null;
        $r->scaduta = $s->isScaduta($oggi);
        $r->giorniMancanti = (int) $oggi->diff($s->getData())->format('%r%a');
        $r->completataIl = $s->getCompletataIl();
        $r->ownerId = (string) $s->getOwner()->getId();
        $r->ownerName = $s->getOwner()->getFullName();

        return $r;
    }
}
