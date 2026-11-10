<?php

namespace App\Tests\Support;

use App\Import\Provider\GoogleDrive\GoogleDriveProvider;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Google Drive API v3 simulata in memoria (mock_response_factory dell'HttpClient
 * nei test). Registra i download per verificare che i duplicati non si scarichino.
 */
final class FakeGoogleDrive
{
    /** @var array<string, array{id: string, name: string, mimeType: string, parent: ?string, content: string, modifiedTime: string}> */
    private array $items = [];

    /** @var list<string> id dei file scaricati o esportati */
    public array $downloads = [];

    /** @var list<string> access token ricevuti */
    public array $tokens = [];

    public bool $revoked = false;

    /** Accesso revocato durante l'import: l'elenco riesce, i download rispondono 401. */
    public bool $revocaDownload = false;

    /** Codice HTTP di errore per l'elenco dei file (es. 500), null = risposta normale. */
    public ?int $erroreLista = null;

    public function folder(string $id, string $name, ?string $parent = null): self
    {
        $this->items[$id] = ['id' => $id, 'name' => $name, 'mimeType' => GoogleDriveProvider::FOLDER, 'parent' => $parent, 'content' => '', 'modifiedTime' => '2026-01-01T00:00:00.000Z'];

        return $this;
    }

    public function file(string $id, string $name, string $mimeType, string $parent, string $content, string $modifiedTime = '2026-02-01T10:00:00.000Z'): self
    {
        $this->items[$id] = ['id' => $id, 'name' => $name, 'mimeType' => $mimeType, 'parent' => $parent, 'content' => $content, 'modifiedTime' => $modifiedTime];

        return $this;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $parts = parse_url($url);
        if (!str_starts_with((string) ($parts['path'] ?? ''), '/drive/v3/files')) {
            return new MockResponse('', ['http_code' => 404]);
        }
        foreach ($options['headers'] ?? [] as $header) {
            if (str_starts_with(strtolower($header), 'authorization: bearer ')) {
                $this->tokens[] = substr($header, 22);
            }
        }
        if ($this->revoked) {
            return new MockResponse('{"error":{"code":401}}', ['http_code' => 401]);
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $path = substr((string) $parts['path'], \strlen('/drive/v3/files'));

        if ('' === $path && null !== $this->erroreLista) {
            return new MockResponse('{"error":{"code":'.$this->erroreLista.'}}', ['http_code' => $this->erroreLista]);
        }
        if ('' === $path) {
            preg_match("/^'([^']+)' in parents/", (string) ($query['q'] ?? ''), $m);
            $files = array_values(array_map(fn (array $i) => $this->metadata($i), array_filter($this->items, static fn (array $i) => $i['parent'] === ($m[1] ?? null))));

            return $this->json(['files' => $files]);
        }

        [$id, $action] = array_pad(explode('/', ltrim($path, '/')), 2, null);
        $item = $this->items[rawurldecode((string) $id)] ?? null;
        if (null === $item) {
            return new MockResponse('{"error":{"code":404}}', ['http_code' => 404]);
        }
        if ('export' === $action || 'media' === ($query['alt'] ?? null)) {
            if ($this->revocaDownload) {
                return new MockResponse('{"error":{"code":401}}', ['http_code' => 401]);
            }
            $this->downloads[] = $item['id'];

            return new MockResponse($item['content']);
        }

        return $this->json($this->metadata($item) + ['trashed' => false]);
    }

    /**
     * @param array{id: string, name: string, mimeType: string, content: string, modifiedTime: string} $item
     *
     * @return array<string, mixed>
     */
    private function metadata(array $item): array
    {
        $meta = ['id' => $item['id'], 'name' => $item['name'], 'mimeType' => $item['mimeType'], 'modifiedTime' => $item['modifiedTime']];
        // Come Drive: md5Checksum e size solo per i file binari, non per cartelle e file nativi Google.
        if (!str_starts_with($item['mimeType'], 'application/vnd.google-apps.')) {
            $meta['md5Checksum'] = md5($item['content']);
            $meta['size'] = (string) \strlen($item['content']);
        }

        return $meta;
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
