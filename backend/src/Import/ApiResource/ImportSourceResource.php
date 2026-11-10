<?php

namespace App\Import\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Import\Entity\ImportSource;
use App\Import\Enum\ImportProvider;
use App\Import\Enum\ImportStrategia;
use App\Import\State\ImportSourceProcessor;
use App\Import\State\ImportSourceProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sorgenti di import del tenant (tutte le operazioni richiedono import:manage).
 *   GET    /api/tenants/{tenantId}/import-sources
 *   POST   /api/tenants/{tenantId}/import-sources         422 "Provider non configurato" senza credenziali OAuth
 *   GET    /api/tenants/{tenantId}/import-sources/{id}
 *   DELETE /api/tenants/{tenantId}/import-sources/{id}    i documenti importati restano
 * Collegamento e sincronizzazione: App\Import\Controller\ImportSourceController.
 * I token OAuth non sono mai esposti.
 */
#[ApiResource(
    shortName: 'ImportSource',
    operations: [
        new GetCollection(uriTemplate: '/tenants/{tenantId}/import-sources', uriVariables: ['tenantId'], provider: ImportSourceProvider::class),
        new Post(uriTemplate: '/tenants/{tenantId}/import-sources', uriVariables: ['tenantId'], read: false, processor: ImportSourceProcessor::class),
        new Get(uriTemplate: '/tenants/{tenantId}/import-sources/{id}', uriVariables: ['tenantId', 'id'], provider: ImportSourceProvider::class),
        new Delete(uriTemplate: '/tenants/{tenantId}/import-sources/{id}', uriVariables: ['tenantId', 'id'], provider: ImportSourceProvider::class, processor: ImportSourceProcessor::class),
    ],
    normalizationContext: ['groups' => ['import:read']],
    denormalizationContext: ['groups' => ['import:write']],
)]
final class ImportSourceResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['import:read'])]
    public ?string $id = null;

    #[ApiProperty(identifier: false)]
    #[Groups(['import:read'])]
    public ?string $tenantId = null;

    #[Groups(['import:read', 'import:write'])]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'providers'], message: 'Provider non supportato.')]
    public string $provider = '';

    /** In scrittura: link della cartella copiato dal browser oppure il suo id. In lettura: l'id. */
    #[Groups(['import:read', 'import:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 500)]
    public string $cartellaRadiceId = '';

    #[Groups(['import:read', 'import:write'])]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'strategie'], message: 'Strategia non valida (cartelle, ai_contenuto).')]
    public string $strategia = '';

    #[Groups(['import:read'])]
    public string $stato = '';

    #[Groups(['import:read'])]
    public bool $collegata = false;

    #[Groups(['import:read'])]
    public ?string $accountEsterno = null;

    #[Groups(['import:read'])]
    public bool $sincronizzazioneInCorso = false;

    #[Groups(['import:read'])]
    public ?\DateTimeImmutable $ultimaSincronizzazione = null;

    /** @var array<string, int>|null */
    #[Groups(['import:read'])]
    public ?array $ultimoEsito = null;

    #[Groups(['import:read'])]
    public ?string $ultimoErrore = null;

    #[Groups(['import:read'])]
    public ?string $creatoDa = null;

    #[Groups(['import:read'])]
    public ?\DateTimeImmutable $createdAt = null;

    /** @return list<string> */
    public static function providers(): array
    {
        return array_map(static fn (ImportProvider $p) => $p->value, ImportProvider::cases());
    }

    /** @return list<string> */
    public static function strategie(): array
    {
        return array_map(static fn (ImportStrategia $s) => $s->value, ImportStrategia::cases());
    }

    public static function fromEntity(ImportSource $source): self
    {
        $r = new self();
        $r->id = (string) $source->getId();
        $r->tenantId = (string) $source->getTenant()->getId();
        $r->provider = $source->getProvider()->value;
        $r->cartellaRadiceId = $source->getCartellaRadiceId();
        $r->strategia = $source->getStrategia()->value;
        $r->stato = $source->getStato()->value;
        $r->collegata = $source->isCollegata();
        $r->accountEsterno = $source->getAccountEsterno();
        $r->sincronizzazioneInCorso = $source->isSincronizzazioneInCorso();
        $r->ultimaSincronizzazione = $source->getUltimaSincronizzazione();
        $r->ultimoEsito = $source->getUltimoEsito();
        $r->ultimoErrore = $source->getUltimoErrore();
        $r->creatoDa = $source->getCreatoDa()->getEmail();
        $r->createdAt = $source->getCreatedAt();

        return $r;
    }
}
