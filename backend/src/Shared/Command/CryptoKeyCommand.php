<?php

namespace App\Shared\Command;

use App\Documenti\Repository\DocumentoRepository;
use App\Ente\Entity\FileCifrato;
use App\Pratiche\Entity\Pratica;
use App\Import\Repository\ImportSourceRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Crypto\CryptoException;
use App\Shared\Crypto\DocumentCipher;
use App\Shared\Crypto\KeyBackup;
use App\Shared\Crypto\MasterKeyRing;
use App\Shared\Enum\AuditOutcome;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gestione della master key dei documenti sensibili. Procedura completa in
 * docs/gestione-chiavi.md.
 *
 *   app:crypto:key generate   crea il file della chiave (se non esiste) — prima installazione
 *   app:crypto:key add-version aggiunge una nuova versione al file (rotazione)
 *   app:crypto:key status     versioni nel file, impronte registrate, documenti per versione
 *   app:crypto:key export     backup cifrato con passphrase (da conservare SEPARATO dal backup DB)
 *   app:crypto:key restore    ripristina il file della chiave da un backup
 *   app:crypto:key rewrap     ricifra le chiavi dei documenti con la versione corrente
 */
#[AsCommand(name: 'app:crypto:key', description: 'Gestione della master key dei documenti sensibili (generate|add-version|status|export|restore|rewrap)')]
final class CryptoKeyCommand
{
    public function __construct(
        private readonly MasterKeyRing $keyRing,
        private readonly Connection $connection,
        private readonly DocumentoRepository $documenti,
        private readonly ImportSourceRepository $importSources,
        private readonly DocumentCipher $cipher,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        #[Autowire('%env(resolve:APP_MASTER_KEY_FILE)%')]
        private readonly string $keyFile,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('generate | add-version | status | export | restore | rewrap')] string $action,
        #[Option('File di backup (export: destinazione, restore: sorgente)')] ?string $file = null,
        #[Option('Sovrascrive il file della chiave esistente (restore)')] bool $force = false,
        #[Option('Dove scrivere la chiave ripristinata (restore; default APP_MASTER_KEY_FILE)')] ?string $output = null,
    ): int {
        try {
            return match ($action) {
                'generate' => $this->generate($io, $file ?? $this->keyFile),
                'add-version' => $this->addVersion($io, $file ?? $this->keyFile),
                'status' => $this->status($io),
                'export' => $this->export($io, $file),
                'restore' => $this->restore($io, $file, $force, $output),
                'rewrap' => $this->rewrap($io),
                default => throw new \InvalidArgumentException('Azione sconosciuta: '.$action),
            };
        } catch (CryptoException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * In Docker il secret è montato in sola lettura: si genera il file sull'host
     * (--file su una cartella montata) e poi lo si monta come secret.
     * L'impronta viene registrata nel DB al primo utilizzo.
     */
    private function generate(SymfonyStyle $io, string $target): int
    {
        if (is_file($target)) {
            $io->warning('Il file della chiave esiste già: non viene sovrascritto. Per ruotare usa "add-version".');

            return Command::FAILURE;
        }
        $dir = \dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new CryptoException('Impossibile creare la cartella '.$dir);
        }
        file_put_contents($target, "# CartellaViva master key - NON versionare, NON copiare nel backup del DB\n".MasterKeyRing::generateKeyLine(1)."\n");
        chmod($target, 0600);
        $io->success(sprintf('Chiave v1 creata in %s (impronta %s). ORA esegui "export" e conserva il backup separato dal DB.', $target, (new MasterKeyRing($target))->fingerprint(1)));

        return Command::SUCCESS;
    }

    private function addVersion(SymfonyStyle $io, string $target): int
    {
        $next = (new MasterKeyRing($target))->currentVersion() + 1;
        file_put_contents($target, MasterKeyRing::generateKeyLine($next)."\n", \FILE_APPEND);
        $io->success(sprintf('Aggiunta la versione %d. Riavvia API e worker, poi esegui "rewrap" e un nuovo "export".', $next));

        return Command::SUCCESS;
    }

    private function status(SymfonyStyle $io): int
    {
        $registered = $this->connection->fetchAllKeyValue('SELECT version, fingerprint FROM crypto_key_version ORDER BY version');
        $perVersion = $this->connection->fetchAllKeyValue('SELECT key_version, COUNT(*) FROM documento WHERE cifrato = true GROUP BY key_version');
        $sourcesPerVersion = $this->connection->fetchAllKeyValue('SELECT key_version, COUNT(*) FROM import_source WHERE encrypted_dek IS NOT NULL GROUP BY key_version');
        $entiPerVersion = $this->connection->fetchAllKeyValue('SELECT key_version, COUNT(*) FROM ente_file GROUP BY key_version');
        $pratichePerVersion = $this->connection->fetchAllKeyValue('SELECT key_version, COUNT(*) FROM pratica GROUP BY key_version');
        $rows = [];
        foreach ($this->keyRing->versions() as $v) {
            $fp = $this->keyRing->fingerprint($v);
            $rows[] = [$v, $fp, $registered[$v] ?? '(non ancora usata)', isset($registered[$v]) ? ($registered[$v] === $fp ? 'OK' : 'DIVERSA!') : '-', $perVersion[$v] ?? 0, $sourcesPerVersion[$v] ?? 0, $entiPerVersion[$v] ?? 0, $pratichePerVersion[$v] ?? 0];
        }
        foreach (array_diff_key($registered, array_flip($this->keyRing->versions())) as $v => $fp) {
            $rows[] = [$v, 'ASSENTE DAL FILE', $fp, 'MANCANTE!', $perVersion[$v] ?? 0, $sourcesPerVersion[$v] ?? 0, $entiPerVersion[$v] ?? 0, $pratichePerVersion[$v] ?? 0];
        }
        $io->table(['Versione', 'Impronta file', 'Impronta registrata', 'Esito', 'Documenti cifrati', 'Sorgenti import', 'File enti', 'Pratiche'], $rows);
        $io->writeln('Versione corrente: '.$this->keyRing->currentVersion());

        return Command::SUCCESS;
    }

    private function export(SymfonyStyle $io, ?string $file): int
    {
        $passphrase = (string) $io->askHidden('Passphrase del backup (min 16 caratteri, conservala SEPARATA dal backup)');
        if ($passphrase !== (string) $io->askHidden('Ripeti la passphrase')) {
            throw new CryptoException('Le passphrase non coincidono.');
        }
        $fingerprints = [];
        foreach ($this->keyRing->versions() as $v) {
            $fingerprints[$v] = $this->keyRing->fingerprint($v);
        }
        $backup = KeyBackup::export((string) file_get_contents($this->keyFile), $passphrase, $fingerprints);
        // Il portale mostra la data dell'ultimo backup (Salute del sistema).
        $this->audit->log('crypto.key_exported', AuditOutcome::SUCCESS, context: ['versioni' => array_keys($fingerprints)]);
        if (null !== $file) {
            file_put_contents($file, $backup);
            chmod($file, 0600);
            $io->success('Backup scritto in '.$file.' (spostalo fuori dalla VM).');
        } else {
            $io->writeln($backup);
        }

        return Command::SUCCESS;
    }

    /** --file = backup; la chiave si scrive in --output (default: APP_MASTER_KEY_FILE). */
    private function restore(SymfonyStyle $io, ?string $file, bool $force, ?string $output): int
    {
        if (null === $file || !is_file($file)) {
            throw new CryptoException('Indica il file di backup con --file.');
        }
        $target = $output ?? $this->keyFile;
        if (is_file($target) && !$force) {
            throw new CryptoException('Il file della chiave esiste già: usa --force per sostituirlo (verifica prima con "status").');
        }
        $plain = KeyBackup::import((string) file_get_contents($file), (string) $io->askHidden('Passphrase del backup'));
        file_put_contents($target, $plain);
        chmod($target, 0600);

        // Verifica contro le impronte registrate nel DB (se il DB è già ripristinato).
        $restored = new MasterKeyRing($target);
        $registered = $this->connection->fetchAllKeyValue('SELECT version, fingerprint FROM crypto_key_version');
        foreach ($registered as $v => $fp) {
            if (!\in_array((int) $v, $restored->versions(), true) || $restored->fingerprint((int) $v) !== $fp) {
                throw new CryptoException(sprintf('La chiave ripristinata NON corrisponde alla versione %d registrata nel DB.', $v));
            }
        }
        $io->success('Chiave ripristinata e verificata. Riavvia API e worker.');

        return Command::SUCCESS;
    }

    private function rewrap(SymfonyStyle $io): int
    {
        $current = $this->keyRing->currentVersion();
        $count = 0;
        foreach ($this->documenti->iterateEncryptedNotAtVersion($current) as $documento) {
            $dek = $this->cipher->unwrapDek((string) $documento->getEncryptedDek(), (int) $documento->getKeyVersion(), $documento->getTenant()->getId(), $documento->getId());
            $wrapped = $this->cipher->wrapDek($dek, $documento->getTenant()->getId(), $documento->getId(), $current);
            sodium_memzero($dek);
            $documento->rewrapDek($wrapped['wrapped'], $wrapped['version']);
            if (0 === ++$count % 100) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        $this->em->flush();
        $this->em->clear();

        // Anche le chiavi dei token OAuth delle sorgenti di import.
        $sources = 0;
        foreach ($this->importSources->iterateWithTokensNotAtVersion($current) as $source) {
            $dek = $this->cipher->unwrapDek((string) $source->getEncryptedDek(), (int) $source->getKeyVersion(), $source->getTenant()->getId(), $source->getId());
            $wrapped = $this->cipher->wrapDek($dek, $source->getTenant()->getId(), $source->getId(), $current);
            sodium_memzero($dek);
            $source->rewrapDek($wrapped['wrapped'], $wrapped['version']);
            ++$sources;
        }
        $this->em->flush();

        // E dei file degli enti (allegati delle richieste e copie consegnate).
        $fileEnti = 0;
        $query = $this->em->createQuery('SELECT f FROM '.FileCifrato::class.' f WHERE f.keyVersion <> :v')->setParameter('v', $current);
        foreach ($query->toIterable() as $file) {
            \assert($file instanceof FileCifrato);
            $dek = $this->cipher->unwrapDek($file->getEncryptedDek(), $file->getKeyVersion(), $file->getTenant()->getId(), $file->getId());
            $wrapped = $this->cipher->wrapDek($dek, $file->getTenant()->getId(), $file->getId(), $current);
            sodium_memzero($dek);
            $file->rewrapDek($wrapped['wrapped'], $wrapped['version']);
            if (0 === ++$fileEnti % 100) {
                $this->em->flush();
            }
        }
        $this->em->flush();
        $this->em->clear();

        // E delle pratiche (valori delle voci).
        $pratiche = 0;
        $query = $this->em->createQuery('SELECT p FROM '.Pratica::class.' p WHERE p.keyVersion <> :v')->setParameter('v', $current);
        foreach ($query->toIterable() as $pratica) {
            $dek = $this->cipher->unwrapDek($pratica->getEncryptedDek(), $pratica->getKeyVersion(), $pratica->getTenant()->getId(), $pratica->getId());
            $wrapped = $this->cipher->wrapDek($dek, $pratica->getTenant()->getId(), $pratica->getId(), $current);
            sodium_memzero($dek);
            $pratica->rewrapDek($wrapped['wrapped'], $wrapped['version']);
            ++$pratiche;
        }
        $this->em->flush();
        $io->success(sprintf('%d documenti, %d sorgenti di import, %d file degli enti e %d pratiche portati alla versione %d. Le versioni precedenti si possono rimuovere dal file solo quando "status" mostra 0 in ogni colonna.', $count, $sources, $fileEnti, $pratiche, $current));

        return Command::SUCCESS;
    }
}
