<?php

namespace App\Import\Provider\GoogleDrive;

use App\Import\Exception\ImportException;
use App\Import\OAuthTokens;

/**
 * Parte OAuth di Google Drive, separata dalle chiamate all'API di Drive così che
 * nei test si possa sostituire (App\Tests\Support\TestGoogleOAuth).
 */
interface GoogleOAuthInterface
{
    /** Scope in sola lettura su tutto Drive: è uno scope "restricted" per Google (vedi README). */
    public const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

    public function isConfigured(): bool;

    public function authorizationUrl(string $state): string;

    /** @throws ImportException */
    public function exchangeCode(string $code): OAuthTokens;

    /** @throws ImportException */
    public function refresh(string $refreshToken): OAuthTokens;

    /** Revoca il token presso Google (best effort: un errore non deve bloccare il chiamante). */
    public function revoke(string $token): void;
}
