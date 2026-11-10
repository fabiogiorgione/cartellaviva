<?php

namespace App\Shared\MessageHandler;

use App\Account\Entity\TokenAccount;
use App\Account\Repository\TwoFactorChallengeRepository;
use App\Documenti\Entity\CondivisioneEsterna;
use App\Import\Entity\ImportSource;
use App\Portale\ProcedureService;
use App\Shared\Message\PuliziaPeriodicaMessage;
use App\Tenancy\Entity\TenantExport;
use App\Tenancy\TenantDeletion;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Elimina dati temporanei che non servono più:
 * - challenge della verifica in due passaggi scadute;
 * - "state" OAuth delle sorgenti di import non usati entro la scadenza;
 * - link di esportazione scaduti da una settimana;
 * - link dell'account (reset password, verifica e cambio email) scaduti da una settimana;
 * - famiglie arrivate alla data di eliminazione definitiva;
 * - procedure d'accesso (recupero 2FA, nuovo proprietario) a fine attesa.
 */
#[AsMessageHandler]
final class PuliziaPeriodicaHandler
{
    public function __construct(
        private readonly TwoFactorChallengeRepository $challenges,
        private readonly TenantDeletion $tenantDeletion,
        private readonly ProcedureService $procedure,
        private readonly \App\Tenancy\EmergenzaService $emergenza,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, int> */
    public function __invoke(PuliziaPeriodicaMessage $message): array
    {
        $result = [
            'challenge_2fa' => $this->challenges->deleteExpired(),
            'stati_oauth' => (int) $this->em->createQueryBuilder()
                ->update(ImportSource::class, 's')
                ->set('s.oauthStateHash', 'NULL')
                ->set('s.oauthStateUser', 'NULL')
                ->set('s.oauthStateScadenza', 'NULL')
                ->where('s.oauthStateScadenza < :now')
                ->setParameter('now', new \DateTimeImmutable())
                ->getQuery()
                ->execute(),
            'esportazioni' => (int) $this->em->createQuery('DELETE FROM '.TenantExport::class.' e WHERE e.scadeIl < :limite')
                ->setParameter('limite', new \DateTimeImmutable('-7 days'))
                ->execute(),
            'link_account' => (int) $this->em->createQuery('DELETE FROM '.TokenAccount::class.' t WHERE t.scadenza < :limite')
                ->setParameter('limite', new \DateTimeImmutable('-7 days'))
                ->execute(),
            // Condivisioni esterne finite da 30 giorni: resta solo l'audit.
            'condivisioni' => (int) $this->em->createQuery('DELETE FROM '.CondivisioneEsterna::class.' c WHERE c.scadeIl < :limite OR c.revocataIl < :limite')
                ->setParameter('limite', new \DateTimeImmutable('-30 days'))
                ->execute(),
            'famiglie_eliminate' => $this->tenantDeletion->purgeDue(),
            'procedure_eseguite' => $this->procedure->eseguiDovute(),
            'emergenze_concesse' => $this->emergenza->eseguiDovute(),
        ];
        $this->logger->info('Pulizia periodica: {result}', ['result' => json_encode($result)]);

        return $result;
    }
}
