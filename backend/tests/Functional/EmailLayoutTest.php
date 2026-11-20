<?php

namespace App\Tests\Functional;

use App\Shared\Mail\Posta;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Tutte le email passano dal layout comune (templates/emails/_layout.html.twig):
 * fascia con il logo, titolo = oggetto, piè di pagina, logo allegato e non da
 * internet, versione testo senza CSS (docs/email.md).
 */
class EmailLayoutTest extends KernelTestCase
{
    /** Valori inventati che coprono le variabili di tutti i template. */
    private const CONTESTO = [
        'nome' => 'Giulia',
        'url' => 'http://localhost:5000/percorso?token=abc',
        'app' => 'http://localhost:5000/impostazioni',
        'acceptUrl' => 'http://localhost:5000/auth/invite?token=abc',
        'recupero' => 'http://localhost:5000/auth/forgot_password',
        'dove' => 'Famiglia Esempio',
        'messaggio' => 'la password del tuo account è stata cambiata.',
        'ente' => 'Scuola Esempio',
        'iscritto' => 'Luca',
        'quando' => '2026-11-25 10:00',
        'mac' => 'Mac di casa',
        'chi' => 'Mario Esempio',
        'famiglia' => 'Famiglia Esempio',
        'giorni' => 7,
        'motivo' => 'sollecito',
        'codice' => '123456',
        'stato' => 'integrazioni',
        'nota' => 'Manca il codice fiscale dell\'ente.',
        'da' => 'Mario Esempio',
        'data' => '2026-12-20',
        'caso' => 'attesa',
        'tipo' => 'recupero_2fa',
        'interessato' => 'Anna Esempio',
        'voci' => [['data' => '2026-12-01', 'famiglia' => 'Famiglia Esempio']],
        'totale' => 2,
        'funzionalita' => 1,
        'miglioramenti' => 1,
        'inviter' => 'Mario Esempio',
        'tenantName' => 'Famiglia Esempio',
        'roleName' => 'Membro',
        'expiresAt' => '2026-11-27 18:00',
        'scadenza' => '2026-12-31',
    ];

    /** @return iterable<string, array{string}> */
    public static function template(): iterable
    {
        foreach (glob(\dirname(__DIR__, 2).'/templates/emails/*.html.twig') ?: [] as $file) {
            $nome = basename($file);
            if (!str_starts_with($nome, '_')) {
                yield $nome => ['emails/'.$nome];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('template')]
    public function testOgniEmailHaIlLayoutComune(string $template): void
    {
        self::bootKernel();
        self::getContainer()->get(Posta::class)->invia('giulia@example.com', 'Oggetto di prova', $template, self::CONTESTO);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $html = (string) $email->getHtmlBody();

        self::assertStringContainsString('Cartella<span style="color: #CFE3DA;">Viva</span>', $html, 'la fascia con il nome');
        self::assertStringContainsString('<title>Oggetto di prova</title>', $html);
        self::assertStringContainsString('I documenti di famiglia, al sicuro e sempre aggiornati.', $html, 'il piè di pagina');
        self::assertStringContainsString('Nessuno di CartellaViva ti chiederà mai la password o i codici.', $html);
        self::assertStringContainsString('>localhost</a>', $html, 'il link all\'app');
        self::assertStringNotContainsString('<html lang="it">'."\n".'<body', $html, 'nessun vecchio template a sé');

        // Il logo è allegato all'email (cid:), nessuna immagine o carattere da internet.
        self::assertSame(2, substr_count($html, 'src="cid:'));
        self::assertDoesNotMatchRegularExpression('#src="https?://|url\(|fonts\.googleapis#', $html);
        $immagini = array_filter($email->getAttachments(), static fn (DataPart $p) => 'image' === $p->getMediaType());
        self::assertCount(2, $immagini);

        // La versione testo (generata dall'HTML) non contiene il CSS del tema scuro.
        $testo = (string) $email->getTextBody();
        self::assertStringContainsString('I documenti di famiglia', $testo);
        self::assertStringNotContainsString('prefers-color-scheme', $testo);
    }

    public function testIlTitoloDiBaseEOggettoEUnCodiceRestaLeggibile(): void
    {
        self::bootKernel();
        self::getContainer()->get(Posta::class)->invia('giulia@example.com', 'Il tuo codice per firmare su CartellaViva', 'emails/firma_codice.html.twig', self::CONTESTO);

        $html = (string) self::getMailerMessage()?->getHtmlBody();
        self::assertMatchesRegularExpression('#<h1[^>]*>Il tuo codice per firmare su CartellaViva</h1>#', $html);
        self::assertMatchesRegularExpression('/>(\d{6})</', $html, 'il codice da solo nel suo tag');
        self::assertStringContainsString('perché stai firmando un documento di Scuola Esempio', $html);
    }
}
