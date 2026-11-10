<?php

namespace App\Tenancy\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\State\MembroFamigliaProcessor;
use App\Tenancy\State\MembroFamigliaProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Membri della famiglia senza account (es. figli minori).
 *   GET    /api/tenants/{tenantId}/membri_famiglia        membro_famiglia:read
 *   POST   /api/tenants/{tenantId}/membri_famiglia        membro_famiglia:manage
 *   GET    /api/tenants/{tenantId}/membri_famiglia/{id}   membro_famiglia:read
 *   PATCH  /api/tenants/{tenantId}/membri_famiglia/{id}   membro_famiglia:manage
 *   DELETE /api/tenants/{tenantId}/membri_famiglia/{id}   membro_famiglia:manage
 */
#[ApiResource(
    shortName: 'MembroFamiglia',
    operations: [
        new GetCollection(uriTemplate: '/tenants/{tenantId}/membri_famiglia', uriVariables: ['tenantId'], provider: MembroFamigliaProvider::class),
        new Post(uriTemplate: '/tenants/{tenantId}/membri_famiglia', uriVariables: ['tenantId'], read: false, validate: false, processor: MembroFamigliaProcessor::class),
        new Get(uriTemplate: '/tenants/{tenantId}/membri_famiglia/{id}', uriVariables: ['tenantId', 'id'], provider: MembroFamigliaProvider::class),
        new Patch(uriTemplate: '/tenants/{tenantId}/membri_famiglia/{id}', uriVariables: ['tenantId', 'id'], validate: false, provider: MembroFamigliaProvider::class, processor: MembroFamigliaProcessor::class),
        new Delete(uriTemplate: '/tenants/{tenantId}/membri_famiglia/{id}', uriVariables: ['tenantId', 'id'], provider: MembroFamigliaProvider::class, processor: MembroFamigliaProcessor::class),
    ],
    normalizationContext: ['groups' => ['membro_famiglia:read']],
    denormalizationContext: ['groups' => ['membro_famiglia:write']],
)]
final class MembroFamigliaResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['membro_famiglia:read'])]
    public ?string $id = null;

    #[ApiProperty(identifier: false)]
    #[Groups(['membro_famiglia:read'])]
    public ?string $tenantId = null;

    #[Groups(['membro_famiglia:read', 'membro_famiglia:write'])]
    public ?string $nome = null;

    #[Groups(['membro_famiglia:read', 'membro_famiglia:write'])]
    public ?string $cognome = null;

    #[Groups(['membro_famiglia:read', 'membro_famiglia:write'])]
    public ?string $sesso = null;

    /** Formato AAAA-MM-GG. */
    #[Groups(['membro_famiglia:read', 'membro_famiglia:write'])]
    #[Assert\Date]
    public ?string $dataNascita = null;

    /** Codice ISTAT dello stato di nascita (Italia = 100), come nell'Anagrafica. */
    #[Groups(['membro_famiglia:read', 'membro_famiglia:write'])]
    public ?int $nazioneNascita = null;

    /** Indice comune di nascita (GET /api/geo/comuni?storici=1), se nato in Italia. */
    #[Groups(['membro_famiglia:read', 'membro_famiglia:write'])]
    public ?int $comuneNascita = null;

    /** Facoltativo; se presente viene verificato (anche contro i dati, se sufficienti). */
    #[Groups(['membro_famiglia:read', 'membro_famiglia:write'])]
    public ?string $codiceFiscale = null;

    #[Groups(['membro_famiglia:read', 'membro_famiglia:write'])]
    public bool $codiceFiscaleManuale = false;

    /** Testo libero: "figlio", "figlia", "coniuge"... */
    #[Groups(['membro_famiglia:read', 'membro_famiglia:write'])]
    public ?string $relazione = null;

    /** Account collegato (facoltativo), es. il coniuge che ha anche un login. */
    #[Groups(['membro_famiglia:read', 'membro_famiglia:write'])]
    public ?string $userId = null;

    #[Groups(['membro_famiglia:read'])]
    public ?\DateTimeImmutable $createdAt = null;

    public static function fromEntity(MembroFamiglia $membro): self
    {
        $a = $membro->getAnagrafica();
        $r = new self();
        $r->id = (string) $membro->getId();
        $r->tenantId = (string) $membro->getTenant()->getId();
        $r->nome = $a->getNome();
        $r->cognome = $a->getCognome();
        $r->sesso = $a->getSesso();
        $r->dataNascita = $a->getDataDiNascita()?->format('Y-m-d');
        $r->nazioneNascita = $a->getNazioneDiNascita();
        $r->comuneNascita = $a->getComuneDiNascita();
        $r->codiceFiscale = $a->getCodiceFiscale();
        $r->codiceFiscaleManuale = (bool) $a->isCodiceFiscaleManuale();
        $r->relazione = $membro->getRelazione();
        $r->userId = $membro->getUser()?->getId()->toRfc4122();
        $r->createdAt = $membro->getCreatedAt();

        return $r;
    }
}
