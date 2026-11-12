<?php

namespace App\Pratiche\Entity;

use App\Documenti\Entity\Documento;
use App\Pratiche\Enum\VoceOrigine;
use App\Pratiche\Enum\VoceStato;
use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Entity\MembroFamiglia;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Voce di una pratica: un requisito soddisfatto da un documento (o a mano).
 * I valori (importo, data, tipo, emittente...) e la nota sono un JSON cifrato
 * con la chiave della pratica (vedi PraticaCifratura): qui solo il blob.
 * Un documento può dare più voci (una CU con più redditi).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pratica_voce')]
#[ORM\Index(name: 'idx_pratica_voce_pratica', columns: ['pratica_id', 'stato'])]
#[ORM\HasLifecycleCallbacks]
class PraticaVoce
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Pratica::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Pratica $pratica;

    #[ORM\Column(length: 60)]
    private string $requisito;

    #[ORM\ManyToOne(targetEntity: MembroFamiglia::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MembroFamiglia $persona = null;

    #[ORM\ManyToOne(targetEntity: Documento::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Documento $documento = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $valoriCifrati;

    #[ORM\Column(length: 20, enumType: VoceStato::class)]
    private VoceStato $stato;

    #[ORM\Column(length: 20, enumType: VoceOrigine::class)]
    private VoceOrigine $origine;

    public function __construct(Pratica $pratica, string $requisito, ?MembroFamiglia $persona, ?Documento $documento, VoceStato $stato, VoceOrigine $origine, ?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->pratica = $pratica;
        $this->requisito = $requisito;
        $this->persona = $persona;
        $this->documento = $documento;
        $this->stato = $stato;
        $this->origine = $origine;
        $this->valoriCifrati = '';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPratica(): Pratica
    {
        return $this->pratica;
    }

    public function getRequisito(): string
    {
        return $this->requisito;
    }

    public function setRequisito(string $requisito): void
    {
        $this->requisito = $requisito;
    }

    public function getPersona(): ?MembroFamiglia
    {
        return $this->persona;
    }

    public function setPersona(?MembroFamiglia $persona): void
    {
        $this->persona = $persona;
    }

    public function getDocumento(): ?Documento
    {
        return $this->documento;
    }

    public function setDocumento(?Documento $documento): void
    {
        $this->documento = $documento;
    }

    public function getValoriCifrati(): string
    {
        return $this->valoriCifrati;
    }

    public function setValoriCifrati(string $blob): void
    {
        $this->valoriCifrati = $blob;
    }

    public function getStato(): VoceStato
    {
        return $this->stato;
    }

    public function setStato(VoceStato $stato): void
    {
        $this->stato = $stato;
    }

    public function getOrigine(): VoceOrigine
    {
        return $this->origine;
    }
}
