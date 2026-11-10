<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Ente\AutomazioniEnte;
use App\Ente\Entity\ModelloRichiesta;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * Ente tappa 2 (fase 5): risposta senza account dal link sicuro con codice
 * email, collegamento successivo all'account con le risposte passate, modelli,
 * rinnovo alla scadenza, richieste ricorrenti, conservazione.
 */
final class EnteFase5Test extends ApiTestCase
{
    private const CF = '80012345676';

    public function testNoAccountLinkWithEmailCodeThenAccountGetsPastAnswers(): void
    {
        [$tid, $token, $leo] = $this->enteConLeo();
        $pdf = $this->fixture('ocr_fattura_scansione.pdf');

        // Nessun referente collegato: la richiesta va via link a chi ha un'email.
        $anteprima = $this->request('POST', "/api/enti/$tid/richieste/anteprima", ['iscrittoIds' => [$leo['id']]], $token);
        self::assertSame([0, 1, []], [$anteprima['famiglie'], $anteprima['viaLink'], $anteprima['nonRaggiunti']]);
        $this->mailer()->reset();
        $r = $this->multipart("/api/enti/$tid/richieste", ['tipo' => 'firma', 'titolo' => 'Uscita al museo', 'iscrittoIds' => json_encode([$leo['id']]), 'firmatari' => 'tutti', 'entroIl' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d')], $pdf, 'museo.pdf', $token);
        $this->assertStatus(201);
        $email = $this->ultimaEmail();
        self::assertSame('elena@example.com', $email->getTo()[0]->getAddress());
        self::assertStringNotContainsString('museo', (string) $email->getHtmlBody(), 'nessun contenuto nell\'email');
        $link = $this->token($email, 'risposta');

        // Senza codice si vede solo l'ente.
        $this->public('GET', "/api/link/$link");
        $this->assertStatus(200);
        $this->public('GET', "/api/link/$link/richiesta");
        $this->assertStatus(403);
        $this->public('POST', "/api/link/$link/codice");
        $this->assertStatus(204);
        $codice = $this->codice($this->ultimaEmail());
        $this->public('POST', "/api/link/$link/verifica", ['codice' => '000000' === $codice ? '111111' : '000000']);
        $this->assertStatus(422);
        $sessione = $this->public('POST', "/api/link/$link/verifica", ['codice' => $codice])['sessione'];
        $vista = $this->public('GET', "/api/link/$link/richiesta", null, $sessione);
        self::assertSame(['Uscita al museo', 'Leo Rossi', 'e•••@example.com'], [$vista['titolo'], $vista['iscritto'], $vista['email']]);

        // Risposta dal browser: copia all'ente, da verificare.
        // Il nome scelto dal browser non decide l'estensione (qui è un PDF).
        $this->multipartPublic("/api/link/$link/risposta", [], $pdf, 'firmato.html', $sessione);
        $this->assertStatus(200);
        $dettaglio = $this->request('GET', "/api/enti/$tid/richieste/{$r['id']}", token: $token);
        $dest = $dettaglio['avanzamento'][0]['destinatari'][0];
        self::assertTrue($dest['viaLink']);
        self::assertSame('da_verificare', $dest['stato']);
        $this->client->request('GET', "/api/enti/$tid/richieste/{$r['id']}/destinatari/{$dest['id']}/file", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertMatchesRegularExpression('/\.pdf"?$/', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        // Elena crea l'account e collega il link: riceve la sua copia e il collegamento.
        $elena = $this->createUser('elena@example.com');
        $famiglia = $this->createTenant('Famiglia Conti');
        $this->addMember($elena, $famiglia, Role::OWNER);
        $esito = $this->request('POST', "/api/link/$link/collega", ['tenantId' => (string) $famiglia->getId(), 'nuovaPersona' => ['nome' => 'Leo', 'cognome' => 'Rossi']], $this->login($elena), extra: ['HTTP_X_LINK_SESSIONE' => $sessione]);
        $this->assertStatus(200);
        self::assertSame(1, $esito['risposte']);
        $elenaToken = $this->login($elena);
        $sue = $this->request('GET', "/api/tenants/{$famiglia->getId()}/richieste", token: $elenaToken);
        self::assertSame('da_verificare', $sue[0]['stato']);
        $doc = $this->request('GET', "/api/tenants/{$famiglia->getId()}/richieste/{$sue[0]['id']}", token: $elenaToken)['documentoId'];
        self::assertSame('Condivisi con enti', $this->request('GET', "/api/tenants/{$famiglia->getId()}/documenti/$doc", token: $elenaToken)['categoria']['nome']);
        self::assertSame('collegato', $this->request('GET', "/api/enti/$tid/iscritti/{$leo['id']}", token: $token)['referenti'][0]['stato']);
        // Il link non vale più.
        $this->public('GET', "/api/link/$link");
        $this->assertStatus(410);
    }

    public function testValidityRenewalBeforeExpiryAndRetention(): void
    {
        [$tid, $token, $leo] = $this->enteConLeo();
        [$famiglia, $elena] = $this->collegaElena($tid, $token, $leo);
        $modello = $this->multipart("/api/enti/$tid/modelli", ['nome' => 'Certificato medico', 'tipo' => 'invio', 'titolo' => 'Certificato medico sportivo', 'firmatari' => 'uno', 'rinnovoGiorni' => '30', 'conservazioneMesi' => '12'], null, null, $token);
        $this->assertStatus(201);
        self::assertTrue($modello['chiediValidita'], 'il rinnovo richiede la data di validità');

        $r = $this->multipart("/api/enti/$tid/richieste", ['tipo' => 'invio', 'titolo' => 'Certificato medico sportivo', 'iscrittoIds' => json_encode([$leo['id']]), 'firmatari' => 'uno', 'modelloId' => $modello['id']], null, null, $token);
        $this->assertStatus(201);
        $fid = (string) $famiglia->getId();
        $elenaToken = $this->login($elena);
        $d = $this->request('GET', "/api/tenants/$fid/richieste", token: $elenaToken)[0];
        self::assertTrue($d['chiediValidita']);
        $png = $this->fixture('ocr_fattura.png');
        $this->multipart("/api/tenants/$fid/richieste/{$d['id']}/risposta", ['firmatari' => [$leo['referenti'][0]['id']]], $png, 'cert.png', $elenaToken);
        $this->assertStatus(422);
        $scade = (new \DateTimeImmutable('+20 days'))->format('Y-m-d');
        $this->multipart("/api/tenants/$fid/richieste/{$d['id']}/risposta", ['firmatari' => [$leo['referenti'][0]['id']], 'validoFino' => $scade], $png, 'cert.png', $elenaToken);
        $this->assertStatus(200);
        $destId = $this->request('GET', "/api/enti/$tid/richieste/{$r['id']}", token: $token)['avanzamento'][0]['destinatari'][0]['id'];
        $this->request('POST', "/api/enti/$tid/richieste/{$r['id']}/destinatari/$destId/verifica", ['esito' => 'accetta'], $token);

        $inScadenza = $this->request('GET', "/api/enti/$tid/documenti-in-scadenza", token: $token);
        self::assertSame([$scade, true], [$inScadenza[0]['validoFino'], $inScadenza[0]['rinnovoAutomatico']]);

        // Mancano 20 giorni (< 30): il job manda il rinnovo al solo Leo, una volta sola.
        self::assertSame(1, $this->automazioni()->rinnovi(new \DateTimeImmutable('today')));
        self::assertSame(0, $this->automazioni()->rinnovi(new \DateTimeImmutable('today')));
        $richieste = $this->request('GET', "/api/enti/$tid/richieste", token: $token);
        self::assertCount(2, $richieste);
        self::assertTrue($richieste[0]['rinnovo']);
        self::assertSame($scade, $richieste[0]['entroIl'], 'il rinnovo va consegnato entro la scadenza del documento');

        // Conservazione: dopo 12 mesi la copia dell'ente viene eliminata.
        self::assertSame(0, $this->automazioni()->conservazione(new \DateTimeImmutable('+11 months')));
        self::assertSame(1, $this->automazioni()->conservazione(new \DateTimeImmutable('+13 months')));
        $dest = $this->request('GET', "/api/enti/$tid/richieste/{$r['id']}", token: $token)['avanzamento'][0]['destinatari'][0];
        self::assertFalse($dest['haFile']);
        self::assertNotNull($dest['fileRimossoIl']);
    }

    public function testRecurringModelSendsToWholeEnteOnItsDate(): void
    {
        [$tid, $token] = $this->enteConLeo();
        $oggi = new \DateTimeImmutable('today');
        $m = $this->multipart("/api/enti/$tid/modelli", [
            'nome' => 'Liberatoria foto', 'tipo' => 'presa_visione', 'titolo' => 'Liberatoria foto', 'firmatari' => 'uno',
            'ricorrenzaGiorno' => $oggi->format('j'), 'ricorrenzaMese' => $oggi->format('n'), 'giorniRisposta' => '10',
        ], null, null, $token);
        $this->assertStatus(201);
        self::assertSame($oggi->format('Y-m-d'), $m['prossimoInvio']);

        self::assertSame(1, $this->automazioni()->ricorrenze($oggi));
        $richieste = $this->request('GET', "/api/enti/$tid/richieste", token: $token);
        self::assertSame('Liberatoria foto', $richieste[0]['titolo']);
        self::assertSame($oggi->modify('+10 days')->format('Y-m-d'), $richieste[0]['entroIl']);
        $this->em()->clear();
        $modello = $this->em()->find(ModelloRichiesta::class, $m['id']);
        self::assertSame($oggi->modify('+1 year')->format('Y-m-d'), $modello->getProssimoInvio()?->format('Y-m-d'), 'prossimo invio fra un anno');
        self::assertSame(0, $this->automazioni()->ricorrenze($oggi));
    }

    /** Un invio fallito (es. passaggio d'anno senza iscritti) si ritenta il giorno dopo. */
    public function testFailedRecurringIsRetriedUntilPupilsArrive(): void
    {
        [$tid, $token, $m, $oggi] = $this->ricorrenteSenzaIscritti();

        self::assertSame(0, $this->automazioni()->ricorrenze($oggi));
        self::assertCount(1, $this->auditRows('richiesta.ricorrente', 'failure'));
        self::assertSame($oggi->format('Y-m-d'), $this->prossimoInvio($m['id']), 'la data resta: si riprova');
        self::assertSame(0, $this->automazioni()->ricorrenze($oggi->modify('+1 day')));
        self::assertSame($oggi->format('Y-m-d'), $this->prossimoInvio($m['id']));

        // L'ente carica gli iscritti del nuovo anno: il giorno dopo la richiesta parte.
        $gruppo = $this->request('POST', "/api/enti/$tid/gruppi", ['nome' => '1ªA'], $token);
        $this->request('POST', "/api/enti/$tid/iscritti", ['nome' => 'Sara', 'cognome' => 'Neri', 'gruppiIds' => [$gruppo['id']], 'referenti' => [
            ['nome' => 'Anna', 'cognome' => 'Neri', 'email' => 'anna@example.com', 'relazione' => 'madre'],
        ]], $token);
        $this->assertStatus(201);
        self::assertSame(1, $this->automazioni()->ricorrenze($oggi->modify('+2 days')));
        self::assertSame('Liberatoria foto', $this->request('GET', "/api/enti/$tid/richieste", token: $token)[0]['titolo']);
        self::assertSame($oggi->modify('+1 year')->format('Y-m-d'), $this->prossimoInvio($m['id']), 'prossimo invio fra un anno');
        self::assertSame(0, $this->automazioni()->ricorrenze($oggi->modify('+2 days')));
    }

    public function testFailedRecurringGivesUpForTheYearAfterTheRetryWindow(): void
    {
        [, , $m, $oggi] = $this->ricorrenteSenzaIscritti();

        self::assertSame(0, $this->automazioni()->ricorrenze($oggi->modify(sprintf('+%d days', AutomazioniEnte::RICORRENZA_RIPROVA_GIORNI - 1))));
        self::assertSame($oggi->format('Y-m-d'), $this->prossimoInvio($m['id']));
        self::assertSame(0, $this->automazioni()->ricorrenze($oggi->modify(sprintf('+%d days', AutomazioniEnte::RICORRENZA_RIPROVA_GIORNI))));
        self::assertSame($oggi->modify('+1 year')->format('Y-m-d'), $this->prossimoInvio($m['id']), 'rinuncia per quest\'anno');
        $fallimenti = $this->auditRows('richiesta.ricorrente', 'failure');
        self::assertCount(2, $fallimenti);
    }

    public function testRecurringAlreadySentByHandFromTheTemplateIsNotDuplicated(): void
    {
        [$tid, $token, $leo] = $this->enteConLeo();
        $oggi = new \DateTimeImmutable('today');
        $m = $this->multipart("/api/enti/$tid/modelli", [
            'nome' => 'Liberatoria foto', 'tipo' => 'presa_visione', 'titolo' => 'Liberatoria foto', 'firmatari' => 'uno',
            'ricorrenzaGiorno' => $oggi->format('j'), 'ricorrenzaMese' => $oggi->format('n'), 'giorniRisposta' => '10',
        ], null, null, $token);
        $this->multipart("/api/enti/$tid/richieste", ['tipo' => 'presa_visione', 'titolo' => 'Liberatoria foto', 'iscrittoIds' => json_encode([$leo['id']]), 'firmatari' => 'uno', 'modelloId' => $m['id']], null, null, $token);
        $this->assertStatus(201);

        self::assertSame(0, $this->automazioni()->ricorrenze($oggi));
        self::assertCount(1, $this->request('GET', "/api/enti/$tid/richieste", token: $token));
        self::assertSame($oggi->modify('+1 year')->format('Y-m-d'), $this->prossimoInvio($m['id']));
    }

    public function testAutomationsIgnoreAnEnteBeingDeleted(): void
    {
        [$tid, $token] = $this->enteConLeo();
        $oggi = new \DateTimeImmutable('today');
        $m = $this->multipart("/api/enti/$tid/modelli", [
            'nome' => 'Liberatoria foto', 'tipo' => 'presa_visione', 'titolo' => 'Liberatoria foto', 'firmatari' => 'uno',
            'ricorrenzaGiorno' => $oggi->format('j'), 'ricorrenzaMese' => $oggi->format('n'), 'giorniRisposta' => '10',
        ], null, null, $token);
        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => 'admin@cartellaviva.local']);
        $this->request('DELETE', "/api/admin/tenants/$tid", ['motivo' => 'Chiusura della scuola.'], $this->login($admin));
        $this->assertStatus(202);

        self::assertSame(['rinnovi' => 0, 'ricorrenze' => 0, 'fileEliminati' => 0], $this->automazioni()->esegui($oggi));
        $this->em()->clear();
        self::assertSame($oggi->format('Y-m-d'), $this->em()->find(ModelloRichiesta::class, $m['id'])->getProssimoInvio()?->format('Y-m-d'), 'la data non avanza');
    }

    public function testDailyJobRunsAllRulesAndReportsCounts(): void
    {
        [$tid, $token] = $this->enteConLeo();
        $oggi = new \DateTimeImmutable('today');
        $this->multipart("/api/enti/$tid/modelli", [
            'nome' => 'Liberatoria foto', 'tipo' => 'presa_visione', 'titolo' => 'Liberatoria foto', 'firmatari' => 'uno',
            'ricorrenzaGiorno' => $oggi->format('j'), 'ricorrenzaMese' => $oggi->format('n'), 'giorniRisposta' => '10',
        ], null, null, $token);

        // Il messaggio del job giornaliero (Schedule) esegue le tre regole insieme.
        static::getContainer()->get(\App\Ente\MessageHandler\AutomazioniEntiHandler::class)(new \App\Ente\Message\AutomazioniEntiMessage());
        $richieste = $this->request('GET', "/api/enti/$tid/richieste", token: $token);
        self::assertSame(['Liberatoria foto'], array_column($richieste, 'titolo'));
        self::assertSame(['rinnovi' => 0, 'ricorrenze' => 0, 'fileEliminati' => 0], $this->automazioni()->esegui($oggi), 'una seconda esecuzione non manda nulla');
    }

    public function testRecurrenceDateHandlesLeapYears(): void
    {
        self::assertSame('2027-02-28', ModelloRichiesta::prossimaData(29, 2, new \DateTimeImmutable('2026-10-02'))->format('Y-m-d'));
        self::assertSame('2028-02-29', ModelloRichiesta::prossimaData(29, 2, new \DateTimeImmutable('2028-01-01'))->format('Y-m-d'));
        self::assertSame('2027-09-15', ModelloRichiesta::prossimaData(15, 9, new \DateTimeImmutable('2026-09-16'))->format('Y-m-d'));
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return array{0: string, 1: string, 2: array<string, mixed>} */
    private function enteConLeo(): array
    {
        $segretaria = $this->createUser('segreteria@scuola.example');
        $admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);
        $token = $this->login($segretaria);
        $tid = $this->request('POST', '/api/enti', ['tipo' => 'scuola', 'denominazione' => 'Scuola Verdi', 'codiceFiscale' => self::CF, 'codiceMeccanografico' => 'RMEE000001'], $token)['tenantId'];
        $this->request('POST', "/api/enti/$tid/email/conferma", ['codice' => $this->codice($this->ultimaEmail())], $token);
        $this->request('POST', "/api/admin/enti/$tid/decisione", ['azione' => 'approva', 'controlli' => ['registro' => true]], $this->login($admin));
        $gruppo = $this->request('POST', "/api/enti/$tid/gruppi", ['nome' => '3ªB'], $token);
        $leo = $this->request('POST', "/api/enti/$tid/iscritti", ['nome' => 'Leo', 'cognome' => 'Rossi', 'gruppiIds' => [$gruppo['id']], 'referenti' => [
            ['nome' => 'Elena', 'cognome' => 'Conti', 'email' => 'elena@example.com'],
        ]], $token);

        return [$tid, $token, $leo];
    }

    /** @return array{0: Tenant, 1: User} */
    private function collegaElena(string $tid, string $token, array $leo): array
    {
        $elena = $this->createUser('elena@example.com');
        $famiglia = $this->createTenant('Famiglia Conti');
        $this->addMember($elena, $famiglia, Role::OWNER);
        $this->mailer()->reset();
        $this->request('POST', "/api/enti/$tid/inviti", ['referenteIds' => [$leo['referenti'][0]['id']]], $token);
        $this->request('POST', '/api/collegamenti/accetta', ['token' => $this->token($this->ultimaEmail(), 'collegamenti/invito'), 'tenantId' => (string) $famiglia->getId(), 'nuovaPersona' => ['nome' => 'Leo', 'cognome' => 'Rossi']], $this->login($this->fresh($elena)));
        $this->assertStatus(201);

        return [$famiglia, $elena];
    }

    /**
     * Modello con ricorrenza oggi, subito dopo un passaggio d'anno senza gruppi.
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>, 3: \DateTimeImmutable}
     */
    private function ricorrenteSenzaIscritti(): array
    {
        [$tid, $token] = $this->enteConLeo();
        $anno = $this->request('GET', "/api/enti/$tid/anni", token: $token)[0];
        $this->request('POST', "/api/enti/$tid/anni/{$anno['id']}/passaggio", ['nome' => '2099/00', 'gruppi' => []], $token);
        $this->assertStatus(201);
        $oggi = new \DateTimeImmutable('today');
        $m = $this->multipart("/api/enti/$tid/modelli", [
            'nome' => 'Liberatoria foto', 'tipo' => 'presa_visione', 'titolo' => 'Liberatoria foto', 'firmatari' => 'uno',
            'ricorrenzaGiorno' => $oggi->format('j'), 'ricorrenzaMese' => $oggi->format('n'), 'giorniRisposta' => '10',
        ], null, null, $token);

        return [$tid, $token, $m, $oggi];
    }

    private function prossimoInvio(string $modelloId): ?string
    {
        $this->em()->clear();

        return $this->em()->find(ModelloRichiesta::class, $modelloId)?->getProssimoInvio()?->format('Y-m-d');
    }

    private function automazioni(): AutomazioniEnte
    {
        return static::getContainer()->get(AutomazioniEnte::class);
    }

    /** @return array<mixed>|null */
    private function public(string $method, string $uri, ?array $body = null, ?string $sessione = null): ?array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if (null !== $sessione) {
            $server['HTTP_X_LINK_SESSIONE'] = $sessione;
        }
        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body));
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? null : json_decode($content, true);
    }

    protected function request(string $method, string $uri, ?array $body = null, ?string $token = null, string $contentType = 'application/json', array $extra = []): ?array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $contentType] + $extra;
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? null : json_decode($content, true);
    }

    /** @return array<mixed>|null */
    private function multipart(string $uri, array $fields, ?string $contents, ?string $fileName, string $token): ?array
    {
        return $this->multipartRaw($uri, $fields, $contents, $fileName, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /** @return array<mixed>|null */
    private function multipartPublic(string $uri, array $fields, ?string $contents, ?string $fileName, string $sessione): ?array
    {
        return $this->multipartRaw($uri, $fields, $contents, $fileName, ['HTTP_X_LINK_SESSIONE' => $sessione]);
    }

    /** @return array<mixed>|null */
    private function multipartRaw(string $uri, array $fields, ?string $contents, ?string $fileName, array $server): ?array
    {
        $files = [];
        if (null !== $contents) {
            $path = tempnam(sys_get_temp_dir(), 'fv-');
            file_put_contents($path, $contents);
            $files['file'] = new UploadedFile($path, (string) $fileName, null, null, true);
        }
        $this->client->request('POST', $uri, $fields, $files, $server + ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data']);
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? null : json_decode($content, true);
    }

    private function token(Email $email, string $percorso): string
    {
        self::assertMatchesRegularExpression('#/'.preg_quote($percorso, '#').'\?token=([A-Za-z0-9_-]+)#', (string) $email->getHtmlBody());
        preg_match('#/'.preg_quote($percorso, '#').'\?token=([A-Za-z0-9_-]+)#', (string) $email->getHtmlBody(), $m);

        return $m[1];
    }

    private function codice(Email $email): string
    {
        preg_match('/>(\d{6})</', (string) $email->getHtmlBody(), $m);

        return $m[1];
    }
}
