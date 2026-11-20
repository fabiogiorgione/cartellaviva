<?php

namespace App\Shared\Mail;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Le email dell'applicazione: mittente unico, corpo da un template Twig.
 * Regola di contenuto (vale per chi scrive i template): mai dati sensibili
 * nell'email, solo il fatto e il link per vederlo nell'app.
 */
final class Posta
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mittente,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    /**
     * Il template estende emails/_layout.html.twig, che riceve anche l'oggetto
     * (il titolo di base dell'email) e l'indirizzo dell'app per il piè di pagina.
     *
     * @param array<string, mixed> $contesto
     */
    public function invia(string $a, string $oggetto, string $template, array $contesto = []): void
    {
        $app = rtrim($this->frontendUrl, '/');
        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->mittente, 'CartellaViva'))
            ->to($a)
            ->subject($oggetto)
            ->htmlTemplate($template)
            ->context($contesto + [
                'oggetto' => $oggetto,
                'app_url' => $app,
                'app_host' => parse_url($app, \PHP_URL_HOST) ?: $app,
            ]));
    }
}
