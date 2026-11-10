<?php

namespace App\Import\Exception;

use App\Import\Enum\ImportProvider;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Credenziali OAuth del provider assenti nell'ambiente (es. GOOGLE_OAUTH_CLIENT_ID vuoto).
 * Nelle API diventa un 422 con un messaggio esplicito, mai un 500.
 */
final class ProviderNonConfiguratoException extends UnprocessableEntityHttpException
{
    public function __construct(ImportProvider $provider)
    {
        parent::__construct(sprintf('Provider non configurato: %s non è disponibile su questo server (credenziali OAuth mancanti).', $provider->label()));
    }
}
