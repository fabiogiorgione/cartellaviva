<?php

namespace App\Pratiche\Entity;

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

    public function __construct(Pratica $pratica, Documento $documento, string $esito, string $metodo)
    {
        $this->pratica = $pratica;
        $this->documento = $documento;
        $this->esito = $esito;
        $this->metodo = $metodo;
        $this->analizzatoIl = new \DateTimeImmutable();
    }

    public function getEsito(): string
    {
        return $this->esito;
    }
}
