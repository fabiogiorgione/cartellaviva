<?php

namespace App\Documenti;

use App\Shared\Errore\DatiNonValidi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tipi di file ammessi e dimensione massima. Il tipo MIME si ricava dal
 * CONTENUTO del file (finfo, più un controllo della struttura per i formati
 * Office che sono archivi ZIP), mai dall'estensione o dall'header del client.
 *
 * I documenti Office e di Pages, Numbers e Keynote si conservano nel formato
 * originale; per la scheda "Anteprima" il server ne ricava un PDF (vedi
 * App\Anteprima). I file con macro VBA non sono ammessi.
 *
 * Gli archivi ZIP si conservano e si scaricano soltanto: il server non li apre
 * mai (niente testo, niente anteprima), quindi il loro contenuto non viene
 * controllato.
 */
final class UploadPolicy
{
    /** @var array<string, string> MIME => descrizione */
    public const ALLOWED = [
        'application/pdf' => 'PDF',
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/heic' => 'HEIC',
        'image/heif' => 'HEIF',
        'image/gif' => 'GIF',
        'image/webp' => 'WEBP',
        self::DOCX => 'DOCX',
        self::XLSX => 'XLSX',
        self::PPTX => 'PPTX',
        self::ODT => 'ODT',
        self::ODS => 'ODS',
        self::ODP => 'ODP',
        'application/msword' => 'DOC',
        'application/vnd.ms-excel' => 'XLS',
        'application/vnd.ms-powerpoint' => 'PPT',
        'text/rtf' => 'RTF',
        self::PAGES => 'Pages',
        self::NUMBERS => 'Numbers',
        self::KEYNOTE => 'Keynote',
        'text/plain' => 'TXT',
        self::ZIP => 'ZIP',
    ];

    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    public const ODT = 'application/vnd.oasis.opendocument.text';
    public const ODS = 'application/vnd.oasis.opendocument.spreadsheet';
    public const ODP = 'application/vnd.oasis.opendocument.presentation';
    public const PAGES = 'application/vnd.apple.pages';
    public const NUMBERS = 'application/vnd.apple.numbers';
    public const KEYNOTE = 'application/vnd.apple.keynote';
    public const ZIP = 'application/zip';

