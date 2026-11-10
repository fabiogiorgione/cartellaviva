<?php

namespace App\Tests\Functional;

use App\Account\Entity\Geo\GeoComune;
use App\Tests\Support\TestGoogleJwksProvider;

final class AnagraficaTest extends ApiTestCase
{
    public function testOnboardingAfterGoogleCreatesProfileAndFamilySpace(): void
    {
        $login = $this->request('POST', '/api/auth/google', ['idToken' => TestGoogleJwksProvider::sign('g-onb', 'mario.verdi@example.com')]);
        $token = $login['token'];

        // Il CF si può calcolare dai dati.
        $cf = $this->request('POST', '/api/anagrafica/codice-fiscale', $this->privato(), $token);
        $this->assertStatus(200);
        self::assertSame('VRDMRA80E10F205', substr($cf['codiceFiscale'], 0, 15));

        $saved = $this->request('PUT', '/api/me/anagrafica', $this->privato(['codiceFiscale' => $cf['codiceFiscale']]), $token);
        $this->assertStatus(200);
        self::assertTrue($saved['profileCompleted']);
        self::assertNotNull($saved['createdTenantId']);
        self::assertSame('+393331234567', $saved['anagrafica']['telefono'][0]['phoneNumber']);
        self::assertStringStartsWith('Milano', $saved['anagrafica']['comuneDiNascitaLabel']);

        $me = $this->request('GET', '/api/me', token: $token);
        self::assertTrue($me['profileCompleted']);
        self::assertSame('Famiglia Verdi', $me['memberships'][0]['tenant']['name']);
        self::assertSame('owner', $me['memberships'][0]['role']['slug']);
        self::assertSame('Mario', $me['firstName']);
    }

    public function testCodiceFiscaleMustMatchPersonalData(): void
    {
        $token = $this->login($this->createUser('cf@example.com'));

        $this->request('PUT', '/api/me/anagrafica', $this->privato(['codiceFiscale' => 'RSSMRA80A01H501U']), $token);

        $this->assertStatus(422);
        self::assertSame('Il codice fiscale non corrisponde ai dati inseriti', $this->violations()['codiceFiscale'] ?? null);
    }

    public function testPrivatoRequiredFieldsAndAdultsOnly(): void
    {
        $token = $this->login($this->createUser('req@example.com'));

        $this->request('PUT', '/api/me/anagrafica', ['type' => 'privato', 'dataDiNascita' => (new \DateTimeImmutable('-10 years'))->format('Y-m-d')], $token);

        $this->assertStatus(422);
        $violations = $this->violations();
        foreach (['nome', 'cognome', 'sesso', 'nazioneDiNascita', 'codiceFiscale', 'addresses'] as $field) {
            self::assertArrayHasKey($field, $violations, $field);
        }
        self::assertSame('E\' possibile censire solamente maggiorenni', $violations['dataDiNascita']);
    }

    public function testAziendaChecksPartitaIva(): void
    {
        $token = $this->login($this->createUser('az@example.com'));
        $azienda = [
            'type' => 'azienda',
            'denominazione' => 'Acme S.r.l.',
            'nome' => 'Mario',
            'cognome' => 'Verdi',
            'vat' => '12345678901',
            'pec' => [['email' => 'non-una-email']],
            'addresses' => [['type' => 'residenza', 'nazione' => 100, 'indirizzo' => 'Via Dante 1', 'comune' => $this->milano()->getId(), 'cap' => '20121']],
        ];

        $this->request('PUT', '/api/me/anagrafica', $azienda, $token);
        $this->assertStatus(422);
        $violations = $this->violations();
        self::assertSame('La Partita Iva non rispetta i parametri di verifica', $violations['vat']);
        self::assertSame('Il campo email deve essere una mail valida', $violations['pec[0].email']);

        // P.IVA valida (checksum corretto) e PEC corretta: salvataggio riuscito.
        $saved = $this->request('PUT', '/api/me/anagrafica', ['vat' => '00743110157', 'pec' => [['email' => 'acme@pec.it']]] + $azienda, $token);
        $this->assertStatus(200);
        self::assertNull($saved['createdTenantId'], 'Un\'anagrafica azienda non crea una famiglia');
    }

    public function testComuniSearchIncludesDiscontinuedOnlyOnRequest(): void
    {
        $token = $this->login($this->createUser('geo@example.com'));

        // "Abano" è stato soppresso nel 1924 (oggi Abano Terme): serve per chi vi è nato.
        $active = $this->request('GET', '/api/geo/comuni?q=abano', token: $token);
        self::assertSame(['Abano Terme (PD)'], array_column($active, 'name'));

        $all = $this->request('GET', '/api/geo/comuni?q=abano&storici=1', token: $token);
        self::assertContains('Abano (PD) - fino al 11/1924', array_column($all, 'name'));
    }

    /** @param array<string, mixed> $overrides */
    private function privato(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'privato',
            'nome' => 'Mario',
            'cognome' => 'Verdi',
            'sesso' => 'm',
            'dataDiNascita' => '1980-05-10',
            'nazioneDiNascita' => 100,
            'comuneDiNascita' => $this->milano()->getId(),
            'cittadinanza' => 100,
            'addresses' => [['type' => 'residenza', 'nazione' => 100, 'indirizzo' => 'Via Dante 1', 'comune' => $this->milano()->getId(), 'cap' => '20121']],
            'telefono' => [['phoneNumber' => '333 123 4567', 'main' => true]],
            'email' => [['email' => 'mario.verdi@example.com', 'main' => true]],
        ];
    }

    private function milano(): GeoComune
    {
        return $this->em()->getRepository(GeoComune::class)->findOneBy(['registryCode' => 'F205', 'status' => GeoComune::STATUS_ACTIVE]);
    }

    /** @return array<string, string> propertyPath => primo messaggio */
    private function violations(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $result = [];
        foreach ($data['violations'] ?? [] as $violation) {
            $result[$violation['propertyPath']] ??= $violation['title'] ?? $violation['message'] ?? '';
        }

        return $result;
    }
}
