<?php

namespace App\Import\Provider\GoogleDrive;

use App\Documenti\UploadPolicy;
use App\Import\Enum\ImportProvider;
use App\Import\Exception\ImportException;
use App\Import\OAuthTokens;
use App\Import\Provider\ImportProviderInterface;
use App\Import\RemoteFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Google Drive (API v3) in sola lettura, con l'HttpClient di Symfony
 * (client "google_drive.client" in config/packages/framework.yaml).
 *
 * I file nativi di Google (Documenti, Fogli, Presentazioni, Disegni) non hanno
 * un contenuto scaricabile né un md5Checksum: si esportano in PDF e si
 * riconoscono per id + data di modifica.
 */
final class GoogleDriveProvider implements ImportProviderInterface
{
    public const FOLDER = 'application/vnd.google-apps.folder';

    /** Tipi nativi di Google esportabili in PDF. */
    private const EXPORTABLE = [
        'application/vnd.google-apps.document',
        'application/vnd.google-apps.spreadsheet',
        'application/vnd.google-apps.presentation',
        'application/vnd.google-apps.drawing',
    ];

    private const FILE_FIELDS = 'id,name,mimeType,md5Checksum,modifiedTime,size';

    public function __construct(
        private readonly GoogleOAuthInterface $oauth,
        private readonly HttpClientInterface $googleDriveClient,
    ) {
    }

    public function provider(): ImportProvider
    {
        return ImportProvider::GOOGLE_DRIVE;
    }

    public function isConfigured(): bool
    {
        return $this->oauth->isConfigured();
    }

    public function authorizationUrl(string $state): string
    {
        return $this->oauth->authorizationUrl($state);
    }

    public function exchangeCode(string $code): OAuthTokens
    {
        return $this->oauth->exchangeCode($code);
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        return $this->oauth->refresh($refreshToken);
    }

    public function revoke(string $refreshToken): void
    {
        $this->oauth->revoke($refreshToken);
    }

    /**
     * Accetta l'id oppure i link di Drive: …/drive/folders/<id>, …/drive/u/0/folders/<id>,
     * …/open?id=<id>, …/folderview?id=<id>. Solo host drive.google.com, in https.
     */
    public function folderIdFromInput(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^[A-Za-z0-9_-]{1,200}$/', $input)) {
            return $input;
        }
        $parts = parse_url($input);
        if (!\is_array($parts) || 'https' !== ($parts['scheme'] ?? null) || 'drive.google.com' !== strtolower($parts['host'] ?? '')) {
            return null;
        }
        if (preg_match('#/folders/([A-Za-z0-9_-]{10,200})(?:/|$)#', $parts['path'] ?? '', $m)) {
            return $m[1];
        }
        parse_str($parts['query'] ?? '', $query);
        $id = $query['id'] ?? null;

