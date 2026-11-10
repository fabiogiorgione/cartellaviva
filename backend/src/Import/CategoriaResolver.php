<?php

namespace App\Import;

use App\Documenti\Entity\Categoria;
use App\Documenti\Repository\CategoriaRepository;
use App\Import\Entity\ImportSource;
use App\Import\Enum\ImportStrategia;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Categoria di un file importato, secondo la strategia della sorgente.
 *
 * cartelle: il nome della sottocartella di primo livello si cerca, senza
 * distinguere maiuscole/minuscole, tra le categorie di sistema E del tenant
 * (una cartella "salute" finisce nella categoria di sistema Salute, sensibile).
 * Se non esiste si crea una categoria del tenant NON sensibile. I file
 * direttamente nella radice vanno in "Da categorizzare".
 *
 * ai_contenuto: sempre "Da categorizzare"; la classificazione avviene dopo l'OCR.
 */
final class CategoriaResolver
{
    public function __construct(
        private readonly CategoriaRepository $categorie,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function forFile(ImportSource $source, RemoteFile $file): Categoria
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', (string) $file->cartella));
        if (ImportStrategia::AI_CONTENUTO === $source->getStrategia() || '' === $nome) {
            return $this->daCategorizzare();
        }
        $nome = mb_substr($nome, 0, 80);

        $trovate = $this->categorie->findUsableByNome($source->getTenant(), $nome);
        if ([] !== $trovate) {
            // Se sistema e tenant hanno lo stesso nome vince la sensibile: nel dubbio si cifra.
            usort($trovate, static fn (Categoria $a, Categoria $b) => (int) $b->isSensibile() <=> (int) $a->isSensibile());

            return $trovate[0];
        }

        $categoria = new Categoria($source->getTenant(), $nome, 'folder', '#64748b', false);
        $this->em->persist($categoria);
        $this->em->flush();
        $this->audit->log('categoria.created', AuditOutcome::SUCCESS, resourceType: 'categoria', resourceId: (string) $categoria->getId(),
            tenantId: $source->getTenant()->getId(), context: ['nome' => $nome, 'sensibile' => false, 'origine' => 'import', 'import_source' => (string) $source->getId()],
            actor: $source->getCreatoDa());

        return $categoria;
    }

    public function daCategorizzare(): Categoria
    {
        return $this->categorie->findSystemBySlug(Categoria::SLUG_DA_CATEGORIZZARE)
            ?? throw new \LogicException('Categoria di sistema "da_categorizzare" assente: esegui bin/console app:categorie:sync.');
    }
}
