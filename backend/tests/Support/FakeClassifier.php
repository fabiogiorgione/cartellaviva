<?php

namespace App\Tests\Support;

use App\Documenti\Classificazione\ClassificationResult;
use App\Documenti\Classificazione\ClassifierInterface;
use App\Documenti\Classificazione\NuovaCategoria;

/** Classificatore finto: restituisce la risposta impostata dal test, validata come quello vero. */
final class FakeClassifier implements ClassifierInterface
{
    public bool $enabled = true;
    public string $risposta = 'nessuna';

    /** Esito della verifica della categoria scelta. */
    public bool $verificata = true;

    /** @var list<array{categoria: string, descrizione: string}> */
    public array $callsVerifica = [];

    /** @var list<array{categoria: string, esempi: list<string>}> */
    public array $callsDescrizione = [];

    /** Risposta del secondo passo (categoria nuova): null = il modello non sa proporla. */
    public ?NuovaCategoria $nuova = null;

    /** @var list<array{nomeFile: string, testo: string, categorie: list<string>, esempi: array<string, list<string>>, indizi: list<string>}> */
    public array $calls = [];

    /** @var list<array{nomeFile: string, testo: string, indizi: list<string>, esistenti: list<string>}> */
    public array $callsNuova = [];

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function classify(string $nomeFile, string $testo, array $categorie, array $esempi = [], array $indizi = []): ClassificationResult
    {
        $this->calls[] = ['nomeFile' => $nomeFile, 'testo' => $testo, 'categorie' => $categorie, 'esempi' => $esempi, 'indizi' => $indizi];

        return new ClassificationResult(\in_array($this->risposta, $categorie, true) ? $this->risposta : null, $this->risposta, 'fake');
    }

    public function descriviCategoria(string $categoria, array $esempi): string
    {
        $this->callsDescrizione[] = ['categoria' => $categoria, 'esempi' => $esempi];

        return "documenti di tipo $categoria";
    }

    public function verifica(string $nomeFile, string $testo, string $categoria, string $descrizione, array $indizi): bool
    {
        $this->callsVerifica[] = ['categoria' => $categoria, 'descrizione' => $descrizione];

        return $this->verificata;
    }

    public function proponiNuova(string $nomeFile, string $testo, array $indizi, array $esistenti): ?NuovaCategoria
    {
        $this->callsNuova[] = ['nomeFile' => $nomeFile, 'testo' => $testo, 'indizi' => $indizi, 'esistenti' => $esistenti];

        return $this->nuova;
    }
}
