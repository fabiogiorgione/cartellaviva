<?php

namespace App\Portale;

use App\Portale\Entity\ProceduraAccesso;
use App\Shared\Crypto\MasterKeyRing;
use App\Tenancy\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "Salute e da fare" del portale: stato dei componenti, cose da gestire e
 * numeri aggregati. Solo conteggi e metadati: nessun contenuto delle famiglie.
 */
final class SaluteService
{
    /** Un backup della master key più vecchio di così va rifatto (es. dopo una nuova versione). */
    private const BACKUP_MAX_GIORNI = 90;

    public function __construct(
        private readonly Connection $db,
        private readonly MasterKeyRing $keyRing,
        private readonly HttpClientInterface $httpClient,
        private readonly ProcedureService $procedure,
        private readonly \App\Account\Push\PushGatewayInterface $push,
        #[Autowire(env: 'OLLAMA_URL')]
        private readonly string $ollamaUrl,
        #[Autowire(env: 'OLLAMA_MODEL')]
        private readonly string $ollamaModel,
        #[Autowire('%env(default::GOTENBERG_URL)%')]
        private readonly ?string $gotenbergUrl = null,
    ) {
    }

    /** Convertitore delle anteprime Office (Gotenberg) e anteprime fallite nelle ultime 24 ore. */
    private function convertitore(): array
    {
        $da = (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
        $fallite = (int) $this->db->fetchOne("SELECT COUNT(*) FROM documento WHERE anteprima_stato = 'fallita' AND updated_at >= :da", ['da' => $da]);
        if (null === $this->gotenbergUrl || '' === trim($this->gotenbergUrl)) {
            return ['stato' => 'non_configurato', 'fallite24h' => $fallite];
        }
        try {
            $ok = 200 === $this->httpClient->request('GET', rtrim($this->gotenbergUrl, '/').'/health', ['timeout' => 2])->getStatusCode();

            return ['stato' => $ok ? 'pronto' : 'non_raggiungibile', 'fallite24h' => $fallite];
        } catch (\Throwable) {
            return ['stato' => 'non_raggiungibile', 'fallite24h' => $fallite];
        }
    }

    /** @return array<string, mixed> */
    public function stato(): array
    {
        $chiave = $this->chiave();

        return [
            'aggiornatoIl' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'componenti' => [
                'worker' => $this->worker(),
                'ocr' => $this->ocr(),
                'import' => ['inErrore' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM import_source WHERE stato = 'errore'")],
                'ai' => $this->ai(),
                'convertitore' => $this->convertitore(),
                'chiave' => $chiave,
                'push' => [
                    'stato' => $this->push->isEnabled() ? 'configurate' : 'non_configurate',
                    'dispositivi' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM dispositivo_push'),
                ],
            ],
            'daFare' => $this->daFare($chiave),
            'numeri' => $this->numeri(),
        ];
    }

    /** @return array<string, int> */
    private function worker(): array
    {
        $rows = $this->db->fetchAllKeyValue('SELECT queue_name, COUNT(*) FROM messenger_messages WHERE delivered_at IS NULL GROUP BY queue_name');

        return ['inAttesa' => (int) array_sum(array_diff_key($rows, ['failed' => 0])), 'falliti' => (int) ($rows['failed'] ?? 0)];
    }

    /** @return array<string, int> */
    private function ocr(): array
    {
        $da = (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');

        return [
            'fallite24h' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM documento WHERE ocr_status = 'fallito' AND updated_at >= ?", [$da]),
            'documenti24h' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM documento WHERE created_at >= ?', [$da]),
            'inCoda' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM documento WHERE ocr_status = 'in_coda'"),
        ];
    }

    /** @return array<string, mixed> */
    private function ai(): array
    {
        if ('' === trim($this->ollamaUrl) || '' === trim($this->ollamaModel)) {
            return ['stato' => 'disattivato', 'modello' => null];
        }
        try {
            $tags = $this->httpClient->request('GET', rtrim($this->ollamaUrl, '/').'/api/tags', ['timeout' => 2])->toArray();
            $modelli = array_column($tags['models'] ?? [], 'name');

            return ['stato' => \in_array($this->ollamaModel, $modelli, true) ? 'pronto' : 'modello_mancante', 'modello' => $this->ollamaModel];
        } catch (\Throwable) {
            return ['stato' => 'non_raggiungibile', 'modello' => $this->ollamaModel];
        }
    }

    /** @return array<string, mixed> */
    private function chiave(): array
    {
        $registrate = $this->db->fetchAllKeyValue('SELECT version, fingerprint FROM crypto_key_version');
        $improntaOk = true;
        foreach ($registrate as $v => $fp) {
            if (!\in_array((int) $v, $this->keyRing->versions(), true) || $this->keyRing->fingerprint((int) $v) !== $fp) {
                $improntaOk = false;
            }
        }
        $backup = $this->db->fetchOne("SELECT MAX(occurred_at) FROM audit_log WHERE action = 'crypto.key_exported' AND outcome = 'success'");

        return [
            'versioneCorrente' => $this->keyRing->currentVersion(),
            'versioni' => $this->keyRing->versions(),
            'improntaOk' => $improntaOk,
            'ultimoBackup' => false !== $backup && null !== $backup ? (new \DateTimeImmutable((string) $backup))->format(\DATE_ATOM) : null,
            'documentiCifrati' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM documento WHERE cifrato = true'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function daFare(array $chiave): array
    {
        $voci = [];
        $ultimo = null !== $chiave['ultimoBackup'] ? new \DateTimeImmutable($chiave['ultimoBackup']) : null;
        if (!$chiave['improntaOk']) {
            $voci[] = ['tipo' => 'chiave', 'urgente' => true, 'titolo' => 'Master key diversa da quella registrata', 'dettaglio' => 'Le impronte non coincidono: i documenti cifrati potrebbero essere illeggibili. Controlla con app:crypto:key status.'];
        }
        if (null === $ultimo || $ultimo < new \DateTimeImmutable(sprintf('-%d days', self::BACKUP_MAX_GIORNI))) {
            $voci[] = ['tipo' => 'backup', 'urgente' => true, 'titolo' => null === $ultimo ? 'Backup della master key mai fatto' : 'Backup della master key vecchio',
                'dettaglio' => 'Senza backup, perdere la VM significa perdere i documenti sensibili. Esegui app:crypto:key export e conserva file e passphrase separati dal backup del DB.'];
        }
        $enti = $this->db->fetchFirstColumn("SELECT t.name FROM ente_profilo p JOIN tenant t ON t.id = p.tenant_id WHERE p.stato = 'in_verifica' ORDER BY p.created_at");
        if ([] !== $enti) {
            $voci[] = ['tipo' => 'enti', 'urgente' => false, 'titolo' => sprintf('%d enti da verificare', \count($enti)), 'dettaglio' => implode(', ', $enti)];
        }
        $recuperi = (int) $this->db->fetchOne('SELECT COUNT(*) FROM procedura_accesso WHERE stato = ?', [ProceduraAccesso::IDENTITA_DA_VERIFICARE]);
        if ($recuperi > 0) {
            $voci[] = ['tipo' => 'recuperi', 'urgente' => false, 'titolo' => sprintf('%d recuperi d\'accesso da verificare', $recuperi), 'dettaglio' => 'Identità da verificare con il supporto.'];
        }
        $orfane = $this->procedure->famiglieSenzaProprietario();
        if ([] !== $orfane) {
            $voci[] = ['tipo' => 'senza_proprietario', 'urgente' => false, 'titolo' => sprintf('%d famiglie senza proprietario', \count($orfane)), 'dettaglio' => implode(', ', array_map(static fn (Tenant $t) => $t->getName(), $orfane))];
        }
        $eliminazioni = $this->db->fetchAllAssociative('SELECT name, eliminazione_programmata_il FROM tenant WHERE eliminazione_programmata_il IS NOT NULL ORDER BY eliminazione_programmata_il');
        if ([] !== $eliminazioni) {
            $voci[] = ['tipo' => 'eliminazioni', 'urgente' => false, 'titolo' => sprintf('%d famiglie in cancellazione', \count($eliminazioni)),
                'dettaglio' => implode(', ', array_map(static fn ($r) => sprintf('%s (il %s)', $r['name'], (new \DateTimeImmutable($r['eliminazione_programmata_il']))->format('d/m/Y')), $eliminazioni))];
        }
        $idee = (int) $this->db->fetchOne("SELECT COUNT(*) FROM idea WHERE stato = 'nuova' AND duplicato_di_id IS NULL");
        if ($idee > 0) {
            $voci[] = ['tipo' => 'idee', 'urgente' => false, 'titolo' => 1 === $idee ? '1 nuova idea da leggere' : sprintf('%d nuove idee da leggere', $idee), 'dettaglio' => 'Portale delle idee: proposte degli utenti per migliorare l\'app.'];
        }
        if ($this->worker()['falliti'] > 0) {
            $voci[] = ['tipo' => 'worker', 'urgente' => false, 'titolo' => 'Messaggi falliti nella coda', 'dettaglio' => 'Controlla con messenger:failed:show e riprova con messenger:failed:retry.'];
        }

        return $voci;
    }

    /** @return array<string, int> */
    private function numeri(): array
    {
        return [
            'famiglie' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM tenant WHERE type = 'privato'"),
            'entiVerificati' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM ente_profilo WHERE stato = 'verificato'"),
            'iscritti' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM ente_iscritto'),
            'utentiAttivi30' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM app_user WHERE last_login_at >= ?', [(new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s')]),
            'spazioDocumenti' => (int) $this->db->fetchOne('SELECT COALESCE(SUM(CAST(dimensione_bytes AS BIGINT)), 0) FROM documento'),
            'accessiNegati24h' => (int) $this->db->fetchOne("SELECT COUNT(*) FROM audit_log WHERE outcome = 'denied' AND occurred_at >= ?", [(new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s')]),
        ];
    }
}
