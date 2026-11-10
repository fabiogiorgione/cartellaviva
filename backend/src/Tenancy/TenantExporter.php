<?php

namespace App\Tenancy;

use App\Account\Entity\User;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\UploadPolicy;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\NonConsentito;
use App\Shared\Errore\NonPiuValido;
use App\Shared\TokenCasuale;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Entity\TenantExport;
use App\Tenancy\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use ZipStream\ZipStream;

/**
 * Esportazione di tutti i documenti di una famiglia in un unico ZIP.
 *
 * Lo ZIP non viene mai salvato: si genera durante il download, decifrando un
 * documento alla volta in memoria. Struttura: una cartella per categoria più
 * "indice.csv" (separatore ";" e BOM UTF-8, si apre direttamente in Excel).
 */
final class TenantExporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MembershipRepository $memberships,
        private readonly DocumentService $documents,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Solo un proprietario attivo; restituisce il token in chiaro (a DB resta l'hash). */
    public function create(Tenant $tenant, User $user): array
    {
        $this->assertOwner($tenant, $user);
        $token = TokenCasuale::nuovo();
        $export = new TenantExport($tenant, $user, hash('sha256', $token));
        $this->em->persist($export);
        $this->em->flush();
        $this->audit->log('tenant.export_created', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), actor: $user);

        return ['token' => $token, 'scadeIl' => $export->getScadeIl()];
    }

    /**
     * Riscatta il link: monouso, entro 24 ore, e solo se chi l'ha chiesto è ancora
     * proprietario del tenant.
     */
    public function redeem(string $token): TenantExport
    {
        $export = '' !== $token ? $this->em->getRepository(TenantExport::class)->findOneBy(['tokenHash' => hash('sha256', $token)]) : null;
        if (!$export instanceof TenantExport || !$export->isUsabile()) {
            throw new NonPiuValido('Il link di esportazione non è valido, è scaduto o è già stato usato: preparane un altro.');
        }
        $this->assertOwner($export->getTenant(), $export->getRichiestoDa());
        $export->markScaricato();
        $this->em->flush();
        $this->audit->log('tenant.export_downloaded', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $export->getTenant()->getId(), tenantId: $export->getTenant()->getId(), actor: $export->getRichiestoDa());

        return $export;
    }

    /** Scrive lo ZIP su $output (lo stream della risposta HTTP). */
    public function stream(Tenant $tenant, $output): void
    {
        $zip = new ZipStream(outputStream: $output, sendHttpHeaders: false, defaultEnableZeroHeader: true, flushOutput: true);
        $rows = [['Categoria', 'Titolo', 'Data del documento', 'Riguarda', 'File nello ZIP', 'Caricato da', 'Caricato il']];
        $usati = [];

        $query = $this->em->createQuery('SELECT d, c FROM '.Documento::class.' d JOIN d.categoria c WHERE d.tenant = :t ORDER BY c.nome ASC, d.createdAt ASC')
            ->setParameter('t', $tenant->getId(), 'uuid');
        foreach ($query->toIterable() as $documento) {
            \assert($documento instanceof Documento);
            $path = $this->uniquePath($documento, $usati);
            try {
                $contents = $this->documents->readContent($documento);
                $zip->addFile(fileName: $path, data: $contents);
                unset($contents);
            } catch (\Throwable $e) {
                $this->logger->error('Export: documento {id} non leggibile', ['id' => (string) $documento->getId(), 'exception' => $e]);
                $path = '(non leggibile: contatta l\'assistenza)';
            }
            $rows[] = [
                $documento->getCategoria()->getNome(),
                $documento->getTitolo(),
                $documento->getDataDocumento()?->format('d/m/Y') ?? '',
                implode(', ', array_map(static fn (MembroFamiglia $m) => $m->getDisplayName(), $documento->getMembri()->toArray())),
                $path,
                $documento->getOwner()->getFullName(),
                $documento->getCreatedAt()->format('d/m/Y H:i'),
            ];
            $this->em->detach($documento);
        }

        $zip->addFile(fileName: 'indice.csv', data: "\u{FEFF}".$this->csv($rows));
        $zip->finish();
    }

    public function fileName(Tenant $tenant): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $tenant->getName()), '-') ?: 'famiglia';

        return sprintf('CartellaViva-%s-%s.zip', $base, (new \DateTimeImmutable())->format('Y-m-d'));
    }

    private function assertOwner(Tenant $tenant, User $user): void
    {
        $membership = $this->memberships->findOneByUserAndTenant($user, $tenant);
        if (null === $membership || !$membership->isProprietarioAttivo()) {
            $this->audit->log('tenant.export_denied', AuditOutcome::DENIED, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), actor: $user);
            throw new NonConsentito('Solo un proprietario può esportare i documenti della famiglia.');
        }
    }

    /** @param array<string, true> $usati */
    private function uniquePath(Documento $documento, array &$usati): string
    {
        $clean = static fn (string $s) => trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', $s), ' .') ?: 'senza nome';
        $dir = $clean($documento->getCategoria()->getNome());
        $name = mb_substr($clean($documento->getTitolo()), 0, 120);
        if (null !== $documento->getDataDocumento()) {
            $name .= ' ('.$documento->getDataDocumento()->format('Y-m-d').')';
        }
        $ext = UploadPolicy::extensionFor($documento->getMimeType());
        $path = "$dir/$name.$ext";
        for ($i = 2; isset($usati[mb_strtolower($path)]); ++$i) {
            $path = "$dir/$name ($i).$ext";
        }
        $usati[mb_strtolower($path)] = true;

        return $path;
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            // Le celle che iniziano con = + - @ si neutralizzano (CSV injection in Excel).
            fputcsv($out, array_map(static fn (string $v) => preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row), ';', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
