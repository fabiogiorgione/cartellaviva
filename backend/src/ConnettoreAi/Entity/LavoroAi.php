<?php

namespace App\ConnettoreAi\Entity;

use App\Documenti\Entity\Documento;
use App\Pratiche\Entity\Pratica;
use App\Tenancy\Entity\Tenant;
use App\ConnettoreAi\Repository\LavoroAiRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un'analisi da fare su un connettore. Niente testo né prompt: si costruiscono
 * all'assegnazione e vivono solo in memoria. Un lavoro concluso si cancella.
 */
#[ORM\Entity(repositoryClass: LavoroAiRepository::class)]
#[ORM\Table(name: 'lavoro_ai')]
#[ORM\UniqueConstraint(name: 'uniq_lavoro_ai', columns: ['tipo', 'pratica_id', 'documento_id'])]
#[ORM\Index(name: 'idx_lavoro_ai_tenant_stato', columns: ['tenant_id', 'stato'])]
class LavoroAi
{
    public const PRATICA_VOCI = 'pratica_voci';
    public const IN_ATTESA = 'in_attesa';
    public const ASSEGNATO = 'assegnato';
    public const LEASE_SECONDI = 300;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 20)]
    private string $tipo;

    #[ORM\ManyToOne(targetEntity: Pratica::class)]
    #[ORM\JoinColumn(name: 'pratica_id', nullable: false, onDelete: 'CASCADE')]
    private Pratica $pratica;

    #[ORM\ManyToOne(targetEntity: Documento::class)]
    #[ORM\JoinColumn(name: 'documento_id', nullable: false, onDelete: 'CASCADE')]
    private Documento $documento;

    #[ORM\Column(length: 10)]
    private string $stato = self::IN_ATTESA;

    #[ORM\ManyToOne(targetEntity: ConnettoreAi::class)]
    #[ORM\JoinColumn(name: 'connettore_id', nullable: true, onDelete: 'SET NULL')]
    private ?ConnettoreAi $connettore = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $assegnatoIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scadeIl = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $tentativi = 0;

    #[ORM\Column]
    private \DateTimeImmutable $creatoIl;

    public function __construct(Pratica $pratica, Documento $documento, string $tipo = self::PRATICA_VOCI)
    {
        $this->id = Uuid::v7();
        $this->tenant = $pratica->getTenant();
        $this->pratica = $pratica;
        $this->documento = $documento;
        $this->tipo = $tipo;
        $this->creatoIl = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getPratica(): Pratica
    {
        return $this->pratica;
    }

    public function getDocumento(): Documento
    {
        return $this->documento;
    }

    public function getStato(): string
    {
        return $this->stato;
    }

    public function getConnettore(): ?ConnettoreAi
    {
        return $this->connettore;
    }

    public function getScadeIl(): ?\DateTimeImmutable
    {
        return $this->scadeIl;
    }

    public function getTentativi(): int
    {
        return $this->tentativi;
    }

    public function getCreatoIl(): \DateTimeImmutable
    {
        return $this->creatoIl;
    }

    public function assegna(ConnettoreAi $c, \DateTimeImmutable $ora): void
    {
        $this->stato = self::ASSEGNATO;
        $this->connettore = $c;
        $this->assegnatoIl = $ora;
        $this->scadeIl = $ora->modify('+'.self::LEASE_SECONDI.' seconds');
    }

    public function proroga(\DateTimeImmutable $ora): void
    {
        $this->scadeIl = $ora->modify('+'.self::LEASE_SECONDI.' seconds');
    }

    /** Del connettore e ancora in tempo? */
    public function diConnettore(ConnettoreAi $c, \DateTimeImmutable $ora): bool
    {
        return self::ASSEGNATO === $this->stato && $this->connettore === $c && null !== $this->scadeIl && $this->scadeIl > $ora;
    }

    /** Torna in attesa (lease scaduto, errore, connettore revocato). */
    public function rimetti(bool $tentativo = true): void
    {
        $this->stato = self::IN_ATTESA;
        $this->connettore = null;
        $this->assegnatoIl = null;
        $this->scadeIl = null;
        if ($tentativo) {
            ++$this->tentativi;
        }
    }
}
