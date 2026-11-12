<?php

namespace App\Pratiche\Entity;

use App\Account\Entity\User;
use App\Documenti\Entity\Documento;
use App\Pratiche\Enum\PagamentoOrigine;
use App\Pratiche\Enum\PagamentoStato;
use App\Pratiche\Repository\PagamentoRepository;
use App\Scadenze\Entity\Scadenza;
use App\Shared\Entity\Contract\OwnedResourceInterface;
use App\Shared\Entity\Contract\TenantOwnedTrait;
use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Pagamento da fare (avviso PagoPA, bollettino…), fase 1 delle Pratiche.
 *
 * - owner = chi l'ha creato (nel contratto "creatoDa"): con pagamento:manage:own
 *   lo gestisce solo lui;
 * - l'importo è in centesimi, sempre maggiore di zero;
 * - documento d'origine, ricevuta e scadenza collegata sono facoltativi e
 *   restano null se l'oggetto collegato viene eliminato.
 */
#[ORM\Entity(repositoryClass: PagamentoRepository::class)]
#[ORM\Table(name: 'pagamento')]
#[ORM\Index(name: 'idx_pagamento_tenant_stato', columns: ['tenant_id', 'stato'])]
#[ORM\HasLifecycleCallbacks]
#[PermissionResource('pagamento', 'Pagamenti', [
    'read' => 'Visualizza i pagamenti da fare e quelli fatti',
    'manage:own' => 'Crea e gestisce i propri pagamenti',
    'manage:all' => 'Gestisce tutti i pagamenti del tenant',
], sensitive: false)]
class Pagamento implements OwnedResourceInterface
{
    /** Importo massimo: 10 milioni di euro (la colonna è un intero a 32 bit). */
    public const MAX_CENTESIMI = 1_000_000_000;

    use TenantOwnedTrait;
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 200)]
    private string $titolo;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $ente = null;

    #[ORM\Column]
    private int $importoCentesimi;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scadenza = null;

    #[ORM\Column(length: 18, nullable: true)]
    private ?string $codiceAvviso = null;

    #[ORM\Column(length: 11, nullable: true)]
    private ?string $codiceFiscaleEnte = null;

    #[ORM\Column(length: 20, enumType: PagamentoStato::class)]
    private PagamentoStato $stato = PagamentoStato::DA_PAGARE;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pagatoIl = null;

    #[ORM\ManyToOne(targetEntity: Documento::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Documento $documento = null;

    #[ORM\ManyToOne(targetEntity: Documento::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Documento $ricevuta = null;

    #[ORM\ManyToOne(targetEntity: Scadenza::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Scadenza $scadenzaCollegata = null;

    #[ORM\Column(length: 20, enumType: PagamentoOrigine::class)]
    private PagamentoOrigine $origine = PagamentoOrigine::MANUALE;

    public function __construct(Tenant $tenant, User $owner, string $titolo, int $importoCentesimi)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->owner = $owner;
        $this->titolo = $titolo;
        $this->importoCentesimi = $importoCentesimi;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitolo(): string
    {
        return $this->titolo;
    }

    public function getEnte(): ?string
    {
        return $this->ente;
    }

    public function getImportoCentesimi(): int
    {
        return $this->importoCentesimi;
    }

    /** "180.40" */
    public function getImporto(): string
    {
        return self::formattaImporto($this->importoCentesimi);
    }

    public static function formattaImporto(int $centesimi): string
    {
        return sprintf('%d.%02d', intdiv($centesimi, 100), $centesimi % 100);
    }

    public function getScadenza(): ?\DateTimeImmutable
    {
        return $this->scadenza;
    }

    public function getCodiceAvviso(): ?string
    {
        return $this->codiceAvviso;
    }

    public function getCodiceFiscaleEnte(): ?string
    {
        return $this->codiceFiscaleEnte;
    }

    public function getStato(): PagamentoStato
    {
        return $this->stato;
    }

    public function getPagatoIl(): ?\DateTimeImmutable
    {
        return $this->pagatoIl;
    }

    public function getDocumento(): ?Documento
    {
        return $this->documento;
    }

    public function getRicevuta(): ?Documento
    {
        return $this->ricevuta;
    }

    public function getScadenzaCollegata(): ?Scadenza
    {
        return $this->scadenzaCollegata;
    }

    public function getOrigine(): PagamentoOrigine
    {
        return $this->origine;
    }

    public function update(string $titolo, ?string $ente, int $importoCentesimi, ?\DateTimeImmutable $scadenza, ?string $codiceAvviso, ?string $codiceFiscaleEnte, ?Documento $documento): void
    {
        $this->titolo = $titolo;
        $this->ente = $ente;
        $this->importoCentesimi = $importoCentesimi;
        $this->scadenza = $scadenza;
        $this->codiceAvviso = $codiceAvviso;
        $this->codiceFiscaleEnte = $codiceFiscaleEnte;
        $this->documento = $documento;
        $this->origine = null !== $documento ? PagamentoOrigine::DOCUMENTO : PagamentoOrigine::MANUALE;
    }

    public function segnaPagato(\DateTimeImmutable $pagatoIl, ?Documento $ricevuta): void
    {
        $this->stato = PagamentoStato::PAGATO;
        $this->pagatoIl = $pagatoIl;
        $this->ricevuta = $ricevuta;
    }

    public function annullaPagato(): void
    {
        $this->stato = PagamentoStato::DA_PAGARE;
        $this->pagatoIl = null;
        $this->ricevuta = null;
    }

    public function collegaScadenza(?Scadenza $scadenza): void
    {
        $this->scadenzaCollegata = $scadenza;
    }
}
