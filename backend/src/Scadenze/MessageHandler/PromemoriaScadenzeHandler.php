<?php

namespace App\Scadenze\MessageHandler;

use App\Account\Entity\User;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Message\PromemoriaScadenzeMessage;
use App\Scadenze\Repository\ScadenzaRepository;
use App\Shared\Mail\Posta;
use App\Tenancy\Security\PermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Promemoria delle scadenze, una volta al giorno (src/Schedule.php).
 *
 * - destinatari: chi ha creato la scadenza e, se la scadenza riguarda una
 *   persona con un account, anche lei; sempre e solo se hanno ancora
 *   scadenza:read nel tenant;
 * - una sola email per utente, con quante scadenze e quando, SENZA i titoli
 *   (possono essere dati sensibili): i dettagli si leggono nell'app;
 * - ogni scadenza si avvisa una volta sola (promemoriaInviatoIl).
 */
#[AsMessageHandler]
final class PromemoriaScadenzeHandler
{
    public function __construct(
        private readonly ScadenzaRepository $scadenze,
        private readonly PermissionResolver $permissions,
        private readonly EntityManagerInterface $em,
        private readonly Posta $posta,
        private readonly \App\Account\Push\PushNotifier $push,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    /** @return array{scadenze: int, email: int} */
    public function __invoke(PromemoriaScadenzeMessage $message): array
    {
        $oggi = new \DateTimeImmutable('today');
        $dovute = $this->scadenze->findPromemoriaDovuti($oggi);

        /** @var array<string, array{user: User, voci: list<Scadenza>}> $perUtente */
        $perUtente = [];
        foreach ($dovute as $scadenza) {
            foreach ($this->destinatari($scadenza) as $user) {
                $key = $user->getId()->toRfc4122();
                $perUtente[$key] ??= ['user' => $user, 'voci' => []];
                $perUtente[$key]['voci'][] = $scadenza;
            }
            $scadenza->markPromemoriaInviato();
        }

        foreach ($perUtente as ['user' => $user, 'voci' => $voci]) {
            usort($voci, static fn (Scadenza $a, Scadenza $b) => $a->getData() <=> $b->getData());
            $oggetto = 1 === \count($voci) ? 'Promemoria: hai una scadenza in arrivo' : sprintf('Promemoria: hai %d scadenze in arrivo', \count($voci));
            $this->push->notifica($user, 'CartellaViva', $oggetto, '/scadenze');
            $this->posta->invia($user->getEmail(), $oggetto, 'emails/promemoria_scadenze.html.twig', [
                    'nome' => $user->getFirstName(),
                    'voci' => array_map(static fn (Scadenza $s) => ['data' => $s->getData(), 'famiglia' => $s->getTenant()->getName()], $voci),
                    'url' => rtrim($this->frontendUrl, '/').'/scadenze',
                ]);
        }
        $this->em->flush();

        $result = ['scadenze' => \count($dovute), 'email' => \count($perUtente)];
        $this->logger->info('Promemoria scadenze: {result}', ['result' => json_encode($result)]);

        return $result;
    }

    /** @return list<User> */
    private function destinatari(Scadenza $scadenza): array
    {
        $candidati = [$scadenza->getOwner()];
        $persona = $scadenza->getMembro()?->getUser();
        if (null !== $persona && !$persona->getId()->equals($scadenza->getOwner()->getId())) {
            $candidati[] = $persona;
        }

        return array_values(array_filter(
            $candidati,
            fn (User $u) => \in_array('scadenza:read', $this->permissions->getPermissionCodes($u, $scadenza->getTenant()), true),
        ));
    }
}
