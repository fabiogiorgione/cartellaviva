<?php

namespace App\Documenti\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Documenti\Entity\Categoria;
use App\Documenti\State\CategoriaProcessor;
use App\Documenti\State\CategoriaProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Categorie usabili nel tenant: di sistema (sola lettura) + personalizzate.
 *   GET    /api/tenants/{tenantId}/categorie        tenant:read
 *   POST   /api/tenants/{tenantId}/categorie        categoria:manage
 *   GET    /api/tenants/{tenantId}/categorie/{id}   tenant:read
 *   PATCH  /api/tenants/{tenantId}/categorie/{id}   categoria:manage (solo personalizzate)
 *   DELETE /api/tenants/{tenantId}/categorie/{id}   categoria:manage (solo personalizzate; con documenti serve ?spostaIn={id})
 */
#[ApiResource(
    shortName: 'Categoria',
    operations: [
        new GetCollection(uriTemplate: '/tenants/{tenantId}/categorie', uriVariables: ['tenantId'], provider: CategoriaProvider::class),
        new Post(uriTemplate: '/tenants/{tenantId}/categorie', uriVariables: ['tenantId'], read: false, processor: CategoriaProcessor::class),
        new Get(uriTemplate: '/tenants/{tenantId}/categorie/{id}', uriVariables: ['tenantId', 'id'], provider: CategoriaProvider::class),
        new Patch(uriTemplate: '/tenants/{tenantId}/categorie/{id}', uriVariables: ['tenantId', 'id'], provider: CategoriaProvider::class, processor: CategoriaProcessor::class),
        new Delete(uriTemplate: '/tenants/{tenantId}/categorie/{id}', uriVariables: ['tenantId', 'id'], provider: CategoriaProvider::class, processor: CategoriaProcessor::class),
    ],
    normalizationContext: ['groups' => ['categoria:read']],
    denormalizationContext: ['groups' => ['categoria:write']],
)]
final class CategoriaResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['categoria:read'])]
    public ?string $id = null;

    #[ApiProperty(identifier: false)]
    #[Groups(['categoria:read'])]
    public ?string $tenantId = null;

    #[Groups(['categoria:read', 'categoria:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    public string $nome = '';

    #[Groups(['categoria:read', 'categoria:write'])]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9_-]{1,50}$/', message: 'Icona non valida (nome icona Lucide, es. "file-text")')]
    public string $icona = 'file-text';

    #[Groups(['categoria:read', 'categoria:write'])]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Colore non valido (formato #RRGGBB)')]
    public string $colore = '#64748b';

    /** Se vero i documenti caricati in questa categoria vengono cifrati. */
    #[Groups(['categoria:read', 'categoria:write'])]
    public bool $isSensibile = false;

    #[Groups(['categoria:read'])]
    public bool $sistema = false;

    /** Documenti della famiglia in questa categoria (solo nell'elenco; null se l'utente non la vede). */
    #[Groups(['categoria:read'])]
    public ?int $documenti = null;

    public static function fromEntity(Categoria $categoria, string $tenantId): self
    {
        $r = new self();
        $r->id = (string) $categoria->getId();
        $r->tenantId = $tenantId;
        $r->nome = $categoria->getNome();
        $r->icona = $categoria->getIcona();
        $r->colore = $categoria->getColore();
        $r->isSensibile = $categoria->isSensibile();
        $r->sistema = $categoria->isSystem();

        return $r;
    }
}