        return \is_string($id) && preg_match('/^[A-Za-z0-9_-]{10,200}$/', $id) ? $id : null;
    }

    public function listFiles(string $accessToken, string $cartellaRadiceId): array
    {
        $radice = $this->json($accessToken, 'files/'.rawurlencode($cartellaRadiceId), ['fields' => 'id,name,mimeType,trashed'], notFound: 'La cartella radice non esiste o l\'account collegato non può leggerla.');
        if (self::FOLDER !== ($radice['mimeType'] ?? null) || true === ($radice['trashed'] ?? false)) {
            throw new ImportException('La cartella radice indicata non è una cartella di Google Drive (o è nel cestino).');
        }

        $files = [];
        $sottocartelle = [];
        foreach ($this->children($accessToken, $cartellaRadiceId) as $item) {
            if (self::FOLDER === $item['mimeType']) {
                $sottocartelle[] = $item;
            } else {
                $files[] = $this->toRemoteFile($item, null);
            }
        }
        // Solo il primo livello: le cartelle dentro le sottocartelle si ignorano.
        foreach ($sottocartelle as $cartella) {
            foreach ($this->children($accessToken, (string) $cartella['id']) as $item) {
                if (self::FOLDER !== $item['mimeType']) {
                    $files[] = $this->toRemoteFile($item, (string) $cartella['name']);
                }
            }
        }

        return $files;
    }

    public function download(string $accessToken, RemoteFile $file, int $maxBytes): string
    {
        $path = 'files/'.rawurlencode($file->id);
        $query = $file->daEsportare ? ['mimeType' => 'application/pdf'] : ['alt' => 'media', 'supportsAllDrives' => 'true'];
        if ($file->daEsportare) {
            $path .= '/export';
        }
        $response = $this->googleDriveClient->request('GET', $path, ['auth_bearer' => $accessToken, 'query' => $query]);
        $this->assertOk($response, 'Il file non è più disponibile su Google Drive.');

        // Download in streaming con limite: un file più grande del massimo non finisce in memoria.
        $contents = '';
        foreach ($this->googleDriveClient->stream($response) as $chunk) {
            $contents .= $chunk->getContent();
            if (\strlen($contents) > $maxBytes) {
                $response->cancel();
                throw new \RuntimeException(sprintf('File oltre la dimensione massima di %d MB.', intdiv($maxBytes, 1024 * 1024)));
            }
        }

        return $contents;
    }

    /** @return list<array<string, mixed>> file non nel cestino direttamente dentro la cartella */
    private function children(string $accessToken, string $folderId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $folderId)) {
            throw new ImportException('Id di cartella Google Drive non valido.');
        }
        $items = [];
        $pageToken = null;
        do {
            $query = [
                'q' => sprintf("'%s' in parents and trashed = false", $folderId),
                'fields' => 'nextPageToken,files('.self::FILE_FIELDS.')',
                'pageSize' => 1000,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if (null !== $pageToken) {
                $query['pageToken'] = $pageToken;
            }
            $page = $this->json($accessToken, 'files', $query);
            foreach ($page['files'] ?? [] as $item) {
                $items[] = $item;
            }
            $pageToken = $page['nextPageToken'] ?? null;
        } while (null !== $pageToken);

        return $items;
    }

    /** @param array<string, mixed> $item */
    private function toRemoteFile(array $item, ?string $cartella): RemoteFile
    {
        $mime = (string) $item['mimeType'];
        $esporta = \in_array($mime, self::EXPORTABLE, true);
        $nonSupportato = null;
        if (!$esporta && !isset(UploadPolicy::ALLOWED[$mime])) {
            $nonSupportato = $mime;
        }

        return new RemoteFile(
            id: (string) $item['id'],
            nome: (string) $item['name'],
            mimeType: $esporta ? 'application/pdf' : $mime,
            versione: (string) ($item['modifiedTime'] ?? ''),
            md5: $esporta ? null : ($item['md5Checksum'] ?? null),
            dimensione: isset($item['size']) ? (int) $item['size'] : null,
            cartella: $cartella,
            daEsportare: $esporta,
            nonSupportato: $nonSupportato,
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function json(string $accessToken, string $path, array $query, string $notFound = 'Risorsa di Google Drive non trovata.'): array
    {
        $query += ['supportsAllDrives' => 'true'];
        $response = $this->googleDriveClient->request('GET', $path, ['auth_bearer' => $accessToken, 'query' => $query]);
        $this->assertOk($response, $notFound);

        return $response->toArray();
    }

    private function assertOk(ResponseInterface $response, string $notFound): void
    {
        try {
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            throw new ImportException('Google Drive non raggiungibile: riprova più tardi.', 0, $e);
        }
        if ($status < 300) {
            return;
        }
        $message = match (true) {
            401 === $status => 'L\'accesso a Google Drive è stato revocato o è scaduto: ricollega l\'account.',
            403 === $status => 'Google Drive ha negato l\'accesso (permessi insufficienti o limite di richieste superato).',
            404 === $status => $notFound,
            default => sprintf('Google Drive ha risposto con un errore (HTTP %d): riprova più tardi.', $status),
        };
        throw new ImportException($message);
    }
}
