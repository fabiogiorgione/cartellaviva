<?php

namespace App\Tests\Unit;

use App\Shared\Errore\Conflitto;
use App\Shared\Errore\ErroreDominio;
use App\Shared\Errore\ErroreDominioListener;
use App\Shared\Errore\MessaggioErroreNormalizer;
use App\Shared\Errore\NonConsentito;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Serializer\Normalizer\ConstraintViolationListNormalizer;
use Symfony\Component\Serializer\Normalizer\ProblemNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Debug spento (produzione): il messaggio resta solo negli errori 4xx scritti dall'app.
 */
final class MessaggioErroreNormalizerTest extends TestCase
{
    private const SRC = __DIR__.'/../../src';

    public function testErroreDiDominioTieneIlMessaggio(): void
    {
        $errore = $this->normalizza($this->daListener(new Conflitto('Esiste già un account con questa email.')));

        self::assertSame(409, $errore['status']);
        self::assertSame('Esiste già un account con questa email.', $errore['detail']);
        self::assertArrayNotHasKey('trace', $errore);
        self::assertArrayNotHasKey('class', $errore);
    }

    public function testRegistrazioneChiusaTieneIlMessaggio(): void
    {
        $errore = $this->normalizza($this->daListener(new NonConsentito('La registrazione è chiusa.')));

        self::assertSame(403, $errore['status']);
        self::assertSame('La registrazione è chiusa.', $errore['detail']);
    }

    public function testErroreCreatoDaSymfonyRestaGenerico(): void
    {
        // Creata dentro vendor/ (HttpException::fromStatusCode): può contenere dettagli interni.
        $errore = $this->normalizza(HttpException::fromStatusCode(404, 'No route found for "GET http://localhost/api/interno"'));

        self::assertSame('Not Found', $errore['detail']);
    }

    public function testGliErrori5xxRestanoGenerici(): void
    {
        // Creata in questo file: con la cartella dei test come "codice dell'app" conta solo lo stato.
        $errore = $this->normalizza(new ServiceUnavailableHttpException(null, 'Dettaglio interno del servizio.'), __DIR__);

        self::assertSame('Service Unavailable', $errore['detail']);
    }

    public function testLaValidazioneTieneICampi(): void
    {
        $violazioni = new ConstraintViolationList([new ConstraintViolation('Email non valida.', null, [], null, 'email', 'x')]);
        $eccezione = new UnprocessableEntityHttpException('Dati non validi.', new ValidationFailedException(null, $violazioni));

        $errore = $this->normalizza($eccezione);

        self::assertSame('email', $errore['violations'][0]['propertyPath']);
        self::assertSame('Email non valida.', $errore['violations'][0]['title']);
        self::assertSame('email: Email non valida.', $errore['detail']);
    }

    private function daListener(ErroreDominio $e): \Throwable
    {
        $evento = new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $e);
        (new ErroreDominioListener())($evento);

        return $evento->getThrowable();
    }

    /** @return array<string, mixed> */
    private function normalizza(\Throwable $e, string $cartellaCodice = self::SRC): array
    {
        $normalizer = new MessaggioErroreNormalizer(new ProblemNormalizer(debug: false), (string) realpath($cartellaCodice));
        // Il Serializer passa sé stesso ai normalizzatori: serve per le violations.
        new Serializer([new ConstraintViolationListNormalizer(), $normalizer]);

        return $normalizer->normalize(FlattenException::createFromThrowable($e), 'json', ['exception' => $e]);
    }
}
