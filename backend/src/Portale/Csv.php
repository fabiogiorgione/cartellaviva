<?php

namespace App\Portale;

use Symfony\Component\HttpFoundation\Response;

/** Esportazioni CSV del portale: ";" e UTF-8 con BOM, come le apre Excel in italiano. */
final class Csv
{
    /** @param list<list<string>> $righe la prima è l'intestazione */
    public static function risposta(string $nomeFile, array $righe): Response
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($righe as $riga) {
            // Le celle che iniziano con = + - @ si neutralizzano (CSV injection in Excel).
            fputcsv($out, array_map(static fn (string $v) => preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $riga), ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s-%s.csv"', $nomeFile, date('Y-m-d')),
        ]);
    }
}