    private const UFFICIO = [self::DOCX, self::XLSX, self::PPTX, self::ODT, self::ODS, self::ODP, 'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'text/rtf', self::PAGES, self::NUMBERS, self::KEYNOTE];

    /** Tipo di file per chi guarda l'elenco (come Documento.tipoFile nell'app). */
    public static function tipo(string $mime): string
    {
        return match (true) {
            'application/pdf' === $mime => 'PDF',
            str_starts_with($mime, 'image/') => 'Foto',
            str_contains($mime, 'spreadsheet'), str_contains($mime, 'excel'), self::NUMBERS === $mime => 'Foglio di calcolo',
            str_contains($mime, 'presentation'), str_contains($mime, 'powerpoint'), self::KEYNOTE === $mime => 'Presentazione',
            str_contains($mime, 'word'), str_contains($mime, 'opendocument.text'), str_contains($mime, 'rtf'), self::PAGES === $mime => 'Documento di testo',
            'text/plain' === $mime => 'Testo',
            self::ZIP === $mime => 'Archivio ZIP',
            default => 'File',
        };
    }

    /**
     * I MIME ammessi di quel tipo.
     *
     * @return list<string>
     */
    public static function mimeDelTipo(string $tipo): array
    {
        return array_values(array_filter(array_keys(self::ALLOWED), static fn (string $mime) => self::tipo($mime) === $tipo));
    }

    public function __construct(
        #[Autowire('%app.document_max_bytes%')]
        private readonly int $maxBytes,
    ) {
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Restituisce il MIME rilevato oppure lancia un 422 con il motivo. Il nome
     * del file serve solo a distinguere Pages, Numbers e Keynote, che hanno la
     * stessa struttura: che sia un documento iWork lo dice comunque il contenuto.
     */
    public function assertAcceptable(string $contents, ?string $nomeFile = null): string
    {
        $size = \strlen($contents);
        if (0 === $size) {
            throw new DatiNonValidi('Il file è vuoto.');
        }
        if ($size > $this->maxBytes) {
            throw new DatiNonValidi(sprintf('Il file supera la dimensione massima di %d MB.', intdiv($this->maxBytes, 1024 * 1024)));
        }
        $mime = self::rileva($contents, $nomeFile);
        if (str_starts_with($contents, "PK\x03\x04") && str_contains($contents, 'vbaProject.bin')) {
            throw new DatiNonValidi('Il documento contiene macro: per sicurezza non è ammesso. Salvalo senza macro (es. .docx invece di .docm) e riprova.');
        }
        if (!isset(self::ALLOWED[$mime])) {
            throw new DatiNonValidi(sprintf('Tipo di file non ammesso (%s). Formati accettati: %s.', $mime, implode(', ', array_unique(self::ALLOWED))));
        }

        return $mime;
    }

    /**
     * finfo, più la struttura per gli archivi ZIP che libmagic non sempre
     * riconosce (OOXML prodotti da alcuni programmi, ODF, iWork): un documento
     * di questi tipi per finfo è spesso un semplice "application/zip".
     */
    public static function rileva(string $contents, ?string $nomeFile = null): string
    {
        $mime = (string) (new \finfo(\FILEINFO_MIME_TYPE))->buffer($contents);
        if ('application/rtf' === $mime) {
            return 'text/rtf';
        }
        if (!str_starts_with($contents, "PK\x03\x04") || (isset(self::ALLOWED[$mime]) && self::ZIP !== $mime)) {
            return $mime;
        }
        // ODF: il primo file dell'archivio è "mimetype", non compresso, con il tipo.
        if ('mimetype' === substr($contents, 30, 8)) {
            $tipo = substr($contents, 38, 60);
            foreach ([self::ODT, self::ODS, self::ODP] as $odf) {
                if (str_starts_with($tipo, $odf)) {
                    return $odf;
                }
            }
        }
        // OOXML: i nomi dei file interni sono in chiaro nelle intestazioni ZIP.
        if (str_contains($contents, '[Content_Types].xml')) {
            return match (true) {
                str_contains($contents, 'word/document.xml') => self::DOCX,
                str_contains($contents, 'xl/workbook.xml') => self::XLSX,
                str_contains($contents, 'ppt/presentation.xml') => self::PPTX,
                default => $mime,
            };
        }

        return self::iwork($contents, $nomeFile) ?? $mime;
    }

    /**
     * Pages, Numbers e Keynote (dal 2013): archivi ZIP con Index/Document.iwa.
     * I tre formati hanno la stessa struttura: decide l'estensione del file e,
     * se manca, le parti tipiche (diapositive per Keynote, tabelle per Numbers).
     */
    private static function iwork(string $zip, ?string $nomeFile): ?string
    {
        $voci = self::vociZip($zip);
        if (!\in_array('Index/Document.iwa', $voci, true)) {
            return null;
        }
        $perEstensione = ['pages' => self::PAGES, 'numbers' => self::NUMBERS, 'key' => self::KEYNOTE];
        $estensione = strtolower(pathinfo((string) $nomeFile, \PATHINFO_EXTENSION));
        if (isset($perEstensione[$estensione])) {
            return $perEstensione[$estensione];
        }
        foreach ($voci as $voce) {
            if (str_starts_with($voce, 'Index/Slide')) {
                return self::KEYNOTE;
            }
        }
        foreach ($voci as $voce) {
            if (str_starts_with($voce, 'Index/Tables/')) {
                return self::NUMBERS;
            }
        }

        return self::PAGES;
    }

    /**
     * Nomi dei file nell'archivio, dalla directory centrale in fondo allo ZIP:
     * non si estrae nulla e non contano i nomi che compaiono dentro i file
     * (es. uno ZIP che contiene un documento Pages resta uno ZIP).
     *
     * @return list<string>
     */
    private static function vociZip(string $zip): array
    {
        $fine = strrpos($zip, "PK\x05\x06");
        if (false === $fine || \strlen($zip) < $fine + 22) {
            return [];
        }
        /** @var array{voci: int, offset: int} $eocd */
        $eocd = unpack('vdisco/vdiscoCd/vvociDisco/vvoci/Vdimensione/Voffset', substr($zip, $fine + 4, 16));
        $voci = [];
        $pos = $eocd['offset'];
        for ($i = 0; $i < min($eocd['voci'], 10000); ++$i) {
            if ("PK\x01\x02" !== substr($zip, $pos, 4) || \strlen($zip) < $pos + 46) {
                break;
            }
            /** @var array{nome: int, extra: int, commento: int} $h */
            $h = unpack('vnome/vextra/vcommento', substr($zip, $pos + 28, 6));
            $voci[] = substr($zip, $pos + 46, $h['nome']);
            $pos += 46 + $h['nome'] + $h['extra'] + $h['commento'];
        }

        return $voci;
    }

    /** Testo da estrarre: per tutti i tipi ammessi tranne gli archivi ZIP, che non si aprono. */
    public static function isOcrApplicable(string $mime): bool
    {
        return isset(self::ALLOWED[$mime]) && self::ZIP !== $mime;
    }

    public const MOTIVO_SENZA_ANTEPRIMA = 'Gli archivi ZIP non hanno anteprima: scaricalo per aprirlo.';

    /** Si può mostrare nella scheda "Anteprima" (l'originale o una copia ricavata)? */
    public static function haAnteprima(string $mime): bool
    {
        return self::ZIP !== $mime;
    }

    public static function isUfficio(string $mime): bool
    {
        return \in_array($mime, self::UFFICIO, true);
    }

    /**
     * Tipo della copia per la scheda Anteprima, oppure null se l'originale si
     * mostra così com'è (PDF, immagini, testo) o non si mostra affatto (ZIP).
     */
    public static function anteprimaPer(string $mime): ?string
    {
        return match (true) {
            self::isUfficio($mime) => 'application/pdf',
            \in_array($mime, ['image/heic', 'image/heif'], true) => 'image/jpeg',
            default => null,
        };
    }

    /**
     * Il nome con l'estensione del contenuto riconosciuto: "bolletta.html" con
     * dentro un PNG diventa "bolletta.png" (chi scarica non apre un HTML).
     * Un'estensione già giusta resta com'è, maiuscole comprese.
     */
    public static function conEstensioneDelContenuto(string $nome, string $mime): string
    {
        $giusta = self::extensionFor($mime);
        $attuale = pathinfo($nome, \PATHINFO_EXTENSION);
        if (\in_array(strtolower($attuale), 'jpg' === $giusta ? ['jpg', 'jpeg'] : [$giusta], true)) {
            return $nome;
        }
        $base = '' === $attuale ? $nome : mb_substr($nome, 0, mb_strlen($nome) - mb_strlen($attuale) - 1);

        return ('' === $base ? 'documento' : $base).'.'.$giusta;
    }

    public static function extensionFor(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            self::DOCX => 'docx',
            self::XLSX => 'xlsx',
            self::PPTX => 'pptx',
            self::ODT => 'odt',
            self::ODS => 'ods',
            self::ODP => 'odp',
            'application/msword' => 'doc',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.ms-powerpoint' => 'ppt',
            'text/rtf' => 'rtf',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            self::PAGES => 'pages',
            self::NUMBERS => 'numbers',
            self::KEYNOTE => 'key',
            'text/plain' => 'txt',
            self::ZIP => 'zip',
            default => 'bin',
        };
    }
}
