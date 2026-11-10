<?php

namespace App\Import;

use App\Account\Entity\User;
use App\Import\Entity\ImportSource;
use App\Import\Enum\ImportProvider;
use App\Import\Enum\ImportSourceStato;
use App\Import\Enum\ImportStrategia;
use App\Import\Exception\ImportException;
use App\Import\Message\ImportJobMessage;
use App\Import\Provider\ImportProviderRegistry;
use App\Import\Repository\ImportSourceRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\PermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ciclo di vita delle sorgenti di import. I PERMESSI delle richieste API li
 * verifica il chiamante tramite il voter (import:manage sul tenant); qui si
 * riverifica il permesso solo dove non c'è una richiesta autenticata (callback
 * OAuth e job asincrono).
 */
final class ImportSourceService
{
    public const PERMISSION = 'import:manage';
    public const RITORNO_WEB = 'web';
    public const RITORNO_APP = 'app';
    public const RITORNI = [self::RITORNO_WEB, self::RITORNO_APP];
    private const STATE_TTL = '+10 minutes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportSourceRepository $sources,
        private readonly ImportProviderRegistry $providers,
        private readonly ImportTokenVault $vault,
        private readonly PermissionResolver $permissions,
        private readonly MessageBusInterface $bus,
        private readonly AuditLogger $audit,
    ) {
    }

    public function create(Tenant $tenant, User $user, ImportProvider $provider, string $cartellaRadiceId, ImportStrategia $strategia): ImportSource
    {
        // Prima di tutto: senza credenziali OAuth la sorgente non servirebbe a nulla.
        $folderId = $this->providers->getConfigured($provider)->folderIdFromInput($cartellaRadiceId)
            ?? throw new DatiNonValidi('cartellaRadiceId: incolla il link della cartella di Google Drive (o il suo id).');

        $source = new ImportSource($tenant, $user, $provider, $folderId, $strategia);
        $this->em->persist($source);
        $this->em->flush();
        $this->log('import.source_created', $source, context: ['strategia' => $strategia->value]);

        return $source;
    }

    /**
     * Avvia il collegamento OAuth: restituisce l'URL del provider da aprire nel browser.
     * $ritorno sceglie dove rimandare l'utente alla fine (web o app), tra destinazioni
     * fisse della configurazione: il client non può indicare un URL.
     */
    public function startConnect(ImportSource $source, User $user, string $ritorno = self::RITORNO_WEB): string
    {
        if (!\in_array($ritorno, self::RITORNI, true)) {
            throw new DatiNonValidi('ritorno: valori ammessi '.implode(', ', self::RITORNI).'.');
        }
        $provider = $this->providers->getConfigured($source->getProvider());
        $state = bin2hex(random_bytes(32));
        $source->beginOAuth(hash('sha256', $state), $user, new \DateTimeImmutable(self::STATE_TTL), $ritorno);
        $this->em->flush();
        $this->log('import.connect_started', $source);

        return $provider->authorizationUrl($state);
    }

    /** Destinazione scelta allo start (da leggere PRIMA di completeConnect, che consuma lo state). */
    public function ritornoForState(string $state): string
    {
        $source = '' !== $state ? $this->sources->findByOAuthStateHash(hash('sha256', $state)) : null;
        $ritorno = $source?->getOauthRitorno();

        return \in_array($ritorno, self::RITORNI, true) ? $ritorno : self::RITORNO_WEB;
    }

    /**
     * Scollega tutte le sorgenti del tenant revocando l'accesso presso il provider
     * (es. famiglia in cancellazione). La revoca è best effort; i token locali si
     * cancellano comunque.
     */
    public function disconnectAll(Tenant $tenant, string $motivo): int
    {
        $count = 0;
        foreach ($this->sources->findBy(['tenant' => $tenant]) as $source) {
            if ($source->isCollegata()) {
                try {
                    $this->providers->get($source->getProvider())->revoke($this->vault->refreshToken($source));
                } catch (\Throwable) {
                    // token illeggibile o provider non configurato: si cancella comunque
                }
                ++$count;
            }
            $source->disconnect($motivo);
            $this->log('import.disconnected', $source, context: ['motivo' => $motivo]);
        }
        $this->em->flush();

        return $count;
    }

    /**
     * Ritorno dal provider (richiesta NON autenticata): l'unica prova è lo state
     * monouso, che identifica sorgente e utente e scade dopo 10 minuti.
     *
     * @throws ImportException con un codice breve per il frontend: non_valido | scaduto | annullato | errore
     */
    public function completeConnect(string $state, ?string $code, ?string $providerError): ImportSource
    {
        $source = '' !== $state ? $this->sources->findByOAuthStateHash(hash('sha256', $state)) : null;
        if (null === $source) {
            throw new ImportException('non_valido');
        }
        $user = $source->consumeOAuthState();
        $this->em->flush(); // monouso anche se i passi successivi falliscono

        if (null === $user) {
            $this->log('import.connect_failed', $source, AuditOutcome::FAILURE, ['motivo' => 'scaduto']);
            throw new ImportException('scaduto');
        }
        if (null !== $providerError || null === $code || '' === $code) {
            $this->log('import.connect_failed', $source, AuditOutcome::FAILURE, ['motivo' => 'annullato'], $user);
            throw new ImportException('annullato');
        }
        if (!$this->canManage($user, $source)) {
            $this->log('import.connect_failed', $source, AuditOutcome::DENIED, ['motivo' => 'permesso revocato'], $user);
            throw new ImportException('non_valido');
        }

        try {
            $tokens = $this->providers->getConfigured($source->getProvider())->exchangeCode($code);
            $this->vault->store($source, $tokens);
        } catch (ImportException $e) {
            $source->failSync($e->getMessage());
            $this->em->flush();
            $this->log('import.connect_failed', $source, AuditOutcome::FAILURE, ['motivo' => $e->getMessage()], $user);
            throw new ImportException('errore', 0, $e);
        }
        $source->markConnected($tokens->account);
        $this->em->flush();
        $this->log('import.connected', $source, actor: $user);

        return $source;
    }

    /** Mette in coda una sincronizzazione manuale. */
    public function requestSync(ImportSource $source): void
    {
        if (!$source->isCollegata() || \in_array($source->getStato(), [ImportSourceStato::NON_CONFIGURATO, ImportSourceStato::DISATTIVATO], true)) {
            throw new Conflitto('La sorgente non è collegata o è disattivata: collegala prima di sincronizzare.');
        }
        if ($source->isSincronizzazioneInCorso()) {
            throw new Conflitto('Una sincronizzazione di questa sorgente è già in corso.');
        }
        $source->requestSync();
        $this->em->flush();
        $this->bus->dispatch(new ImportJobMessage((string) $source->getId()));
        $this->log('import.sync_requested', $source);
    }

    /** I documenti già importati restano (perdono solo il collegamento alla sorgente). */
    public function delete(ImportSource $source): void
    {
        $this->log('import.source_deleted', $source);
        $this->em->remove($source);
        $this->em->flush();
    }

    /** Il permesso vale ancora per l'utente (ruolo cambiato, membro rimosso...)? */
    public function canManage(User $user, ImportSource $source): bool
    {
        return \in_array(self::PERMISSION, $this->permissions->getPermissionCodes($user, $source->getTenant()), true);
    }

    /** @param array<string, mixed> $context */
    public function log(string $action, ImportSource $source, AuditOutcome $outcome = AuditOutcome::SUCCESS, array $context = [], ?User $actor = null): void
    {
        $this->audit->log($action, $outcome, resourceType: 'import_source', resourceId: (string) $source->getId(),
            tenantId: $source->getTenant()->getId(), context: $context + ['provider' => $source->getProvider()->value], actor: $actor);
    }
}
