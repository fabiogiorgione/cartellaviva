<?php

namespace App\Documenti\Indice;

use App\Documenti\Entity\Documento;
use App\Documenti\Repository\DocumentoRepository;
use App\Tenancy\Entity\Tenant;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ricerca per significato ("bolletta della luce" trova la fattura ENEL anche
 * senza quelle parole), unita alla ricerca per parole di sempre:
 * 1. i documenti più vicini per significato (solo non cifrati: gli unici indicizzati);
 * 2. quelli con un tag uguale a una parola cercata ("auto" → polizza e libretto);
 * 3. poi quelli che contengono le parole cercate nel titolo o nel testo
 *    (così i documenti cifrati restano trovabili per titolo).
 * Gli stessi filtri e le stesse restrizioni della lista (categoria, membro,
 * accesso d'emergenza) valgono per tutte le parti.
 *
 * Pertinenza: un documento resta se la sua distanza non supera né il tetto
 * assoluto né quella del migliore più un margine (con bge-m3 le distanze dei
 * documenti pertinenti e degli altri sono vicine: conta il distacco). Le
 * ricerche di una sola parola rendono meno: lì aiutano i tag.
 */
final class RicercaPerSignificato
{
    private const CANDIDATI = 100;

    public function __construct(
        private readonly EmbedderInterface $embedder,
        private readonly IndiceDocumenti $indice,
        private readonly DocumentoRepository $documenti,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.ricerca_distanza_max%')]
        private readonly float $distanzaMax,
        #[Autowire('%app.ricerca_margine%')]
        private readonly float $margine,
    ) {
    }

    /**
     * @param array<string, mixed> $filtri come DocumentoRepository::search (senza q)
     *
     * @return list<Documento>|null null = ricerca per significato non disponibile (si usa quella normale)
     */
    public function cerca(Tenant $tenant, string $q, array $filtri, int $limite): ?array
    {
        $q = trim($q);
        if ('' === $q || !$this->embedder->isEnabled()) {
            return null;
        }
        try {
            // Nella richiesta HTTP: attesa breve, poi si ripiega sulla ricerca per parole.
            $vettore = $this->embedder->embed([mb_substr($q, 0, 500)], 30)[0];
        } catch (\Throwable $e) {
            $this->logger->warning('Ricerca per significato non disponibile, uso la ricerca per parole: {reason}', ['reason' => $e->getMessage()]);

            return null;
        }

        $vicini = $this->indice->cerca($tenant, $vettore, $this->embedder->modello(), self::CANDIDATI);
        $soglia = null === ($vicini[0] ?? null) ? 0.0 : min($this->distanzaMax, $vicini[0]['distanza'] + $this->margine);
        $ids = array_column(array_filter($vicini, static fn (array $v) => $v['distanza'] <= $soglia), 'id');
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($q), $parole);
        foreach ($this->indice->conTag($tenant, \array_slice(array_values(array_unique($parole[0])), 0, 10), self::CANDIDATI) as $id) {
            if (!\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $perSignificato = [];
        if ([] !== $ids) {
            // Gli stessi filtri della lista: un documento non consentito non compare.
            $trovati = $this->documenti->search($tenant, $filtri + ['ids' => $ids], 1, \count($ids))['items'];
            $posizione = array_flip($ids);
            usort($trovati, static fn (Documento $a, Documento $b) => $posizione[(string) $a->getId()] <=> $posizione[(string) $b->getId()]);
            $perSignificato = $trovati;
        }
        $perParole = $this->documenti->search($tenant, $filtri + ['q' => $q], 1, $limite)['items'];

        $risultato = [];
        foreach ([...$perSignificato, ...$perParole] as $d) {
            $risultato[(string) $d->getId()] ??= $d;
        }

        return \array_slice(array_values($risultato), 0, $limite);
    }
}
