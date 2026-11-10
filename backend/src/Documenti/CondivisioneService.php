<?php

namespace App\Documenti;

use App\Account\Entity\User;
use App\Documenti\Entity\CondivisioneEsterna;
use App\Documenti\Entity\Documento;
use App\Documenti\Repository\DocumentoRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonConsentito;
use App\Shared\Errore\NonPiuValido;
use App\Shared\Errore\NonTrovato;
use App\Shared\Mail\Posta;
use App\Shared\TokenCasuale;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Condivisione esterna temporanea: link + codice, scadenza, limite di
 * download, revoca. Il file si decifra solo in memoria al momento del
 * download; ogni accesso e ogni download finiscono nell'audit della famiglia.
 */
final class CondivisioneService
{
    public const MAX_DOCUMENTI = 20;
    public const MAX_ORE = 720; // 30 giorni
    public const MAX_DOWNLOAD = 50;
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentoRepository $documenti,
        private readonly DocumentService $documents,
        private readonly TenantAccess $access,
        private readonly AuditLogger $audit,
        private readonly Posta $posta,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    /**
     * @param list<string> $documentiIds
     *
     * @return array{condivisione: CondivisioneEsterna, url: string, codice: string}
     */
    public function crea(Tenant $tenant, array $documentiIds, int $ore, int $maxDownload, ?string $nota): array
    {
        $user = $this->access->currentUser();
        $documentiIds = array_values(array_unique(array_filter($documentiIds, 'is_string')));
        if ([] === $documentiIds || \count($documentiIds) > self::MAX_DOCUMENTI) {
            throw new DatiNonValidi(sprintf('documentiIds: da 1 a %d documenti.', self::MAX_DOCUMENTI));
        }
        if ($ore < 1 || $ore > self::MAX_ORE) {
            throw new DatiNonValidi('ore: da 1 ora a 30 giorni.');
        }
        if ($maxDownload < 1 || $maxDownload > self::MAX_DOWNLOAD) {
            throw new DatiNonValidi(sprintf('maxDownload: da 1 a %d.', self::MAX_DOWNLOAD));
        }
        $documenti = [];
        foreach ($documentiIds as $id) {
            $d = $this->documenti->findOneInTenant($tenant, $id) ?? throw new DatiNonValidi('documentiIds: documento non trovato in questa famiglia.');
            // Si condivide solo ciò che si può leggere.
            $this->access->require('documento:read', $d);
            $documenti[] = $d;
        }

        $token = TokenCasuale::nuovo();
        $codice = '';
        for ($i = 0; $i < 8; ++$i) {
            $codice .= self::ALFABETO[random_int(0, \strlen(self::ALFABETO) - 1)];
        }
        $c = new CondivisioneEsterna($tenant, $user, $documenti, hash('sha256', $token), password_hash($codice, \PASSWORD_DEFAULT),
            new \DateTimeImmutable(sprintf('+%d hours', $ore)), $maxDownload, null !== $nota && '' !== trim($nota) ? mb_substr(trim($nota), 0, 120) : null);
        $this->access->require('condivisione:manage', $c);
        $this->em->persist($c);
        $this->em->flush();
        $this->log('condivisione.creata', $c, ['documenti' => \count($documenti), 'ore' => $ore, 'maxDownload' => $maxDownload], $user);

        return [
            'condivisione' => $c,
            'url' => rtrim($this->frontendUrl, '/').'/condivisione?token='.$token,
            'codice' => substr($codice, 0, 4).'-'.substr($codice, 4),
        ];
    }

    /** @return array<string, mixed> anteprima pubblica: nessun titolo prima del codice */
    public function anteprima(string $token): array
    {
        $c = $this->daToken($token);

        return [
            'stato' => $c->getStato(),
            'scadeIl' => $c->getScadeIl()->format(\DATE_ATOM),
            'documenti' => \count($c->getDocumenti()),
            'condivisoDa' => $c->getOwner()->getFirstName(),
        ];
    }

