<?php

namespace App\Account\Controller;

use App\Account\AccountService;
use App\Account\Entity\User;
use App\Account\Security\LoginResponder;
use App\Shared\Http\JsonBodyTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Credenziali dell'account.
 *
 * Pubblici (dai link delle email):
 *   POST /api/auth/password/dimenticata {email}          -> 202, sempre uguale
 *   POST /api/auth/password/anteprima   {token}          -> {email mascherata}
 *   POST /api/auth/password/reimposta   {token, password}
 *   POST /api/auth/attivazione/anteprima {token}         -> {email mascherata}
 *   POST /api/auth/attivazione          {token, password} (account creato dal portale)
 *   POST /api/auth/verifica-email       {token}
 *   POST /api/auth/cambio-email/conferma {token}
 *
 * Autenticati:
 *   POST /api/me/password  {attuale?, nuova}  -> nuovi token (le altre sessioni si chiudono)
 *   POST /api/me/email     {email, password}  -> 202, link al nuovo indirizzo
 *   POST /api/me/email/verifica                -> invia di nuovo il link di verifica
 */
final class AccountController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly AccountService $account,
        #[Autowire(service: 'limiter.public_auth')]
        private readonly RateLimiterFactoryInterface $publicAuthLimiter,
    ) {
    }

    #[Route('/api/auth/password/dimenticata', name: 'api_password_dimenticata', methods: ['POST'])]
    public function dimenticata(Request $request): JsonResponse
    {
        $this->limita($request);
        $this->account->richiediReset((string) ($this->body($request)['email'] ?? ''));

        return new JsonResponse(['messaggio' => 'Se l\'indirizzo corrisponde a un account, riceverai un\'email con il link per reimpostare la password. Il link vale un\'ora.'], Response::HTTP_ACCEPTED);
    }

    #[Route('/api/auth/password/anteprima', name: 'api_password_anteprima', methods: ['POST'])]
    public function anteprima(Request $request): JsonResponse
    {
        $this->limita($request);

        return $this->json(['email' => $this->account->anteprimaReset((string) ($this->body($request)['token'] ?? ''))]);
    }

    #[Route('/api/auth/password/reimposta', name: 'api_password_reimposta', methods: ['POST'])]
    public function reimposta(Request $request): JsonResponse
    {
        $this->limita($request);
        $body = $this->body($request);
        $user = $this->account->reimpostaPassword((string) ($body['token'] ?? ''), (string) ($body['password'] ?? ''));

        return $this->json([
            'messaggio' => $user->isTwoFactorEnabled()
                ? 'Password reimpostata. Accedi con la nuova password: ti verrà chiesto anche il codice della verifica in due passaggi.'
                : 'Password reimpostata. Ora puoi accedere con la nuova password.',
            'email' => $user->getEmail(),
        ]);
    }

    #[Route('/api/auth/attivazione/anteprima', name: 'api_attivazione_anteprima', methods: ['POST'])]
    public function anteprimaAttivazione(Request $request): JsonResponse
    {
        $this->limita($request);

        return $this->json(['email' => $this->account->anteprimaAttivazione((string) ($this->body($request)['token'] ?? ''))]);
    }

    #[Route('/api/auth/attivazione', name: 'api_attivazione', methods: ['POST'])]
    public function attiva(Request $request): JsonResponse
    {
        $this->limita($request);
        $body = $this->body($request);
        $user = $this->account->attiva((string) ($body['token'] ?? ''), (string) ($body['password'] ?? ''));

        return $this->json(['messaggio' => 'Account attivato. Ora puoi accedere con la tua password.', 'email' => $user->getEmail()]);
    }

    #[Route('/api/auth/verifica-email', name: 'api_verifica_email', methods: ['POST'])]
    public function verificaEmail(Request $request): JsonResponse
    {
        $this->limita($request);
        $this->account->verificaEmail((string) ($this->body($request)['token'] ?? ''));

        return $this->json(['messaggio' => 'Indirizzo email confermato. Grazie!']);
    }

    #[Route('/api/auth/cambio-email/conferma', name: 'api_cambio_email_conferma', methods: ['POST'])]
    public function confermaCambioEmail(Request $request): JsonResponse
    {
        $this->limita($request);
        $user = $this->account->confermaCambioEmail((string) ($this->body($request)['token'] ?? ''));

        return $this->json(['messaggio' => 'Indirizzo email aggiornato. Accedi di nuovo con il nuovo indirizzo.', 'email' => $user->getEmail()]);
    }

    #[Route('/api/me/password', name: 'api_me_password', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function cambiaPassword(#[CurrentUser] User $user, Request $request, LoginResponder $responder): Response
    {
        $body = $this->body($request);
        $this->account->cambiaPassword($user, isset($body['attuale']) && \is_string($body['attuale']) ? $body['attuale'] : null, (string) ($body['nuova'] ?? ''));

        // Questa sessione continua con token nuovi; tutte le altre sono chiuse.
        return $responder->issueTokens($user);
    }

    #[Route('/api/me/email', name: 'api_me_email', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function cambiaEmail(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $body = $this->body($request);
        $this->account->richiediCambioEmail($user, (string) ($body['email'] ?? ''), isset($body['password']) && \is_string($body['password']) ? $body['password'] : null);

        return new JsonResponse(['messaggio' => 'Ti abbiamo mandato un link al nuovo indirizzo: il cambio avviene quando lo apri (entro 24 ore).'], Response::HTTP_ACCEPTED);
    }

    #[Route('/api/me/email/verifica', name: 'api_me_email_verifica', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function inviaVerifica(#[CurrentUser] User $user): JsonResponse
    {
        $this->account->inviaVerifica($user);

        return new JsonResponse(['messaggio' => $user->isEmailVerificata() ? 'Il tuo indirizzo è già verificato.' : 'Ti abbiamo mandato un nuovo link: vale 24 ore.'], Response::HTTP_ACCEPTED);
    }

    private function limita(Request $request): void
    {
        if (!$this->publicAuthLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Troppi tentativi, riprova più tardi.');
        }
    }
}
