<?php

namespace App\Shared\Errore;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Risposte d'errore dei controller (non API Platform) con il debug spento.
 *
 * Il ProblemNormalizer di Symfony, senza debug, mette in "detail" il solo testo dello
 * stato ("Conflict", "Forbidden"). Qui si rimette il messaggio, ma solo per gli errori
 * 4xx creati dal codice dell'app (src/): sono scritti per chi usa l'app ("Esiste già un
 * account con questa email."). Restano generici gli errori 4xx creati da Symfony, che
 * possono contenere dettagli interni (classi, URL), e tutti i 5xx.
 */
#[AsDecorator('serializer.normalizer.problem')]
final class MessaggioErroreNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    private readonly string $cartellaCodice;

    public function __construct(
        #[AutowireDecorated]
        private readonly NormalizerInterface $inner,
        #[Autowire('%kernel.project_dir%/src')]
        string $cartellaCodice,
    ) {
        $this->cartellaCodice = rtrim($cartellaCodice, '/').'/';
    }

    /** @return array<string, mixed> */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        /** @var array<string, mixed> $errore */
        $errore = $this->inner->normalize($data, $format, $context);
        $eccezione = $context['exception'] ?? null;

        if ($data instanceof FlattenException
            && $eccezione instanceof HttpExceptionInterface
            && $data->getStatusCode() >= 400 && $data->getStatusCode() < 500
            && !isset($errore['violations'])
            && ($errore['detail'] ?? null) === $data->getStatusText()
            && '' !== $eccezione->getMessage()
            && str_starts_with($eccezione->getFile(), $this->cartellaCodice)
        ) {
            $errore['detail'] = $eccezione->getMessage();
        }

        return $errore;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }

    /** Il normalizzatore interno usa il serializer per gli errori di validazione (violations). */
    public function setSerializer(SerializerInterface $serializer): void
    {
        if ($this->inner instanceof SerializerAwareInterface) {
            $this->inner->setSerializer($serializer);
        }
    }
}
