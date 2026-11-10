<?php

namespace App\Documenti\Classificazione;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turni per le proposte di categoria con l'AI. Ogni proposta tiene occupato un
 * thread del server web per decine di secondi (Ollama su CPU), e Ollama le
 * elabora comunque una alla volta: senza turni bastano poche richieste in
 * parallelo per bloccare l'API a tutti.
 *
 * - una proposta alla volta per utente;
 * - al massimo $posti proposte contemporanee su tutta la piattaforma.
 *
 * Advisory lock di PostgreSQL a livello di sessione: si rilasciano alla fine
 * del lavoro e, se la richiesta muore, alla chiusura della connessione.
 */
class TurnoProposte
{
    private const UTENTE = 'proposta_categoria_utente';
    private const POSTO = 'proposta_categoria_posto';

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%app.proposte_contemporanee%')]
        private readonly int $posti,
    ) {
    }

    /**
     * Esegue $lavoro se c'è un turno libero.
     *
     * @template T
     *
     * @param callable(): T $lavoro
     *
     * @return T
     *
     * @throws TurnoOccupato se l'utente ha già una proposta in corso o i posti sono tutti occupati
     */
    public function esegui(string $userId, callable $lavoro): mixed
    {
        if (!$this->prendi(self::UTENTE, $userId)) {
            throw new TurnoOccupato('Hai già una proposta in corso: attendi che finisca.');
        }
        try {
            $posto = null;
            for ($i = 0; $i < max(1, $this->posti); ++$i) {
                if ($this->prendi(self::POSTO, (string) $i)) {
                    $posto = (string) $i;
                    break;
                }
            }
            if (null === $posto) {
                throw new TurnoOccupato('L\'AI sta già lavorando per altri: riprova tra qualche secondo.');
            }
            try {
                return $lavoro();
            } finally {
                $this->lascia(self::POSTO, $posto);
            }
        } finally {
            $this->lascia(self::UTENTE, $userId);
        }
    }

    private function prendi(string $spazio, string $chiave): bool
    {
        return (bool) $this->db->fetchOne('SELECT pg_try_advisory_lock(hashtext(:s), hashtext(:k))', ['s' => $spazio, 'k' => $chiave]);
    }

    private function lascia(string $spazio, string $chiave): void
    {
        $this->db->fetchOne('SELECT pg_advisory_unlock(hashtext(:s), hashtext(:k))', ['s' => $spazio, 'k' => $chiave]);
    }
}
