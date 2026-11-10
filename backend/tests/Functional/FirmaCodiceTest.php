<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;

/**
 * Firma con codice (FES): ogni referente firma per sé con un codice alla sua
 * email; quando firmano tutti si compone il PDF (originale + attestazione) e
 * la consegna è completata senza verifica manuale.
 */
final class FirmaCodiceTest extends ApiTestCase
{
    private const CF = '80012345676';

    public function testBothParentsSignWithCodeAndAttestationIsVerifiable(): void
    {
        [$tid, $token, $leo] = $this->enteConLeo([['Mario', 'mario@example.com'], ['Giulia', 'giulia@example.com']]);
        $famiglia = $this->createTenant('Famiglia Rossi');
        $mario = $this->collega($tid, $token, $leo['referenti'][0]['id'], 'mario@example.com', $famiglia, Role::OWNER);
        $giulia = $this->collega($tid, $token, $leo['referenti'][1]['id'], 'giulia@example.com', $famiglia, Role::MEMBER);
        $nonno = $this->createUser('nonno@example.com');
        $this->addMember($nonno, $famiglia, Role::MEMBER);

        $pdf = $this->fixture('ocr_fattura_scansione.pdf');
        $r = $this->multipart("/api/enti/$tid/richieste", ['tipo' => 'firma', 'titolo' => 'Uscita al museo', 'iscrittoIds' => json_encode([$leo['id']]), 'firmatari' => 'tutti', 'modalitaFirma' => 'codice', 'entroIl' => (new \DateTimeImmutable('+5 days'))->format('Y-m-d')], $pdf, 'museo.pdf', $token);
        $this->assertStatus(201);
        self::assertSame('codice', $r['modalitaFirma']);
        $fid = (string) $famiglia->getId();

        // Chi non è tra i firmatari non può firmare.
        $marioToken = $this->login($mario);
        $d = $this->request('GET', "/api/tenants/$fid/richieste", token: $marioToken)[0];
        $this->request('POST', "/api/tenants/$fid/richieste/{$d['id']}/firma/codice", [], $this->login($nonno));
        $this->assertStatus(409);

        // Mario: codice alla sua email, firma.
        $this->mailer()->reset();
        $avvio = $this->request('POST', "/api/tenants/$fid/richieste/{$d['id']}/firma/codice", [], $marioToken);
        self::assertSame(hash('sha256', $pdf), $avvio['documentoSha256']);
        $email = $this->ultimaEmail();
        self::assertSame('mario@example.com', $email->getTo()[0]->getAddress());
        self::assertStringNotContainsString('museo', (string) $email->getHtmlBody());
        $codice = $this->codice($email);
        $this->request('POST', "/api/tenants/$fid/richieste/{$d['id']}/firma", ['codice' => $codice, 'accetto' => false], $marioToken);
        $this->assertStatus(422);
        $this->request('POST', "/api/tenants/$fid/richieste/{$d['id']}/firma", ['codice' => '000000' === $codice ? '111111' : '000000', 'accetto' => true], $marioToken);
        $this->assertStatus(422);
        $dopoMario = $this->request('POST', "/api/tenants/$fid/richieste/{$d['id']}/firma", ['codice' => $codice, 'accetto' => true], $marioToken);
        $this->assertStatus(200);
        self::assertSame('vista', $dopoMario['stato'], 'manca ancora Giulia');
        self::assertSame([true, false], array_column($dopoMario['referenti'], 'firmato'));
        $this->request('POST', "/api/tenants/$fid/richieste/{$d['id']}/firma/codice", [], $marioToken);
        $this->assertStatus(409);

        // L'ente vede la firma parziale.
        $avanz = $this->request('GET', "/api/enti/$tid/richieste/{$r['id']}", token: $token)['avanzamento'][0]['destinatari'][0];
        self::assertNotNull($avanz['referenti'][0]['firmatoConCodiceIl']);

        // Giulia firma: consegna completata senza verifica manuale.
        $giuliaToken = $this->login($giulia);
        $this->mailer()->reset();
        $this->request('POST', "/api/tenants/$fid/richieste/{$d['id']}/firma/codice", [], $giuliaToken);
        $finale = $this->request('POST', "/api/tenants/$fid/richieste/{$d['id']}/firma", ['codice' => $this->codice($this->ultimaEmail()), 'accetto' => true], $giuliaToken);
        $this->assertStatus(200);
        self::assertSame('completata', $finale['stato']);
        self::assertSame(1, $this->request('GET', "/api/enti/$tid/richieste/{$r['id']}", token: $token)['totali']['completate']);
        self::assertSame('fatta', $this->request('GET', "/api/tenants/$fid/scadenze?stato=fatta", token: $giuliaToken)[0]['stato']);

        // PDF firmato = originale + attestazione con entrambi i firmatari.
        $dest = $this->request('GET', "/api/enti/$tid/richieste/{$r['id']}", token: $token)['avanzamento'][0]['destinatari'][0];
        $this->client->request('GET', "/api/enti/$tid/richieste/{$r['id']}/destinatari/{$dest['id']}/file", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $firmato = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF', $firmato);
        $testo = $this->testoPdf($firmato);
        self::assertStringContainsString('Attestazione di firma elettronica', $testo);
        self::assertStringContainsString('Mario Rossi', $testo);
        self::assertStringContainsString('Giulia Rossi', $testo);
        self::assertStringContainsString(hash('sha256', $pdf), str_replace(["\n", ' '], '', $testo));
        self::assertSame($this->pagine($pdf) + 1, $this->pagine($firmato), 'originale invariato + una pagina di attestazione');

        // Verifica pubblica con il codice stampato.
        preg_match('/Codice di verifica\s+([A-Z0-9]{12})/', $testo, $m);
        self::assertNotEmpty($m);
        $this->client->request('GET', '/api/verifica-firma/'.$m[1], server: ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatus(200);
        $verifica = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(hash('sha256', $firmato), $verifica['documentoFirmatoSha256']);
        self::assertSame(hash('sha256', $pdf), $verifica['documentoSha256']);

        // Copia della famiglia in "Condivisi con enti".
        $doc = $this->request('GET', "/api/tenants/$fid/richieste/{$d['id']}", token: $giuliaToken)['documentoId'];
        self::assertSame('Condivisi con enti', $this->request('GET', "/api/tenants/$fid/documenti/$doc", token: $giuliaToken)['categoria']['nome']);
    }

    public function testNoAccountReferenteSignsWithCodeFromLink(): void
    {
        [$tid, $token, $leo] = $this->enteConLeo([['Elena', 'elena@example.com']]);
        $this->mailer()->reset();
        $this->multipart("/api/enti/$tid/richieste", ['tipo' => 'firma', 'titolo' => 'Delega', 'iscrittoIds' => json_encode([$leo['id']]), 'firmatari' => 'uno', 'modalitaFirma' => 'codice'], $this->fixture('ocr_fattura_scansione.pdf'), 'delega.pdf', $token);
        $this->assertStatus(201);
        preg_match('#risposta\?token=([A-Za-z0-9_-]+)#', (string) $this->ultimaEmail()->getHtmlBody(), $m);
        $link = $m[1];
        $this->public('POST', "/api/link/$link/codice");
        $sessione = $this->public('POST', "/api/link/$link/verifica", ['codice' => $this->codice($this->ultimaEmail())])['sessione'];
        self::assertSame('codice', $this->public('GET', "/api/link/$link/richiesta", null, $sessione)['modalitaFirma']);

        $this->public('POST', "/api/link/$link/firma/codice", null, $sessione);
        $this->assertStatus(200);
        $esito = $this->public('POST', "/api/link/$link/firma", ['codice' => $this->codice($this->ultimaEmail()), 'accetto' => true], $sessione);
        $this->assertStatus(200);
        self::assertSame('completata', $esito['stato']);
    }

    public function testCodeSignatureNeedsAPdf(): void
    {
        [$tid, $token, $leo] = $this->enteConLeo([['Elena', 'elena@example.com']]);
        $this->multipart("/api/enti/$tid/richieste", ['tipo' => 'firma', 'titolo' => 'Delega', 'iscrittoIds' => json_encode([$leo['id']]), 'firmatari' => 'uno', 'modalitaFirma' => 'codice'], $this->fixture('ocr_fattura.png'), 'delega.png', $token);
        $this->assertStatus(422);
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * @param list<array{0: string, 1: string}> $referenti nome ed email
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function enteConLeo(array $referenti): array
    {
        $segretaria = $this->createUser('segreteria@scuola.example');
        $admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);
        $token = $this->login($segretaria);
        $tid = $this->request('POST', '/api/enti', ['tipo' => 'scuola', 'denominazione' => 'Scuola Verdi', 'codiceFiscale' => self::CF, 'codiceMeccanografico' => 'RMEE000001'], $token)['tenantId'];
        $this->request('POST', "/api/enti/$tid/email/conferma", ['codice' => $this->codice($this->ultimaEmail())], $token);
        $this->request('POST', "/api/admin/enti/$tid/decisione", ['azione' => 'approva', 'controlli' => ['registro' => true]], $this->login($admin));
        $gruppo = $this->request('POST', "/api/enti/$tid/gruppi", ['nome' => '3ªB'], $token);
        $leo = $this->request('POST', "/api/enti/$tid/iscritti", ['nome' => 'Leo', 'cognome' => 'Rossi', 'gruppiIds' => [$gruppo['id']],
            'referenti' => array_map(static fn ($r) => ['nome' => $r[0], 'cognome' => 'Rossi', 'email' => $r[1]], $referenti)], $token);

        return [$tid, $token, $leo];
    }

    private function collega(string $tid, string $token, string $referenteId, string $email, Tenant $famiglia, string $ruolo): User
    {
        $user = $this->createUser($email);
        $this->addMember($user, $famiglia, $ruolo);
        $this->mailer()->reset();
        $this->request('POST', "/api/enti/$tid/inviti", ['referenteIds' => [$referenteId]], $token);
        preg_match('#collegamenti/invito\?token=([A-Za-z0-9_-]+)#', (string) $this->ultimaEmail()->getHtmlBody(), $m);
        $this->request('POST', '/api/collegamenti/accetta', ['token' => $m[1], 'tenantId' => (string) $famiglia->getId(), 'nuovaPersona' => ['nome' => 'Leo', 'cognome' => 'Rossi']], $this->login($this->fresh($user)));
        $this->assertStatus(201);

        return $user;
    }

    private function testoPdf(string $pdf): string
    {
        $p = new Process(['pdftotext', '-', '-']);
        $p->setInput($pdf);
        $p->mustRun();

        return $p->getOutput();
    }

    private function pagine(string $pdf): int
    {
        $p = new Process(['pdfinfo', '-']);
        $p->setInput($pdf);
        $p->mustRun();
        preg_match('/Pages:\s+(\d+)/', $p->getOutput(), $m);

        return (int) $m[1];
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

    private function codice(Email $email): string
    {
        preg_match('/>(\d{6})</', (string) $email->getHtmlBody(), $m);

        return $m[1];
    }
}
