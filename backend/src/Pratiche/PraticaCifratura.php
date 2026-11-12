<?php

namespace App\Pratiche;

use App\Pratiche\Entity\Pratica;
use App\Pratiche\Entity\PraticaVoce;
use App\Shared\Crypto\DocumentCipher;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\Uid\Uuid;

/**
 * Valori delle voci cifrati come i documenti sensibili: chiave casuale della
 * pratica (DEK) cifrata con la chiave del tenant; ogni voce è legata al suo id
 * (dati associati), quindi un blob copiato su un'altra voce non si decifra.
 * I valori in chiaro esistono solo in memoria.
 */
class PraticaCifratura
{
    private const SCOPO = 'pratica-voce';

    /** @var array<string, string> DEK già aperte in questa richiesta */
    private array $chiavi = [];

    public function __construct(private readonly DocumentCipher $cipher)
    {
    }

    /** @return array{wrapped: string, version: int} */
    public function nuovaChiave(Tenant $tenant, Uuid $praticaId): array
    {
        $dek = $this->cipher->generateDek();
        try {
            return $this->cipher->wrapDek($dek, $tenant->getId(), $praticaId);
        } finally {
            sodium_memzero($dek);
        }
    }

    /** @param array<string, mixed> $valori */
    public function cifra(PraticaVoce $voce, array $valori): void
    {
        $json = json_encode($valori, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $voce->setValoriCifrati(base64_encode($this->cipher->encryptSecret($json, $this->dek($voce->getPratica()), $voce->getId(), self::SCOPO)));
    }

    /** @return array<string, mixed> */
    public function decifra(PraticaVoce $voce): array
    {
        if ('' === $voce->getValoriCifrati()) {
            return [];
        }
        $json = $this->cipher->decryptSecret((string) base64_decode($voce->getValoriCifrati(), true), $this->dek($voce->getPratica()), $voce->getId(), self::SCOPO);
        $valori = json_decode($json, true);

        return \is_array($valori) ? $valori : [];
    }

    private function dek(Pratica $pratica): string
    {
        $chiave = $pratica->getId()->toRfc4122();

        return $this->chiavi[$chiave] ??= $this->cipher->unwrapDek($pratica->getEncryptedDek(), $pratica->getKeyVersion(), $pratica->getTenant()->getId(), $pratica->getId());
    }

    public function __destruct()
    {
        foreach ($this->chiavi as &$dek) {
            sodium_memzero($dek);
        }
    }
}
