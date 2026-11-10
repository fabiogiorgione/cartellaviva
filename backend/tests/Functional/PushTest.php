<?php

namespace App\Tests\Functional;

use App\Account\Entity\DispositivoPush;
use App\Account\Message\InviaPushMessage;
use App\Account\MessageHandler\InviaPushHandler;
use App\Account\Push\FcmPushGateway;
use App\Account\Push\PushNotifier;
use App\Tests\Support\FakePushGateway;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Fase 7.3: telefoni registrati, invio nel worker, token scaduti rimossi,
 * nessun contenuto riservato nelle notifiche, FCM HTTP v1 con service account.
 */
final class PushTest extends ApiTestCase
{
    public function testDeviceRegistrationFollowsTheUserAndInvalidTokensAreRemoved(): void
    {
        $mario = $this->createUser('mario@example.com');
        $anna = $this->createUser('anna@example.com');
        $tMario = $this->login($mario);

        $this->request('POST', '/api/me/dispositivi', ['token' => 'tok-1', 'piattaforma' => 'web'], $tMario);
        $this->assertStatus(422);
        self::assertTrue($this->request('POST', '/api/me/dispositivi', ['token' => 'tok-1', 'piattaforma' => 'android'], $tMario)['pushAttive']);
        $this->request('POST', '/api/me/dispositivi', ['token' => 'tok-2', 'piattaforma' => 'ios'], $tMario);
        // Stesso telefono, entra Anna: il token passa a lei.
        $this->request('POST', '/api/me/dispositivi', ['token' => 'tok-2', 'piattaforma' => 'ios'], $this->login($anna));
        self::assertSame(1, $this->dispositivi($mario->getEmail()));

        $this->queue()->reset();
        static::getContainer()->get(PushNotifier::class)->notifica($this->fresh($mario), 'CartellaViva', 'Promemoria: hai una scadenza in arrivo', '/scadenze');
        $msg = $this->queue()->getSent()[0]->getMessage();
        self::assertInstanceOf(InviaPushMessage::class, $msg);

        $gateway = static::getContainer()->get(FakePushGateway::class);
        $gateway->nonValidi = ['tok-1'];
        self::assertSame(['inviate' => 0, 'rimossi' => 1], static::getContainer()->get(InviaPushHandler::class)($msg));
        self::assertSame(0, $this->dispositivi($mario->getEmail()));

        // Al logout l'app toglie il token; nessun telefono = nessun messaggio in coda.
        $tAnna = $this->login($this->fresh($anna));
        $this->request('DELETE', '/api/me/dispositivi', ['token' => 'tok-2'], $tAnna);
        $this->assertStatus(204);
        $this->queue()->reset();
        static::getContainer()->get(PushNotifier::class)->notifica($this->fresh($anna), 'x', 'y', '/');
        self::assertCount(0, $this->queue()->getSent());
    }

    public function testFcmV1RequestWithServiceAccount(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $file = tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($file, json_encode(['client_email' => 'fcm@progetto.iam.gserviceaccount.com', 'private_key' => $pem]));

        $richieste = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$richieste): MockResponse {
            $richieste[] = ['url' => $url, 'options' => $options];
            if (str_contains($url, 'oauth2')) {
                return new MockResponse(json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600]));
            }
            if (str_contains((string) $options['body'], 'scaduto')) {
                return new MockResponse(json_encode(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]), ['http_code' => 404]);
            }

            return new MockResponse('{"name":"projects/p/messages/1"}');
        });
        $fcm = new FcmPushGateway($http, new ArrayAdapter(), 'progetto-fv', $file);
        self::assertTrue($fcm->isEnabled());
        self::assertSame('ok', $fcm->invia('tok-buono', 'CartellaViva', 'Hai una nuova richiesta da Scuola', ['route' => '/richieste']));
        self::assertSame('token_non_valido', $fcm->invia('tok-scaduto', 'CartellaViva', 'x', []));

        // JWT firmato e verificabile con la chiave pubblica del service account.
        parse_str((string) $richieste[0]['options']['body'], $form);
        [$h, $p, $s] = explode('.', $form['assertion']);
        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        self::assertSame(['fcm@progetto.iam.gserviceaccount.com', 'https://www.googleapis.com/auth/firebase.messaging'], [$claims['iss'], $claims['scope']]);
        self::assertSame(1, openssl_verify("$h.$p", base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - \strlen($s) % 4) % 4)), openssl_pkey_get_details($key)['key'], \OPENSSL_ALGO_SHA256));

        self::assertSame('https://fcm.googleapis.com/v1/projects/progetto-fv/messages:send', $richieste[1]['url']);
        $body = json_decode((string) $richieste[1]['options']['body'], true);
        self::assertSame(['tok-buono', '/richieste'], [$body['message']['token'], $body['message']['data']['route']]);
        self::assertContains('Authorization: Bearer ya29.test', $richieste[1]['options']['headers']);
        self::assertCount(3, $richieste, 'il token OAuth si riusa dalla cache');
        @unlink($file);

        self::assertFalse((new FcmPushGateway($http, new ArrayAdapter(), '', ''))->isEnabled());
    }

    private function dispositivi(string $email): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM dispositivo_push d JOIN app_user u ON u.id = d.user_id WHERE u.email = ?', [$email]);
    }

    private function queue(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }
}
