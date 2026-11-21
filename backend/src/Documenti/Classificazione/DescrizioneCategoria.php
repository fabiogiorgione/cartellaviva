<?php

namespace App\Documenti\Classificazione;

use App\Documenti\Entity\Categoria;
use App\Documenti\Entity\Documento;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Che cosa raccoglie una categoria: la verifica sì/no della scelta del modello
 * (proposta di categoria e classificazione degli import) confronta il documento
 * con questa descrizione.
 *
 * - Categoria di sistema: la descrizione scritta a mano in config/packages/categorie.yaml.
 *   Quella generata dal modello piccolo dimenticava generi interi (un rogito
 *   scartato da "Casa"); misura del 21/11/2026 su 87 casi inventati.
 * - Della famiglia o nuova: la scrive il modello dal nome e da qualche titolo,
 *   poi resta in cache finché non cambiano modello, nome o titoli.
 */
final class DescrizioneCategoria
{
    private const ESEMPI = 3;

    /**
     * @param array<string, array{descrizione?: string}> $sistema le categorie di sistema, per slug
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Connection $db,
        #[Autowire(param: 'app.categorie_sistema')]
        private readonly array $sistema,
    ) {
    }

    /**
     * @param ?Categoria   $esistente la categoria scelta, se esiste già (null: una categoria nuova del wizard)
     * @param list<string> $esempi    titoli di documenti della categoria
     */
    public function per(ClassifierInterface $classifier, string $modello, ?Categoria $esistente, string $nome, array $esempi): string
    {
        $fissa = null !== $esistente && $esistente->isSystem() ? ($this->sistema[(string) $esistente->getSlug()]['descrizione'] ?? null) : null;
        if (null !== $fissa) {
            return $fissa;
        }
        $chiave = 'categoria_descrizione_'.hash('xxh128', json_encode([$modello, $nome, $esempi], \JSON_THROW_ON_ERROR));

        // Con il Mac la domanda può restare in attesa (eccezione): allora non si salva niente.
        return $this->cache->get($chiave, function (ItemInterface $item) use ($classifier, $nome, $esempi): string {
            $item->expiresAfter(7 * 86400);

            return $classifier->descriviCategoria($nome, $esempi);
        });
    }

    /** Come per(), con i titoli presi dalla categoria stessa (classificazione degli import). */
    public function perCategoria(ClassifierInterface $classifier, string $modello, Categoria $categoria, Documento $escluso): string
    {
        if ($categoria->isSystem() && isset($this->sistema[(string) $categoria->getSlug()]['descrizione'])) {
            return $this->per($classifier, $modello, $categoria, $categoria->getNome(), []);
        }
        $titoli = $this->db->fetchFirstColumn(<<<'SQL'
            SELECT titolo FROM documento
            WHERE tenant_id = :tenant AND categoria_id = :categoria AND id <> :escluso
            ORDER BY created_at DESC
            LIMIT 50
            SQL, ['tenant' => $escluso->getTenant()->getId()->toRfc4122(), 'categoria' => $categoria->getId()->toRfc4122(), 'escluso' => $escluso->getId()->toRfc4122()]);
        $esempi = \array_slice(array_values(array_filter(array_map('strval', $titoli), PropostaCategoria::titoloParlante(...))), 0, self::ESEMPI);

        return $this->per($classifier, $modello, $categoria, $categoria->getNome(), $esempi);
    }
}
