<?php

namespace App\Documenti;

/**
 * File di testo semplice (TXT) in UTF-8: quelli salvati da Windows sono spesso
 * in Windows-1252 o in UTF-16 (con BOM). Serve al testo cercabile e
 * all'anteprima; il file scaricato resta quello originale.
 */
final class TestoSemplice
{
    public static function inUtf8(string $contenuto): string
    {
        return match (true) {
            str_starts_with($contenuto, "\xEF\xBB\xBF") => substr($contenuto, 3),
            str_starts_with($contenuto, "\xFF\xFE") => mb_convert_encoding(substr($contenuto, 2), 'UTF-8', 'UTF-16LE'),
            str_starts_with($contenuto, "\xFE\xFF") => mb_convert_encoding(substr($contenuto, 2), 'UTF-8', 'UTF-16BE'),
            mb_check_encoding($contenuto, 'UTF-8') => $contenuto,
            default => mb_convert_encoding($contenuto, 'UTF-8', 'Windows-1252'),
        };
    }
}
