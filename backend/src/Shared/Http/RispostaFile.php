<?php

namespace App\Shared\Http;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Risposte con un file (documenti, allegati, anteprime, ZIP): sempre con il
 * tipo dichiarato, senza "sniffing" del browser e senza cache, con il nome
 * vero più una variante ASCII per i client che non leggono filename*.
 */
final class RispostaFile
{
    /** Da scaricare. */
    public static function allegato(string $contenuto, string $mime, string $nome): Response
    {
        return self::intestazioni(new Response($contenuto), $mime, $nome);
    }

    /** Da mostrare nella pagina (scheda Anteprima). */
    public static function inline(string $contenuto, string $mime, string $nome): Response
    {
        return self::intestazioni(new Response($contenuto), $mime, $nome, HeaderUtils::DISPOSITION_INLINE);
    }

    /** Le stesse intestazioni su una risposta già costruita (es. uno ZIP in streaming). */
    public static function intestazioni(Response $response, string $mime, string $nome, string $disposizione = HeaderUtils::DISPOSITION_ATTACHMENT): Response
    {
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition($disposizione, $nome, self::ascii($nome)));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private static function ascii(string $nome): string
    {
        $ascii = str_replace(['"', '\\', '/', '%'], '_', (string) preg_replace('/[^\x20-\x7E]/', '_', $nome));

        return '' === trim($ascii, ' _.') ? 'documento' : $ascii;
    }
}
