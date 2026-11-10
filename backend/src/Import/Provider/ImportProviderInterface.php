<?php

namespace App\Import\Provider;

use App\Import\Enum\ImportProvider;
use App\Import\Exception\ImportException;
use App\Import\OAuthTokens;
use App\Import\RemoteFile;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un servizio esterno da cui importare documenti. Le implementazioni vengono
 * raccolte da ImportProviderRegistry tramite il tag: il resto del codice non
 * conosce i singoli provider.
 */
#[AutoconfigureTag('app.import_provider')]
interface ImportProviderInterface
{
    public function provider(): ImportProvider;

    /** Credenziali OAuth presenti nell'ambiente. */
    public function isConfigured(): bool;

    /** URL a cui mandare l'utente per autorizzare l'accesso in sola lettura. */
    public function authorizationUrl(string $state): string;

    /** @throws ImportException */
    public function exchangeCode(string $code): OAuthTokens;

    /** @throws ImportException se il refresh token è stato revocato o è scaduto */
    public function refresh(string $refreshToken): OAuthTokens;

    /** Revoca l'accesso concesso dall'utente (best effort). */
    public function revoke(string $refreshToken): void;

    /**
     * Id della cartella da quello che incolla l'utente (link copiato dal browser o
     * id nudo); null se non è riconoscibile.
     */
    public function folderIdFromInput(string $input): ?string;

    /**
     * File della cartella radice e delle sue sottocartelle di PRIMO livello
     * (le sottocartelle più profonde sono ignorate).
     *
     * @return list<RemoteFile>
     *
     * @throws ImportException
     */
    public function listFiles(string $accessToken, string $cartellaRadiceId): array;

    /** Contenuto del file (al massimo $maxBytes, oltre si interrompe il download). */
    public function download(string $accessToken, RemoteFile $file, int $maxBytes): string;
}
