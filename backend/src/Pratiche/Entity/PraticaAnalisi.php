<?php

namespace App\Pratiche\Entity;

use App\ConnettoreAi\Entity\ConnettoreAi;
use App\Documenti\Entity\Documento;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un documento già analizzato per una pratica: la ricerca riprende da dove si
 * era fermata e non propone due volte le stesse voci. Esito: "voci" (ne ha
 * proposte), "nessuna", "errore".
 */
#[ORM\Entity]
#[ORM\Table(name: 'pratica_analisi')]
class PraticaAnalisi
{
    public const VOCI = 'voci';
    public const NESSUNA = 'nessuna';
    public const ERRORE = 'errore';

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Pratica::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Pratica $pratica;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Documento::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Documento $documento;

    #[ORM\Column(length: 10)]
    private string $esito;

    #[ORM\Column(length: 20)]
    private string $metodo;

    #[ORM\Column]
    private \DateTimeImmutable $analizzatoIl;

    /** Modello usato (nome completo), per il registro e le misure. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $modello;

    #[ORM\Column(nullable: true)]
    private ?int $durataMs;

    /** Il connettore (Mac) che l'ha fatta: il registro resta anche se il Mac viene eliminato. */
    #[ORM\ManyToOne(targetEntity: ConnettoreAi::class)]
    #[ORM\JoinColumn(name: 'connettore_ai_id', nullable: true, onDelete: 'SET NULL')]
    private ?ConnettoreAi $connettoreAi;

    public function __construct(Pratica $pratica, Documento $documento, string $esito, string $metodo, ?string $modello = null, ?int $durataMs = null, ?ConnettoreAi $connettoreAi = null)
    {
        $this->pratica = $pratica;
        $this->documento = $documento;
        $this->esito = $esito;
        $this->metodo = mb_substr($metodo, 0, 20);
        $this->analizzatoIl = new \DateTimeImmutable();
        $this->modello = null === $modello ? null : mb_substr($modello, 0, 100);
        $this->durataMs = $durataMs;
        $this->connettoreAi = $connettoreAi;
    }

    public function getEsito(): string
    {
        return $this->esito;
    }
}
