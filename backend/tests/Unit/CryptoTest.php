<?php

namespace App\Tests\Unit;

use App\Shared\Crypto\CryptoException;
use App\Shared\Crypto\DocumentCipher;
use App\Shared\Crypto\KeyBackup;
use App\Shared\Crypto\KeyVersionGuard;
use App\Shared\Crypto\MasterKeyRing;
use App\Shared\Crypto\TenantKeyService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CryptoTest extends TestCase
{
    private string $keyFile;

    protected function setUp(): void
    {
        $this->keyFile = tempnam(sys_get_temp_dir(), 'fv-key-');
        file_put_contents($this->keyFile, MasterKeyRing::generateKeyLine(1)."\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
    }

    public function testEnvelopeEncryptionRoundTripAndBindingToDocumentAndTenant(): void
    {
        $cipher = $this->cipher();
        $tenant = Uuid::v7();
        $doc = Uuid::v7();

        $dek = $cipher->generateDek();
        $wrapped = $cipher->wrapDek($dek, $tenant, $doc);
        $blob = $cipher->encryptContent('contenuto riservato', $dek, $doc);

        self::assertSame(1, $wrapped['version']);
        self::assertStringNotContainsString($dek, base64_decode($wrapped['wrapped']));
        $unwrapped = $cipher->unwrapDek($wrapped['wrapped'], 1, $tenant, $doc);
        self::assertSame('contenuto riservato', $cipher->decryptContent($blob, $unwrapped, $doc));

        // Un blob copiato su un altro documento non si decifra.
        $this->assertFails(fn () => $cipher->decryptContent($blob, $unwrapped, Uuid::v7()));
        // La DEK non si apre con la chiave di un altro tenant.
        $this->assertFails(fn () => $cipher->unwrapDek($wrapped['wrapped'], 1, Uuid::v7(), $doc));
        // Manomissione del contenuto rilevata.
        $this->assertFails(fn () => $cipher->decryptContent(substr($blob, 0, -1).(substr($blob, -1) ^ "\x01"), $unwrapped, $doc));
    }

    public function testRotationKeepsOldDocumentsReadableAndRewrapUsesNewVersion(): void
    {
        $cipher = $this->cipher();
        $tenant = Uuid::v7();
        $doc = Uuid::v7();
        $dek = $cipher->generateDek();
        $v1 = $cipher->wrapDek($dek, $tenant, $doc);

        file_put_contents($this->keyFile, MasterKeyRing::generateKeyLine(2)."\n", \FILE_APPEND);
        $rotated = $this->cipher();
        self::assertSame($dek, $rotated->unwrapDek($v1['wrapped'], 1, $tenant, $doc));
        $v2 = $rotated->wrapDek($dek, $tenant, $doc);
        self::assertSame(2, $v2['version']);
        self::assertSame($dek, $rotated->unwrapDek($v2['wrapped'], 2, $tenant, $doc));
    }

    public function testWrongKeyFileIsRefused(): void
    {
        $guard = $this->createStub(KeyVersionGuard::class);
        $guard->method('assertValid')->willThrowException(new CryptoException('mismatch'));
        $cipher = new DocumentCipher(new TenantKeyService(new MasterKeyRing($this->keyFile), $guard));

        $this->expectException(CryptoException::class);
        $cipher->wrapDek($cipher->generateDek(), Uuid::v7(), Uuid::v7());
    }

    public function testKeyBackupRoundTripAndWrongPassphrase(): void
    {
        $contents = (string) file_get_contents($this->keyFile);
        $backup = KeyBackup::export($contents, 'una passphrase molto lunga', [1 => 'abc']);

        self::assertStringNotContainsString(explode(':', trim($contents))[1], $backup);
        self::assertSame($contents, KeyBackup::import($backup, 'una passphrase molto lunga'));
        $this->assertFails(fn () => KeyBackup::import($backup, 'passphrase sbagliata!!'));
        $this->assertFails(fn () => KeyBackup::export($contents, 'corta', []));
    }

    private function cipher(): DocumentCipher
    {
        $guard = $this->createStub(KeyVersionGuard::class);

        return new DocumentCipher(new TenantKeyService(new MasterKeyRing($this->keyFile), $guard));
    }

    private function assertFails(callable $fn): void
    {
        try {
            $fn();
        } catch (CryptoException) {
            $this->addToAssertionCount(1);

            return;
        }
        self::fail('Era attesa una CryptoException');
    }
}
