<?php

namespace App\Shared\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait JsonBodyTrait
{
    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Corpo JSON non valido.');
        }

        return $data;
    }

    private function str(array $data, string $key, int $max = 255): ?string
    {
        $value = isset($data[$key]) && \is_scalar($data[$key]) ? trim((string) $data[$key]) : '';

        return '' === $value ? null : mb_substr($value, 0, $max);
    }

    private function date(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $this->str($data, $key, 10);
        if (null === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException($key.': formato atteso AAAA-MM-GG.');
        }

        return $date;
    }
}
