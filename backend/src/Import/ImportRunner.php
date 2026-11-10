<?php

namespace App\Import;

use App\Documenti\DocumentService;
use App\Documenti\Repository\DocumentoRepository;
use App\Documenti\UploadPolicy;
use App\Import\Entity\ImportSource;
use App\Import\Enum\ImportSourceStato;
use App\Import\Exception\ImportException;
use App\Import\Provider\ImportProviderInterface;
use App\Import\Provider\ImportProviderRegistry;
use App\Shared\Enum\AuditOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sincronizzazione di una sorgente (eseguita dal worker, ImportJobHandler).
 *
 * Per ogni file della cartella radice e delle sottocartelle di primo livello:
 * 1. duplicato? PRIMA del download: HMAC dell'md5Checksum di Drive contro i
 *    documenti del tenant; per i file nativi di Google (senza MD5) id + data di
 *    modifica già importati dalla sorgente;
 * 2. download (solo se non è un duplicato);
 * 3. categoria secondo la strategia (CategoriaResolver);
 * 4-5. DocumentService::importFile: cifratura se la categoria è sensibile,
 *    documento con OCR in coda.
 *
 * Un errore su un singolo file non ferma gli altri (conteggiato in "errori");
 * un errore della sorgente (token revocato, cartella sparita) porta lo stato
 * a "errore" con il motivo. Rieseguire la sincronizzazione è sempre sicuro:
 * i file già importati vengono riconosciuti come duplicati.
 */
final class ImportRunner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportProviderRegistry $providers,
        private readonly ImportTokenVault $vault,
        private readonly ImportSourceService $sources,
        private readonly CategoriaResolver $categorie,
        private readonly DocumentService $documents,
        private readonly DocumentoRepository $documenti,
        private readonly UploadPolicy $policy,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, int> esito (trovati, importati, duplicati, saltati, errori) */
    public function run(ImportSource $source): array
    {
        $esito = ['trovati' => 0, 'importati' => 0, 'duplicati' => 0, 'saltati' => 0, 'errori' => 0];
        if (!$source->isCollegata() || !\in_array($source->getStato(), [ImportSourceStato::ATTIVO, ImportSourceStato::ERRORE], true)) {
            $this->logger->info('Import: sorgente {id} non collegata o disattivata, niente da fare', ['id' => (string) $source->getId()]);
            $source->cancelSyncRequest();
            $this->em->flush();

            return $esito;
        }

        try {
            // I documenti diventano dell'utente che ha creato la sorgente: deve avere ancora il permesso.
            if (!$this->sources->canManage($source->getCreatoDa(), $source)) {
                throw new ImportException('L\'utente che ha collegato la sorgente non ha più il permesso di importare in questa famiglia: ricollegala con un altro utente.');
            }
            $provider = $this->providers->getConfigured($source->getProvider());
            $files = $provider->listFiles($this->accessToken($source, $provider), $source->getCartellaRadiceId());
        } catch (\Throwable $e) {
            $this->finish($source, $esito, $this->reason($e));

            return $esito;
        }

        foreach ($files as $file) {
            ++$esito['trovati'];
            try {
                $esito[$this->importOne($source, $provider, $file)]++;
            } catch (ImportException $e) {
                // Errore della sorgente (es. token revocato durante l'import): inutile continuare.
                $this->finish($source, $esito, $e->getMessage());

                return $esito;
            } catch (\Throwable $e) {
                ++$esito['errori'];
                // Nel log solo l'id del file: il nome può essere già un dato personale.
                $this->logger->warning('Import: file {file} della sorgente {id} non importato: {reason}', ['file' => $file->id, 'id' => (string) $source->getId(), 'reason' => $e->getMessage()]);
                if (!$this->em->isOpen()) {
                    throw $e; // EntityManager chiuso da un errore di salvataggio: Messenger riproverà il job
                }
            }
        }

        $this->finish($source, $esito, null);

        return $esito;
    }

    /** @return 'importati'|'duplicati'|'saltati' */
    private function importOne(ImportSource $source, ImportProviderInterface $provider, RemoteFile $file): string
    {
        if (null !== $file->nonSupportato || (null !== $file->dimensione && $file->dimensione > $this->policy->maxBytes())) {
            return 'saltati';
        }

        // 1. Duplicato, prima di scaricare.
        $tenant = $source->getTenant();
        if (null !== $file->md5) {
            if ($this->documenti->existsWithMd5Fingerprint($tenant, $this->documents->md5FingerprintOfHash($file->md5, $tenant))) {
                return 'duplicati';
            }
        } elseif ($this->documenti->existsImportedVersion($source, $file->id, $file->versione)) {
            return 'duplicati';
        }

        // 2. Download.
        $contents = $provider->download($this->accessToken($source, $provider), $file, $this->policy->maxBytes());
        // Un export PDF identico a un documento già presente (es. caricato a mano).
        if (null === $file->md5 && $this->documenti->existsWithMd5Fingerprint($tenant, $this->documents->md5Fingerprint($contents, $tenant))) {
            return 'duplicati';
        }

        // 3-5. Categoria, cifratura se sensibile, documento con OCR in coda.
        $categoria = $this->categorie->forFile($source, $file);
        $this->documents->importFile($source, $categoria, $contents, $file->nomeFile(), $file->id, $file->versione);

        return 'importati';
    }

    /** Access token valido: se è in scadenza si rinnova e si salva (cifrato) quello nuovo. */
    private function accessToken(ImportSource $source, ImportProviderInterface $provider): string
    {
        if ($this->vault->isAccessTokenExpiring($source)) {
            $this->vault->store($source, $provider->refresh($this->vault->refreshToken($source)));
            $this->em->flush();
        }

        return $this->vault->accessToken($source);
    }

    /** @param array<string, int> $esito */
    private function finish(ImportSource $source, array $esito, ?string $errore): void
    {
        if (null === $errore) {
            $source->completeSync($esito);
        } else {
            $source->failSync($errore, $esito);
        }
        $this->em->flush();
        $this->sources->log(null === $errore ? 'import.sync_completed' : 'import.sync_failed', $source,
            null === $errore ? AuditOutcome::SUCCESS : AuditOutcome::FAILURE,
            $esito + (null !== $errore ? ['motivo' => $errore] : []), $source->getCreatoDa());
        $this->logger->info('Import sorgente {id}: {esito}', ['id' => (string) $source->getId(), 'esito' => json_encode($esito), 'errore' => $errore]);
    }

    private function reason(\Throwable $e): string
    {
        if ($e instanceof ImportException || $e instanceof Exception\ProviderNonConfiguratoException) {
            return $e->getMessage();
        }
        $this->logger->error('Import: errore imprevisto: {reason}', ['reason' => $e->getMessage(), 'exception' => $e]);

        return 'Errore imprevisto durante la sincronizzazione: riprova più tardi.';
    }
}
