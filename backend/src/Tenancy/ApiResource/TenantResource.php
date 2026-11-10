<?php

namespace App\Tenancy\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\State\TenantProcessor;
use App\Tenancy\State\TenantProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * GET   /api/tenants        tenant su cui ho una Membership attiva (superadmin: tutti)
 * GET   /api/tenants/{id}   richiede tenant:read
 * POST  /api/tenants        crea un tenant di cui divento owner (tipo "azienda" solo superadmin)
 * PATCH /api/tenants/{id}   richiede tenant:manage
 */
#[ApiResource(
    shortName: 'Tenant',
    operations: [
        new GetCollection(uriTemplate: '/tenants', provider: TenantProvider::class),
        new Get(uriTemplate: '/tenants/{id}', provider: TenantProvider::class),
        new Post(
            uriTemplate: '/tenants',
            denormalizationContext: ['groups' => ['tenant:write', 'tenant:create']],
            processor: TenantProcessor::class,
        ),
        new Patch(uriTemplate: '/tenants/{id}', provider: TenantProvider::class, processor: TenantProcessor::class),
    ],
    normalizationContext: ['groups' => ['tenant:read']],
    denormalizationContext: ['groups' => ['tenant:write']],
)]
final class TenantResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['tenant:read'])]
    public ?string $id = null;

    #[Groups(['tenant:read', 'tenant:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    public string $name = '';

    #[Groups(['tenant:read', 'tenant:create'])]
    public TenantType $type = TenantType::PRIVATO;

    /** Slug del mio ruolo nel tenant (null se accedo come superadmin senza membership). */
    #[Groups(['tenant:read'])]
    public ?string $myRole = null;

    #[Groups(['tenant:read'])]
    public ?\DateTimeImmutable $createdAt = null;

    /** Ruoli personalizzati (sempre true per gli enti). Spegnerla richiede che nessuno usi un ruolo personalizzato. */
    #[Groups(['tenant:read', 'tenant:write'])]
    public bool $modalitaAvanzata = false;

    /** Data dell'eliminazione definitiva, se richiesta: fino ad allora il tenant è in sola lettura. */
    #[Groups(['tenant:read'])]
    public ?\DateTimeImmutable $eliminazioneProgrammataIl = null;

    #[Groups(['tenant:read'])]
    public bool $solaLettura = false;

    /** Eliminazione decisa dal portale: il proprietario non può annullarla. */
    #[Groups(['tenant:read'])]
    public bool $eliminazioneDalPortale = false;

    /** Spazio occupato dai documenti (solo nel dettaglio del tenant). */
    #[Groups(['tenant:read'])]
    public ?int $spazioUsatoBytes = null;

    public static function fromEntity(Tenant $tenant, ?Membership $myMembership): self
    {
        $resource = new self();
        $resource->id = (string) $tenant->getId();
        $resource->name = $tenant->getName();
        $resource->type = $tenant->getType();
        $resource->myRole = $myMembership?->getRole()->getSlug();
        $resource->createdAt = $tenant->getCreatedAt();
        $resource->modalitaAvanzata = $tenant->isModalitaAvanzata();
        $resource->eliminazioneProgrammataIl = $tenant->getEliminazioneProgrammataIl();
        $resource->solaLettura = $tenant->isInEliminazione();
        $resource->eliminazioneDalPortale = $tenant->isEliminazioneDalPortale();

        return $resource;
    }
}
