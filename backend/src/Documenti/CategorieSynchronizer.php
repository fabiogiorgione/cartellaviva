<?php

namespace App\Documenti;

use App\Documenti\Entity\Categoria;
use App\Documenti\Repository\CategoriaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Crea/aggiorna le categorie di sistema da config/packages/categorie.yaml
 * (idempotente, per slug). Le categorie personalizzate dei tenant non si toccano.
 */
final class CategorieSynchronizer
{
    /**
     * @param array<string, array{nome: string, icona: string, colore: string, sensibile: bool}> $definitions
     */
    public function __construct(
        private readonly CategoriaRepository $categorie,
        private readonly EntityManagerInterface $em,
        #[Autowire(param: 'app.categorie_sistema')]
        private readonly array $definitions,
    ) {
    }

    /** @return list<string> slug sincronizzati */
    public function synchronize(): array
    {
        foreach ($this->definitions as $slug => $d) {
            $categoria = $this->categorie->findSystemBySlug($slug);
            if (null === $categoria) {
                $this->em->persist(new Categoria(null, $d['nome'], $d['icona'], $d['colore'], (bool) $d['sensibile'], $slug));
            } else {
                $categoria->update($d['nome'], $d['icona'], $d['colore'], (bool) $d['sensibile']);
            }
        }
        $this->em->flush();

        return array_keys($this->definitions);
    }
}
