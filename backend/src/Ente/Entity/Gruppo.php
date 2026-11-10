<?php

namespace App\Ente\Entity;

use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Classe, corso, squadra o turno, in un anno. Un iscritto può stare in più
 * gruppi. Gli operatori assegnati vedono iscritti e richieste del gruppo
 * (la segreteria, con ente:tutti_i_gruppi, li vede tutti).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ente_gruppo')]
#[ORM\Index(name: 'idx_ente_gruppo_anno', columns: ['anno_id'])]
class Gruppo implements TenantScopedInterface
{
    public const TIPO_CLASSE = 'classe';
    public const TIPO_ATTIVITA = 'attivita';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: AnnoEnte::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AnnoEnte $anno;

    #[ORM\ManyToOne(targetEntity: Sede::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Sede $sede;

    #[ORM\Column(length: 80)]
    private string $nome;

    #[ORM\Column(length: 20)]
    private string $tipo;

    /** @var Collection<int, Membership> */
    #[ORM\ManyToMany(targetEntity: Membership::class)]
    #[ORM\JoinTable(name: 'ente_gruppo_operatore')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $operatori;

    public function __construct(Tenant $tenant, AnnoEnte $anno, string $nome, string $tipo = self::TIPO_CLASSE, ?Sede $sede = null)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->anno = $anno;
        $this->nome = $nome;
        $this->tipo = $tipo;
        $this->sede = $sede;
        $this->operatori = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getAnno(): AnnoEnte
    {
        return $this->anno;
    }

    public function getSede(): ?Sede
    {
        return $this->sede;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function aggiorna(string $nome, string $tipo, ?Sede $sede): void
    {
        $this->nome = $nome;
        $this->tipo = $tipo;
        $this->sede = $sede;
    }

    /** @return Collection<int, Membership> */
    public function getOperatori(): Collection
    {
        return $this->operatori;
    }

    /** @param iterable<Membership> $operatori */
    public function replaceOperatori(iterable $operatori): void
    {
        $this->operatori->clear();
        foreach ($operatori as $membership) {
            if (!$this->operatori->contains($membership)) {
                $this->operatori->add($membership);
            }
        }
    }
}
