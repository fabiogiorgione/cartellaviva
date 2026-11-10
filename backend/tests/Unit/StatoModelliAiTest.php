<?php

namespace App\Tests\Unit;

use App\Documenti\Indice\AiEstrattoreDati;
use App\Documenti\Indice\StatoModelliAi;
use App\Tests\Support\FakeEmbedder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StatoModelliAiTest extends TestCase
{
    public function testDownloadedModelsAreReadyAlsoWithTheLatestTag(): void
    {
        // FakeEmbedder usa "embed-di-test": in Ollama compare come "embed-di-test:latest".
        $tags = new MockResponse(json_encode(['models' => [['name' => 'embed-di-test:latest'], ['name' => 'qwen3:4b']]]));
        self::assertSame(['dati' => 'pronto', 'ricerca' => 'pronto'], $this->stato($tags, 'qwen3:4b')->stato());
        self::assertSame(['dati' => 'mancante', 'ricerca' => 'pronto'], $this->stato(new MockResponse(json_encode(['models' => [['name' => 'embed-di-test:latest']]])), 'qwen3:4b')->stato());
    }

    public function testDisabledAndUnreachable(): void
    {
        $embedder = new FakeEmbedder();
        $embedder->enabled = false;
        self::assertSame(['dati' => 'disattivato', 'ricerca' => 'disattivato'], $this->stato(new MockResponse('', ['http_code' => 500]), '', $embedder)->stato());
        self::assertSame(['dati' => 'non_raggiungibile', 'ricerca' => 'non_raggiungibile'], $this->stato(new MockResponse('', ['http_code' => 500]), 'qwen3:4b')->stato());
    }

    private function stato(MockResponse $risposta, string $modelloDati, ?FakeEmbedder $embedder = null): StatoModelliAi
    {
        $http = new MockHttpClient($risposta);

        return new StatoModelliAi($http, new AiEstrattoreDati($http, 'http://ollama.test:11434', $modelloDati, 6000), $embedder ?? new FakeEmbedder(), 'http://ollama.test:11434');
    }
}
