<?php

namespace App\Account\TwoFactor;

use App\Account\Entity\User;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

/**
 * TOTP RFC 6238 compatibile con Google Authenticator (SHA1, 6 cifre, 30 secondi),
 * compatibile con le app di autenticazione (Google Authenticator e simili).
 *
 * Tolleranza di un intervallo prima/dopo (orologi non sincronizzati) e
 * protezione anti-replay: un codice (intervallo) già usato non vale una seconda volta.
 */
final class TotpService
{
    public const ISSUER = 'CartellaViva';
    private const PERIOD = 30;
    private const WINDOW = 1;

    public function __construct(
        private readonly SecretEncryptor $encryptor,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    /** Nuovo segreto base32 (160 bit). */
    public function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    /** URI otpauth:// da mostrare come QR code nell'app di autenticazione. */
    public function provisioningUri(User $user, string $plainSecret): string
    {
        $totp = TOTP::createFromSecret($plainSecret);
        $totp->setLabel($user->getEmail());
        $totp->setIssuer(self::ISSUER);

        return $totp->getProvisioningUri();
    }

    /**
     * Verifica il codice sul segreto cifrato indicato. Restituisce l'intervallo
     * accettato (da salvare come ultimo usato) oppure null.
     */
    public function verify(string $encryptedSecret, string $code, ?int $lastUsedTimestep): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $totp = TOTP::createFromSecret($this->encryptor->decrypt($encryptedSecret));
        $current = intdiv($this->now(), self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; ++$offset) {
            $timestep = $current + $offset;
            if (hash_equals($totp->at($timestep * self::PERIOD), $code)) {
                return (null !== $lastUsedTimestep && $timestep <= $lastUsedTimestep) ? null : $timestep;
            }
        }

        return null;
    }

    /** Solo per test: codice valido per il segreto in chiaro all'istante corrente. */
    public function currentCode(string $plainSecret, int $offsetSteps = 0): string
    {
        return TOTP::createFromSecret($plainSecret)->at($this->now() + $offsetSteps * self::PERIOD);
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}
