<?php

namespace App\Import;

/**
 * File trovato nella sorgente esterna (solo metadati, prima del download).
 */
final class RemoteFile
{
    public function __construct(
        public readonly string $id,
        public readonly string $nome,
        public readonly string $mimeType,
        /** Versione del file nella sorgente (data di modifica): con l'id identifica il contenuto. */
        public readonly string $versione,
        /** MD5 del contenuto, se la sorgente lo fornisce (non per i file nativi di Google). */
        public readonly ?string $md5,
        public readonly ?int $dimensione,
        /** Nome della sottocartella di primo livello; null = file direttamente nella cartella radice. */
        public readonly ?string $cartella,
        /** Il file non si scarica così com'è ma si esporta (es. Google Documenti -> PDF). */
        public readonly bool $daEsportare = false,
        /** Motivo per cui il file non si importa (tipo non supportato), null se importabile. */
        public readonly ?string $nonSupportato = null,
    ) {
    }

    /** Nome del file salvato: per gli export si aggiunge l'estensione del formato esportato. */
    public function nomeFile(): string
    {
        return $this->daEsportare && !str_ends_with(strtolower($this->nome), '.pdf') ? $this->nome.'.pdf' : $this->nome;
    }
}