    /** @return array{sessione: string, condivisione: CondivisioneEsterna} */
    public function accedi(string $token, string $codice): array
    {
        $c = $this->daToken($token);
        $this->richiediAttiva($c);
        $codice = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $codice));
        if (!password_verify($codice, $c->getCodiceHash())) {
            $c->codiceErrato();
            $this->em->flush();
            $this->log('condivisione.codice_errato', $c, ['bloccata' => 'bloccata' === $c->getStato()], outcome: AuditOutcome::FAILURE);
            throw new NonConsentito('bloccata' === $c->getStato()
                ? 'Troppi codici errati: la condivisione è bloccata. Chiedi a chi te l\'ha mandata di crearne una nuova.'
                : 'Codice non corretto.');
        }
        $primo = null === $c->getPrimoAccessoIl();
        $sessione = TokenCasuale::nuovo();
        $c->apriSessione(hash('sha256', $sessione));
        $this->em->flush();
        $this->log('condivisione.aperta', $c);
        if ($primo && $c->getOwner()->isActive()) {
            // Solo il fatto, senza titoli né nota: chi condivide sa a chi l'ha mandata.
            $this->posta->invia($c->getOwner()->getEmail(), 'Una tua condivisione è stata aperta', 'emails/condivisione_aperta.html.twig', ['nome' => $c->getOwner()->getFirstName(), 'url' => rtrim($this->frontendUrl, '/').'/condivisioni']);
        }

        return ['sessione' => $sessione, 'condivisione' => $c];
    }

    /** @return array{documento: Documento, contenuto: string} */
    public function scarica(string $token, string $sessione, string $documentoId): array
    {
        $c = $this->daToken($token);
        $this->richiediAttiva($c);
        if (!$c->isSessioneValida(hash('sha256', $sessione))) {
            throw new NonConsentito('Sessione scaduta: inserisci di nuovo il codice.');
        }
        $documento = null;
        foreach ($c->getDocumenti() as $d) {
            if ((string) $d->getId() === $documentoId) {
                $documento = $d;
            }
        }
        if (null === $documento) {
            throw new NonTrovato('Documento non trovato.');
        }
        $contenuto = $this->documents->readContent($documento);
        $c->contaDownload();
        $this->em->flush();
        // Sempre nell'audit, anche per i documenti non cifrati: è un'uscita dall'archivio.
        $this->log('documento.download_esterno', $c, ['documento' => (string) $documento->getId(), 'download' => $c->getDownload()]);

        return ['documento' => $documento, 'contenuto' => $contenuto];
    }

    public function revoca(CondivisioneEsterna $c): void
    {
        $this->access->require('condivisione:manage', $c);
        $c->revoca();
        $this->em->flush();
        $this->log('condivisione.revocata', $c);
    }

    /** @return list<CondivisioneEsterna> le proprie, o tutte con condivisione:manage:all */
    public function elenco(Tenant $tenant): array
    {
        $tutte = $this->em->getRepository(CondivisioneEsterna::class)->findBy(['tenant' => $tenant], ['createdAt' => 'DESC'], 200);

        return array_values(array_filter($tutte, fn (CondivisioneEsterna $c) => $this->access->isGranted('condivisione:manage', $c)));
    }

    /** Uscita di un membro: i suoi link smettono di funzionare. */
    public static function revocaDi(EntityManagerInterface $em, Tenant $tenant, User $user): int
    {
        return (int) $em->createQuery('UPDATE '.CondivisioneEsterna::class.' c SET c.revocataIl = :ora, c.sessioneHash = NULL WHERE c.tenant = :t AND c.owner = :u AND c.revocataIl IS NULL')
            ->setParameter('ora', new \DateTimeImmutable())->setParameter('t', $tenant->getId(), 'uuid')->setParameter('u', $user->getId(), 'uuid')
            ->execute();
    }

    private function daToken(string $token): CondivisioneEsterna
    {
        $c = '' === trim($token) ? null : $this->em->getRepository(CondivisioneEsterna::class)->findOneBy(['tokenHash' => hash('sha256', trim($token))]);

        return $c instanceof CondivisioneEsterna ? $c : throw new NonTrovato('Condivisione non trovata.');
    }

    private function richiediAttiva(CondivisioneEsterna $c): void
    {
        $stato = $c->getStato();
        if ('attiva' !== $stato) {
            throw new NonPiuValido(match ($stato) {
                'scaduta' => 'La condivisione è scaduta.',
                'esaurita' => 'Raggiunto il numero massimo di download.',
                'bloccata' => 'Troppi codici errati: la condivisione è bloccata.',
                default => 'La condivisione è stata revocata.',
            });
        }
    }

    /** @param array<string, mixed> $context */
    private function log(string $action, CondivisioneEsterna $c, array $context = [], ?User $actor = null, AuditOutcome $outcome = AuditOutcome::SUCCESS): void
    {
        $this->audit->log($action, $outcome, resourceType: 'condivisione', resourceId: (string) $c->getId(), tenantId: $c->getTenant()->getId(), context: $context, actor: $actor);
    }
}
