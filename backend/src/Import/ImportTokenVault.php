<?php

namespace App\Import;

use App\Import\Entity\ImportSource;
use App\Import\Exception\ImportException;
use App\Shared\Crypto\DocumentCipher;

/**
 * Token OAuth delle sorgenti, cifrati come i documenti sensibili: chiave casuale
 * della sorgente (DEK) cifrata con la chiave del tenant derivata dalla master key.
 * I token in chiaro esistono solo in memoria, durante l'uso.
 */
final class ImportTokenVault
{
    private const ACCESS = 'oauth-access';
    private const REFRESH = 'oauth-refresh';

    public function __construct(private readonly DocumentCipher $cipher)
    {
    }

    /** Salva i token; se il provider non rinnova il refresh token si conserva quello attuale. */
    public function store(ImportSource $source, OAuthTokens $tokens): void
    {
        $refresh = $tokens->refreshToken ?? ($source->isCollegata() ? $this->refreshToken($source) : null);
        if (null === $refresh || '' === $refresh) {
            throw new ImportException('Il provider non ha restituito un refresh token: rimuovi l\'accesso di CartellaViva dal tuo account Google e ricollega.');
        }

        $dek = $this->cipher->generateDek();
        $wrapped = $this->cipher->wrapDek($dek, $source->getTenant()->getId(), $source->getId());
        $source->storeTokens(
            $wrapped['wrapped'],
            $wrapped['version'],
            base64_encode($this->cipher->encryptSecret($tokens->accessToken, $dek, $source->getId(), self::ACCESS)),
            base64_encode($this->cipher->encryptSecret($refresh, $dek, $source->getId(), self::REFRESH)),
            $tokens->scadenza,
        );
        sodium_memzero($dek);
    }

    public function accessToken(ImportSource $source): string
    {
        return $this->decrypt($source, (string) $source->getOauthAccessToken(), self::ACCESS);
    }

    public function refreshToken(ImportSource $source): string
    {
        return $this->decrypt($source, (string) $source->getOauthRefreshToken(), self::REFRESH);
    }

    /** L'access token è scaduto o sta per scadere (margine di 2 minuti)? */
    public function isAccessTokenExpiring(ImportSource $source): bool
    {
        $scadenza = $source->getOauthTokenScadenza();

        return null === $scadenza || $scadenza <= new \DateTimeImmutable('+2 minutes');
    }

    private function decrypt(ImportSource $source, string $blob, string $purpose): string
    {
        if (null === $source->getEncryptedDek()) {
            throw new ImportException('La sorgente non è collegata a un account.');
        }
        $dek = $this->cipher->unwrapDek($source->getEncryptedDek(), (int) $source->getKeyVersion(), $source->getTenant()->getId(), $source->getId());
        try {
            return $this->cipher->decryptSecret((string) base64_decode($blob, true), $dek, $source->getId(), $purpose);
        } finally {
            sodium_memzero($dek);
        }
    }
}
