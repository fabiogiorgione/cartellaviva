<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * Ente (fase 3): registrazione e verifica, gruppi e ambito degli operatori,
 * import degli iscritti, collegamento con le famiglie, richieste di firma.
 */
final class EnteTest extends ApiTestCase
{
    private const CF = '80012345676';

    private User $segretaria;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->segretaria = $this->createUser('segreteria@scuola.example');
        $this->admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);
    }

    public function testRegistrationEmailCodeAndPortalApproval(): void
    {
        $token = $this->login($this->segretaria);
        $this->request('POST', '/api/enti', ['tipo' => 'scuola', 'denominazione' => 'Scuola Verdi', 'codiceFiscale' => '80012345670', 'codiceMeccanografico' => 'RMEE000001'], $token);
        $this->assertStatus(422);

        $ente = $this->request('POST', '/api/enti', ['tipo' => 'scuola', 'denominazione' => 'Scuola Verdi', 'codiceFiscale' => self::CF, 'codiceMeccanografico' => 'rmee000001', 'emailIstituzionale' => 'altra@example.com'], $token);
        $this->assertStatus(201);
        self::assertSame('rmee000001@istruzione.it', $ente['emailIstituzionale'], 'l\'email della scuola si ricava dal codice meccanografico');
        self::assertSame('email_da_confermare', $ente['stato']);
        $tid = $ente['tenantId'];
        $codice = $this->codiceDaEmail();

        // Prima della verifica: niente inviti né richieste.
        $this->request('POST', "/api/enti/$tid/inviti", ['referenteIds' => ['x']], $token);
        $this->assertStatus(409);

        $this->request('POST', "/api/enti/$tid/email/conferma", ['codice' => '000000' === $codice ? '111111' : '000000'], $token);
        $this->assertStatus(422);
        $ok = $this->request('POST', "/api/enti/$tid/email/conferma", ['codice' => $codice], $token);
        self::assertSame('in_verifica', $ok['stato']);

        // Portale: solo superadmin; approvare richiede il controllo sul registro.
        $this->request('POST', "/api/admin/enti/$tid/decisione", ['azione' => 'approva', 'controlli' => ['registro' => true]], $token);
        $this->assertStatus(403);
        $adminToken = $this->login($this->admin);
        self::assertCount(1, $this->request('GET', '/api/admin/enti?stato=in_verifica', token: $adminToken));
        $this->request('POST', "/api/admin/enti/$tid/decisione", ['azione' => 'approva', 'controlli' => ['registro' => false]], $adminToken);
        $this->assertStatus(409);
        $this->request('POST', "/api/admin/enti/$tid/decisione", ['azione' => 'integrazioni'], $adminToken);
        $this->assertStatus(422);
        $approvato = $this->request('POST', "/api/admin/enti/$tid/decisione", ['azione' => 'approva', 'controlli' => ['registro' => true, 'telefonata' => false]], $adminToken);
        self::assertTrue($approvato['verificato']);
        self::assertSame('Il vostro ente è verificato su CartellaViva', $this->ultimaEmail()->getSubject());

        // Stesso codice fiscale: non si registra di nuovo.
        $this->request('POST', '/api/enti', ['tipo' => 'scuola', 'denominazione' => 'Copia', 'codiceFiscale' => self::CF, 'codiceMeccanografico' => 'RMEE000002'], $this->login($this->createUser('altro@example.com')));
        $this->assertStatus(409);
    }

    public function testOperatorSeesOnlyOwnGroupsAndImportDoesNotSendInvites(): void
    {
        [$tid, $token] = $this->enteVerificato();
        $a = $this->request('POST', "/api/enti/$tid/gruppi", ['nome' => '3ªA'], $token);
        $b = $this->request('POST', "/api/enti/$tid/gruppi", ['nome' => '3ªB'], $token);

        $csv = "nome;cognome;data_nascita;gruppo;ref1_nome;ref1_cognome;ref1_email;ref2_nome;ref2_cognome;ref2_email\n"
            ."Leo;Rossi;12/03/2018;3ªB;Mario;Rossi;mario@example.com;Giulia;Rossi;giulia@example.com\n"
            ."Sara;Bianchi;;3ªA;Luca;Bianchi;luca@example.com;;;\n"
            ."Omar;;;3ªB;;;;;;\n"
            ."Marco;Ferri;;5ªC;;;;;;\n";
        $anteprima = $this->request('POST', "/api/enti/$tid/iscritti/import", ['csv' => $csv], $token);
        self::assertSame([2, 2], [$anteprima['valide'], $anteprima['errori']]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ente_iscritto'));
        $this->mailer()->reset();
        $fatto = $this->request('POST', "/api/enti/$tid/iscritti/import", ['csv' => $csv, 'applica' => true], $token);
        self::assertSame(2, $fatto['nuovi']);
        self::assertSame(3, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ente_referente WHERE stato = ?', ['da_invitare']));
        self::assertEmailCount(0);
        // Reimportare non duplica.
        self::assertSame(2, $this->request('POST', "/api/enti/$tid/iscritti/import", ['csv' => $csv, 'applica' => true], $token)['esistenti']);

        // Operatore della sola 3ªB.
        $marta = $this->createUser('marta@scuola.example');
        $m = $this->addMember($marta, $this->ente($tid), 'staff');
        $this->request('PUT', "/api/enti/$tid/gruppi/{$b['id']}/operatori", ['membershipIds' => [(string) $m->getId()]], $token);
        $this->assertStatus(200);
        $martaToken = $this->login($marta);
        self::assertSame(['3ªB'], array_column($this->request('GET', "/api/enti/$tid/gruppi", token: $martaToken), 'nome'));
        $iscritti = $this->request('GET', "/api/enti/$tid/iscritti", token: $martaToken)['iscritti'];
        self::assertSame(['Leo'], array_column($iscritti, 'nome'));
        $sara = $this->request('GET', "/api/enti/$tid/iscritti?gruppo={$a['id']}", token: $token)['iscritti'][0];
        $this->request('GET', "/api/enti/$tid/iscritti/{$sara['id']}", token: $martaToken);
        $this->assertStatus(404);
        // L'operatore non gestisce la struttura.
        $this->request('POST', "/api/enti/$tid/gruppi", ['nome' => '4ªB'], $martaToken);
        $this->assertStatus(403);
    }

    public function testFamilyLinkFlowWithoutLeakingFamilyData(): void
    {
        [$tid, $token, $leo] = $this->enteConIscritto();
        [$famiglia, $mario] = $this->famiglia('mario@example.com', 'Famiglia Rossi');

        $this->mailer()->reset();
        $esito = $this->request('POST', "/api/enti/$tid/inviti", ['referenteIds' => array_column($leo['referenti'], 'id')], $token);
        self::assertSame(['inviati' => 2, 'saltati' => 0], $esito);
        [$tokenMario, $tokenGiulia] = $this->tokenInviti();

        // Anteprima pubblica.
        $this->client->request('GET', '/api/collegamenti/anteprima?token='.$tokenMario, server: ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatus(200);
        $anteprima = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Leo', $anteprima['iscritto']['nome']);
        self::assertSame(['3ªB'], $anteprima['gruppi']);

        // Un altro utente non può accettare l'invito di Mario.
        [$famigliaAltra] = $this->famiglia('intruso@example.com', 'Famiglia X');
        $this->request('POST', '/api/collegamenti/accetta', ['token' => $tokenMario, 'tenantId' => (string) $famigliaAltra->getId(), 'nuovaPersona' => ['nome' => 'Leo', 'cognome' => 'Rossi']], $this->login($this->fresh($this->userByEmail('intruso@example.com'))));
        $this->assertStatus(409);

        $marioToken = $this->login($mario);
        $c = $this->request('POST', '/api/collegamenti/accetta', ['token' => $tokenMario, 'tenantId' => (string) $famiglia->getId(), 'nuovaPersona' => ['nome' => 'Leo', 'cognome' => 'Rossi']], $marioToken);
        $this->assertStatus(201);
        self::assertSame('Leo Rossi', $c['persona']['nome']);
        $this->request('POST', '/api/collegamenti/accetta', ['token' => $tokenMario, 'tenantId' => (string) $famiglia->getId()], $marioToken);
        $this->assertStatus(410);

        // Giulia non è la persona giusta: "non sono io", anche senza account.
        $this->request('POST', '/api/collegamenti/rifiuta', ['token' => $tokenGiulia, 'nonSonoIo' => true]);
        $this->assertStatus(204);

        $visto = $this->request('GET', "/api/enti/$tid/iscritti/{$leo['id']}", token: $token);
        self::assertSame(['collegato', 'non_sono_io'], array_column($visto['referenti'], 'stato'));
        $json = json_encode($visto);
        self::assertStringNotContainsString((string) $famiglia->getId(), $json, 'l\'ente non vede la famiglia');
        self::assertStringNotContainsString($c['persona']['id'], $json, 'né la persona abbinata');

        // La famiglia interrompe il collegamento.
        $this->request('DELETE', "/api/tenants/{$famiglia->getId()}/collegamenti/{$c['id']}", token: $marioToken);
        $this->assertStatus(204);
        self::assertSame('revocato', $this->request('GET', "/api/enti/$tid/iscritti/{$leo['id']}", token: $token)['referenti'][0]['stato']);
    }

    /**
     * Le copie consegnate restano all'ente anche dopo la
     * cancellazione definitiva della famiglia (decisione di prodotto).
     */
    public function testPurgedFamilyLeavesDeliveredResponsesWithTheEnte(): void
    {
        [$tid, $token, $leo] = $this->enteConIscritto();
        [$famiglia, $mario] = $this->famiglia('mario@example.com', 'Famiglia Rossi');
        $this->collega($tid, $token, $leo['referenti'][0]['id'], $famiglia, $mario);
        $pdf = $this->fixture('ocr_fattura_scansione.pdf');
        $richiesta = $this->multipart("/api/enti/$tid/richieste", ['tipo' => 'firma', 'titolo' => 'Uscita al museo', 'gruppoId' => $leo['gruppi'][0]['id'], 'firmatari' => 'uno'], $pdf, 'autorizzazione.pdf', $token);
        $fid = (string) $famiglia->getId();
        $marioToken = $this->login($mario);
        $did = $this->request('GET', "/api/tenants/$fid/richieste?stato=da_fare", token: $marioToken)[0]['id'];
        $this->multipart("/api/tenants/$fid/richieste/$did/risposta", ['firmatari' => [$leo['referenti'][0]['id']]], $pdf, 'firmato.pdf', $marioToken);
        $this->assertStatus(200);

        // La famiglia viene cancellata per sempre (dopo l'attesa).
        $this->request('DELETE', "/api/admin/tenants/$fid", ['motivo' => 'Richiesta della famiglia.'], $this->login($this->admin));
        $this->assertStatus(202);
        $this->em()->getConnection()->executeStatement('UPDATE tenant SET eliminazione_programmata_il = :ieri WHERE id = :id', ['ieri' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), 'id' => $fid]);
        self::assertSame(1, static::getContainer()->get(\App\Tenancy\TenantDeletion::class)->purgeDue());
        self::assertFalse((bool) $this->em()->getConnection()->fetchOne('SELECT 1 FROM tenant WHERE id = :id', ['id' => $fid]));

        // L'ente ha ancora la risposta e il file.
        $dettaglio = $this->request('GET', "/api/enti/$tid/richieste/{$richiesta['id']}", token: $token);
        $this->assertStatus(200);
        $destinatari = array_merge(...array_column($dettaglio['avanzamento'], 'destinatari'));
        $risposte = array_values(array_filter($destinatari, static fn ($d) => $d['haFile'] ?? false));
        self::assertCount(1, $risposte, 'la risposta consegnata resta all\'ente');
        $this->client->request('GET', "/api/enti/$tid/richieste/{$richiesta['id']}/destinatari/{$risposte[0]['id']}/file", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame($pdf, $this->client->getResponse()->getContent());
    }

    public function testSignatureRequestDeliveryVerificationAndDeadline(): void
    {
        [$tid, $token, $leo] = $this->enteConIscritto();
        [$famiglia, $mario] = $this->famiglia('mario@example.com', 'Famiglia Rossi');
        $this->collega($tid, $token, $leo['referenti'][0]['id'], $famiglia, $mario);
        $gruppoId = $leo['gruppi'][0]['id'];
        $pdf = $this->fixture('ocr_fattura_scansione.pdf');

        // Giulia senza email: non è raggiungibile nemmeno dal link (qui si prova solo il flusso nell'app).
        $this->request('PUT', "/api/enti/$tid/referenti/{$leo['referenti'][1]['id']}", ['nome' => 'Giulia', 'cognome' => 'Rossi'], $token);
        $this->assertStatus(200);
        $anteprima = $this->request('POST', "/api/enti/$tid/richieste/anteprima", ['gruppoId' => $gruppoId], $token);
        self::assertSame(['iscritti' => 2, 'referenti' => 2, 'referentiCollegati' => 1, 'famiglie' => 1, 'viaLink' => 0, 'nonRaggiunti' => ['Omar Haddad']], $anteprima);

        $this->mailer()->reset();
        $entro = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $richiesta = $this->multipart("/api/enti/$tid/richieste", ['tipo' => 'firma', 'titolo' => 'Uscita al museo', 'gruppoId' => $gruppoId, 'firmatari' => 'tutti', 'entroIl' => $entro], $pdf, 'autorizzazione.pdf', $token);
        $this->assertStatus(201);
        self::assertSame(1, $richiesta['totali']['mancanti']);
        self::assertSame(1, $richiesta['totali']['nonRaggiunti']);
        $avviso = $this->ultimaEmail();
        self::assertSame('Hai una nuova richiesta da Scuola Verdi', $avviso->getSubject());
        self::assertStringNotContainsString('museo', (string) $avviso->getHtmlBody(), 'le email non contengono il titolo');

        // Famiglia: richiesta da fare + scadenza collegata, non spuntabile a mano.
        $fid = (string) $famiglia->getId();
        $marioToken = $this->login($mario);
        $lista = $this->request('GET', "/api/tenants/$fid/richieste?stato=da_fare", token: $marioToken);
        self::assertCount(1, $lista);
        $did = $lista[0]['id'];
        $scadenze = $this->request('GET', "/api/tenants/$fid/scadenze", token: $marioToken);
        self::assertSame('richiesta', $scadenze[0]['origine']);
        self::assertSame($did, $scadenze[0]['richiestaId']);
        $this->request('POST', "/api/tenants/$fid/scadenze/{$scadenze[0]['id']}/fatta", token: $marioToken);
        $this->assertStatus(409);

        $this->client->request('GET', "/api/tenants/$fid/richieste/$did/allegato", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$marioToken]);
        self::assertSame($pdf, $this->client->getResponse()->getContent());

        // Risposta: copia all'ente e alla famiglia, scadenza in verifica.
        $risposta = $this->multipart("/api/tenants/$fid/richieste/$did/risposta", ['firmatari' => [$leo['referenti'][0]['id']]], $pdf, 'firmato.pdf', $marioToken);
        $this->assertStatus(200);
        self::assertSame('da_verificare', $risposta['stato']);
        $doc = $this->request('GET', "/api/tenants/$fid/documenti/{$risposta['documentoId']}", token: $marioToken);
        self::assertSame('Condivisi con enti', $doc['categoria']['nome']);
        self::assertTrue($doc['cifrato']);
        self::assertSame('in_verifica', $this->request('GET', "/api/tenants/$fid/scadenze", token: $marioToken)[0]['stato']);

        // Coda della verifica affiancata: cosa controllare, senza il contenuto.
        $coda = $this->request('GET', "/api/enti/$tid/da-verificare", token: $token);
        $this->assertStatus(200);
        self::assertCount(1, $coda);
        self::assertSame(['Uscita al museo', 'tutti', 'Leo Rossi', 'application/pdf'], [$coda[0]['richiesta']['titolo'], $coda[0]['richiesta']['firmatari'], $coda[0]['destinatario']['iscritto'], $coda[0]['destinatario']['file']['mimeType']]);
        self::assertSame([['nome' => 'Mario Rossi', 'firmato' => true]], $coda[0]['destinatario']['referenti']);
        self::assertSame([], $this->request('GET', "/api/enti/$tid/da-verificare?richiestaId=".\Symfony\Component\Uid\Uuid::v7(), token: $token));

        // Un operatore di un altro gruppo non vede la richiesta.
        $altroGruppo = $this->request('POST', "/api/enti/$tid/gruppi", ['nome' => '3ªA'], $token);
        $piero = $this->createUser('piero@scuola.example');
        $mp = $this->addMember($piero, $this->ente($tid), 'staff');
        $this->request('PUT', "/api/enti/$tid/gruppi/{$altroGruppo['id']}/operatori", ['membershipIds' => [(string) $mp->getId()]], $token);
        $pieroToken = $this->login($piero);
        $this->request('GET', "/api/enti/$tid/richieste/{$richiesta['id']}", token: $pieroToken);
        $this->assertStatus(404);
        self::assertSame([], $this->request('GET', "/api/enti/$tid/da-verificare", token: $pieroToken), 'la coda segue la visibilità dei gruppi');
        // Il registro attività è della segreteria, non degli operatori.
        $this->request('GET', "/api/tenants/$tid/audit_logs?attivita=1", token: $pieroToken);
        $this->assertStatus(403);

        // L'ente scarica la copia, chiede di rifirmare, poi accetta.
        $dettaglio = $this->request('GET', "/api/enti/$tid/richieste/{$richiesta['id']}", token: $token);
        $destId = $dettaglio['avanzamento'][0]['destinatari'][0]['id'];
        $this->client->request('GET', "/api/enti/$tid/richieste/{$richiesta['id']}/destinatari/$destId/file", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame($pdf, $this->client->getResponse()->getContent());
        // Anteprima per la verifica affiancata: un PDF è l'anteprima di sé stesso.
        $this->client->request('GET', "/api/enti/$tid/richieste/{$richiesta['id']}/destinatari/$destId/anteprima", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame([$pdf, 'application/pdf'], [$this->client->getResponse()->getContent(), $this->client->getResponse()->headers->get('Content-Type')]);
        $this->request('POST', "/api/enti/$tid/richieste/{$richiesta['id']}/destinatari/$destId/verifica", ['esito' => 'rifirma'], $token);
        $this->assertStatus(422);
        $this->request('POST', "/api/enti/$tid/richieste/{$richiesta['id']}/destinatari/$destId/verifica", ['esito' => 'rifirma', 'motivo' => 'Manca la firma a pagina 1'], $token);
        $dopo = $this->request('GET', "/api/tenants/$fid/richieste/$did", token: $marioToken);
        self::assertSame(['da_rifirmare', 'Manca la firma a pagina 1'], [$dopo['stato'], $dopo['motivoEnte']]);
        self::assertSame('aperta', $this->request('GET', "/api/tenants/$fid/scadenze", token: $marioToken)[0]['stato']);

        // La seconda volta la famiglia manda un DOCX: l'ente lo vede convertito in PDF, l'originale resta DOCX.
        $docx = $this->fixture('contratto.docx');
        $this->multipart("/api/tenants/$fid/richieste/$did/risposta", ['firmatari' => [$leo['referenti'][0]['id']]], $docx, 'firmato2.docx', $marioToken);
        $this->assertStatus(200);
        $this->client->request('GET', "/api/enti/$tid/richieste/{$richiesta['id']}/destinatari/$destId/anteprima", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', "/api/enti/$tid/richieste/{$richiesta['id']}/destinatari/$destId/file", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame($docx, $this->client->getResponse()->getContent());
        $finale = $this->request('POST', "/api/enti/$tid/richieste/{$richiesta['id']}/destinatari/$destId/verifica", ['esito' => 'accetta'], $token);
        self::assertSame(1, $finale['totali']['completate']);
        self::assertSame('fatta', $this->request('GET', "/api/tenants/$fid/scadenze?stato=fatta", token: $marioToken)[0]['stato']);
        $this->request('POST', "/api/tenants/$fid/richieste/$did/risposta", ['firmatari' => [$leo['referenti'][0]['id']]], $marioToken);
        $this->assertStatus(409);

        // Registro attività dell'ente: le azioni, senza i controlli tecnici dei permessi.
        $registro = $this->request('GET', "/api/tenants/$tid/audit_logs?attivita=1&limit=200", token: $token);
        $this->assertStatus(200);
        $azioni = array_column($registro, 'action');
        self::assertContains('richiesta.inviata', $azioni);
        self::assertContains('richiesta.accettata', $azioni);
        self::assertNotContains('permission.check', $azioni);
        self::assertSame([], $this->request('GET', "/api/tenants/$tid/audit_logs?attivita=1&attore=nessuno-cosi", token: $token));

        $this->client->request('GET', "/api/enti/$tid/richieste/{$richiesta['id']}/zip", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $this->assertStatus(200);
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, (string) $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertSame(1, $zip->numFiles);
        // L'ultima consegna è il DOCX: nello ZIP c'è l'originale, con la sua estensione.
        self::assertSame('Leo Rossi - Uscita al museo.docx', $zip->getNameIndex(0));
        $zip->close();
        @unlink($path);
    }

    public function testPresaVisioneWithOneSignerClosesOtherFamilies(): void
    {
        [$tid, $token, $leo] = $this->enteConIscritto();
        [$fam1, $mario] = $this->famiglia('mario@example.com', 'Famiglia Rossi');
        [$fam2, $giulia] = $this->famiglia('giulia@example.com', 'Famiglia Conti');
        $this->collega($tid, $token, $leo['referenti'][0]['id'], $fam1, $mario);
        $this->collega($tid, $token, $leo['referenti'][1]['id'], $fam2, $giulia);

        $r = $this->multipart("/api/enti/$tid/richieste", ['tipo' => 'presa_visione', 'titolo' => 'Regolamento', 'iscrittoIds' => json_encode([$leo['id']]), 'firmatari' => 'uno'], null, null, $token);
        $this->assertStatus(201);
        self::assertNull($r['gruppo']);

        $giuliaToken = $this->login($giulia);
        $d2 = $this->request('GET', "/api/tenants/{$fam2->getId()}/richieste", token: $giuliaToken)[0];
        // Ogni famiglia vede solo il proprio referente.
        self::assertSame(['Giulia Rossi'], array_column($d2['referenti'], 'nome'));
        $ok = $this->request('POST', "/api/tenants/{$fam2->getId()}/richieste/{$d2['id']}/risposta", ['firmatari' => [$leo['referenti'][1]['id']]], $giuliaToken);
        self::assertSame('completata', $ok['stato']);

        $d1 = $this->request('GET', "/api/tenants/{$fam1->getId()}/richieste", token: $this->login($mario))[0];
        self::assertSame('annullata', $d1['stato']);
        self::assertSame(1, $this->request('GET', "/api/enti/$tid/richieste/{$r['id']}", token: $token)['totali']['completate']);
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return array{0: string, 1: string} id dell'ente verificato e token della segreteria */
    private function enteVerificato(): array
    {
        $token = $this->login($this->segretaria);
        $tid = $this->request('POST', '/api/enti', ['tipo' => 'scuola', 'denominazione' => 'Scuola Verdi', 'codiceFiscale' => self::CF, 'codiceMeccanografico' => 'RMEE000001'], $token)['tenantId'];
        $this->request('POST', "/api/enti/$tid/email/conferma", ['codice' => $this->codiceDaEmail()], $token);
        $this->request('POST', "/api/admin/enti/$tid/decisione", ['azione' => 'approva', 'controlli' => ['registro' => true]], $this->login($this->admin));
        $this->assertStatus(200);

        return [$tid, $token];
    }

    /** @return array{0: string, 1: string, 2: array<string, mixed>} ente, token, Leo (3ªB) con due referenti */
    private function enteConIscritto(): array
    {
        [$tid, $token] = $this->enteVerificato();
        $gruppo = $this->request('POST', "/api/enti/$tid/gruppi", ['nome' => '3ªB'], $token);
        $leo = $this->request('POST', "/api/enti/$tid/iscritti", ['nome' => 'Leo', 'cognome' => 'Rossi', 'gruppiIds' => [$gruppo['id']], 'referenti' => [
            ['nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario@example.com', 'relazione' => 'padre'],
            ['nome' => 'Giulia', 'cognome' => 'Rossi', 'email' => 'giulia@example.com', 'relazione' => 'madre'],
        ]], $token);
        $this->assertStatus(201);
        $this->request('POST', "/api/enti/$tid/iscritti", ['nome' => 'Omar', 'cognome' => 'Haddad', 'gruppiIds' => [$gruppo['id']]], $token);

        return [$tid, $token, $leo];
    }

    /** @return array{0: Tenant, 1: User} */
    private function famiglia(string $email, string $nome): array
    {
        $user = $this->createUser($email);
        $tenant = $this->createTenant($nome);
        $this->addMember($user, $tenant, Role::OWNER);

        return [$tenant, $user];
    }

    private function collega(string $tid, string $token, string $referenteId, Tenant $famiglia, User $user): void
    {
        $this->mailer()->reset();
        $this->request('POST', "/api/enti/$tid/inviti", ['referenteIds' => [$referenteId]], $token);
        $this->request('POST', '/api/collegamenti/accetta', ['token' => $this->tokenInviti()[0], 'tenantId' => (string) $famiglia->getId(), 'nuovaPersona' => ['nome' => 'Leo', 'cognome' => 'Rossi']], $this->login($this->fresh($user)));
        $this->assertStatus(201);
    }

    /** @return list<string> */
    private function tokenInviti(): array
    {
        $tokens = [];
        foreach ($this->mailer()->getEvents()->getMessages() as $message) {
            if ($message instanceof Email && preg_match('/token=([A-Za-z0-9_-]+)/', (string) $message->getHtmlBody(), $m)) {
                $tokens[] = $m[1];
            }
        }

        return $tokens;
    }

    private function codiceDaEmail(): string
    {
        preg_match('/>(\d{6})</', (string) $this->ultimaEmail()->getHtmlBody(), $m);
        self::assertNotEmpty($m, 'codice nell\'email');

        return $m[1];
    }

    private function ente(string $tid): Tenant
    {
        return $this->em()->find(Tenant::class, $tid);
    }

    private function userByEmail(string $email): User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    /** @return array<mixed>|null */
    private function multipart(string $uri, array $fields, ?string $contents, ?string $fileName, string $token): ?array
    {
        $files = [];
        if (null !== $contents) {
            $path = tempnam(sys_get_temp_dir(), 'fv-');
            file_put_contents($path, $contents);
            $files['file'] = new UploadedFile($path, (string) $fileName, null, null, true);
        }
        $this->client->request('POST', $uri, $fields, $files, ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'multipart/form-data']);
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? null : json_decode($content, true);
    }
}
