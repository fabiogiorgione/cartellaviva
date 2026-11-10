<?php

namespace App\Tenancy\Entity;

use App\Account\Entity\User;
use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Permission\Attribute\PermissionResource;
use App\Tenancy\Repository\TenantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenant')]
#[ORM\HasLifecycleCallbacks]
#[PermissionResource('tenant', 'Tenant', [
    'read' => 'Visualizza il tenant',
    'manage' => 'Modifica i dati del tenant',
], sensitive: false)]
class Tenant
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 20, enumType: TenantType::class)]
    private TenantType $type;

    /** Ruoli personalizzati con permessi granulari (le famiglie partono senza; gli enti li hanno sempre). */
    #[ORM\Column(options: ['default' => false])]
    private bool $modalitaAvanzata = false;

    /** Cancellazione richiesta: fino a $eliminazioneProgrammataIl il tenant è in sola lettura e si può annullare. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $eliminazioneRichiestaIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $eliminazioneProgrammataIl = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'eliminazione_richiesta_da_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $eliminazioneRichiestaDa = null;

    /** Eliminazione decisa dal portale: la annulla solo il portale, non il proprietario. */
    #[ORM\Column(options: ['default' => false])]
    private bool $eliminazioneDalPortale = false;

    public function __construct(string $name, TenantType $type)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isModalitaAvanzata(): bool
    {
        return TenantType::AZIENDA === $this->type || $this->modalitaAvanzata;
    }

    public function setModalitaAvanzata(bool $attiva): void
    {
        $this->modalitaAvanzata = $attiva;
    }

    public function isInEliminazione(): bool
    {
        return null !== $this->eliminazioneProgrammataIl;
    }

    public function getEliminazioneProgrammataIl(): ?\DateTimeImmutable
    {
        return $this->eliminazioneProgrammataIl;
    }

    public function getEliminazioneRichiestaIl(): ?\DateTimeImmutable
    {
        return $this->eliminazioneRichiestaIl;
    }

    public function getEliminazioneRichiestaDa(): ?User
    {
        return $this->eliminazioneRichiestaDa;
    }

    public function isEliminazioneDalPortale(): bool
    {
        return $this->eliminazioneDalPortale;
    }

    public function requestDeletion(User $by, \DateTimeImmutable $programmataIl, bool $dalPortale = false): void
    {
        $this->eliminazioneRichiestaIl = new \DateTimeImmutable();
        $this->eliminazioneProgrammataIl = $programmataIl;
        $this->eliminazioneRichiestaDa = $by;
        $this->eliminazioneDalPortale = $dalPortale;
    }

    public function cancelDeletion(): void
    {
        $this->eliminazioneRichiestaIl = null;
        $this->eliminazioneProgrammataIl = null;
        $this->eliminazioneRichiestaDa = null;
        $this->eliminazioneDalPortale = false;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getType(): TenantType
    {
        return $this->type;
    }
}
