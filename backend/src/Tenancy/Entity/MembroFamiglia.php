<?php

namespace App\Tenancy\Entity;

use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\User;
use App\Shared\Entity\Contract\OwnedResourceInterface;
use App\Shared\Entity\Contract\TenantOwnedTrait;
use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Permission\Attribute\PermissionResource;
use App\Tenancy\Repository\MembroFamigliaRepository;
use App\Tenancy\Validator\ValidMembroFamiglia;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Persona reale della famiglia, SENZA un proprio account (es. un figlio minore).
 * Serve a indicare a chi si riferisce un documento. I dati anagrafici stanno in
 * un'Anagrafica dedicata, validata con il gruppo "membro_famiglia" (solo il nome
 * è obbligatorio, nessun limite di età, codice fiscale facoltativo ma verificato).
 *
 * Se la persona ha anche un account (es. il coniuge) il membro può esservi
 * collegato con "user", in modo facoltativo.
 */
#[ORM\Entity(repositoryClass: MembroFamigliaRepository::class)]
#[ORM\Table(name: 'membro_famiglia')]
#[ORM\HasLifecycleCallbacks]
#[ValidMembroFamiglia(groups: [self::VALIDATION_GROUP])]
#[PermissionResource('membro_famiglia', 'Membri della famiglia', [
    'read' => 'Visualizza i membri della famiglia (anche senza account)',
    'manage' => 'Aggiunge, modifica e rimuove i membri della famiglia',
])]
class MembroFamiglia implements OwnedResourceInterface
{
    use TenantOwnedTrait;
    use TimestampableTrait;

    public const VALIDATION_GROUP = 'membro_famiglia';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Anagrafica::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\Valid]
    private Anagrafica $anagrafica;

    /** Relazione in testo libero ("figlio", "figlia", "coniuge", "nonna"...). */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, groups: ['Default', self::VALIDATION_GROUP])]
    private ?string $relazione = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    public function __construct(Tenant $tenant, User $owner, Anagrafica $anagrafica)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->owner = $owner;
        $this->anagrafica = $anagrafica;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAnagrafica(): Anagrafica
    {
        return $this->anagrafica;
    }

    public function getRelazione(): ?string
    {
        return $this->relazione;
    }

    public function setRelazione(?string $relazione): void
    {
        $relazione = null !== $relazione ? trim($relazione) : null;
        $this->relazione = '' === $relazione ? null : $relazione;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function getDisplayName(): string
    {
        return $this->anagrafica->getFullName(false);
    }
}
