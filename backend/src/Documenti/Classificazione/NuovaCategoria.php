<?php

namespace App\Documenti\Classificazione;

/** Categoria nuova proposta dal modello: da approvare (e creare) a mano. */
final class NuovaCategoria
{
    public const MAX_NOME = 40;
    private const MAX_PAROLE = 4;

    /** Icone che il modello può scegliere: le stesse proposte nell'app per le categorie personalizzate. */
    public const ICONE = [
        'folder', 'house', 'car', 'heart-pulse', 'pill', 'id-card', 'receipt', 'graduation-cap', 'briefcase', 'piggy-bank', 'landmark',
        'shield-check', 'plane', 'trophy', 'paw-print', 'baby', 'brain', 'wrench', 'smartphone', 'zap', 'scale', 'file-text',
    ];

    /** Nomi che non dicono nulla: meglio nessuna proposta. */
    private const VUOTI = ['nessuna', 'nessuno', 'altro', 'altri', 'varie', 'vari', 'documenti', 'documento', 'generale', 'generico', 'da categorizzare', 'sconosciuto', 'non so'];

    public function __construct(
        public readonly string $nome,
        public readonly bool $sensibile,
        public readonly string $icona,
        public readonly string $modello,
    ) {
    }

    /**
     * Il nome viene dal modello, che legge un testo non fidato: si accetta solo un
     * nome corto di lettere, cifre e poca punteggiatura, con l'iniziale maiuscola.
     */
    public static function normalizzaNome(mixed $nome): ?string
    {
        // Una riga sola e al massimo 4 parole: un nome, non una frase.
        if (!\is_string($nome) || preg_match('/[\r\n]/', trim($nome))) {
            return null;
        }
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome), " \t\n\r\"'.,;:«»");
        if ('' === $nome || mb_strlen($nome) > self::MAX_NOME || !preg_match('~^[\p{L}\p{N}][\p{L}\p{N} \'’&/,.-]*$~u', $nome)) {
            return null;
        }
        if (\in_array(mb_strtolower($nome), self::VUOTI, true) || substr_count($nome, ' ') >= self::MAX_PAROLE) {
            return null;
        }

        return mb_strtoupper(mb_substr($nome, 0, 1)).mb_substr($nome, 1);
    }
}
