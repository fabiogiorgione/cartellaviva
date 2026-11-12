<?php

namespace App\Pratiche;

use App\Account\Entity\User;
use App\Documenti\Entity\Documento;
use App\Documenti\Repository\DocumentoRepository;
use App\Pratiche\Entity\Pagamento;
use App\Pratiche\Enum\PagamentoStato;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Enum\ScadenzaRicorrenza;
use App\Scadenze\Enum\ScadenzaStato;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Azioni sui pagamenti: creazione, modifica, "pagato", eliminazione, con la
 * scadenza collegata (promemoria 7 giorni prima) tenuta allineata.
 * Validazione con messaggi in italiano (HTTP 422); audit senza importi né codici.
 */
final class PagamentiService
{
    public const PROMEMORIA_GIORNI = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly TenantAccess $access,
        private readonly DocumentoRepository $documenti,
    ) {
    }

    /** @param array<string, mixed> $dati corpo della richiesta */
    public function crea(Tenant $tenant, User $chi, array $dati): Pagamento
    {
        // Prima il permesso (sul pagamento nuovo dell'utente: con ":own" crea i propri), poi i dati.
        $this->access->require('pagamento:manage', new Pagamento($tenant, $chi, '-', 1));
        $titolo = $this->titolo($dati['titolo'] ?? null);
        $importo = $this->importo($dati['importo'] ?? null);
        $documento = $this->documento($tenant, $dati['documentoId'] ?? null, 'documentoId');
        $pagamento = new Pagamento($tenant, $chi, $titolo, $importo);
        $pagamento->update(
            $titolo,
            $this->testo($dati['ente'] ?? null, 200, 'L\'ente può avere al massimo 200 caratteri.'),
            $importo,
            $this->data($dati['scadenza'] ?? null, 'scadenza'),
            $this->codice($dati['codiceAvviso'] ?? null, 18, 'Il codice avviso ha 18 cifre.'),
            $this->codice($dati['codiceFiscaleEnte'] ?? null, 11, 'Il codice fiscale dell\'ente ha 11 cifre.'),
            $documento,
        );
        $this->em->persist($pagamento);
        $this->sincronizzaScadenza($pagamento, $chi, $this->promemoria($dati, true));
        // Il documento d'origine ha la sua decisione: la proposta è accettata.
        $documento?->chiudiPagamentoProposta(Documento::PROPOSTA_ACCETTATA);
        $this->em->flush();
        $this->log('pagamento.creato', $pagamento, ['origine' => $pagamento->getOrigine()->value, 'conScadenza' => null !== $pagamento->getScadenzaCollegata()]);

        return $pagamento;
    }

    /** @param array<string, mixed> $dati solo i campi presenti vengono cambiati */
    public function aggiorna(Pagamento $pagamento, User $chi, array $dati): void
    {
        $ha = static fn (string $campo): bool => \array_key_exists($campo, $dati);
        $tenant = $pagamento->getTenant();
        $cambiati = [];
        $titolo = $pagamento->getTitolo();
        $ente = $pagamento->getEnte();
        $importo = $pagamento->getImportoCentesimi();
        $scadenza = $pagamento->getScadenza();
        $codice = $pagamento->getCodiceAvviso();
        $cf = $pagamento->getCodiceFiscaleEnte();
        $documento = $pagamento->getDocumento();

        if ($ha('titolo')) {
            $titolo = $this->titolo($dati['titolo']);
            $cambiati[] = 'titolo';
        }
        if ($ha('importo')) {
            $importo = $this->importo($dati['importo']);
            $cambiati[] = 'importo';
        }
        if ($ha('ente')) {
            $ente = $this->testo($dati['ente'], 200, 'L\'ente può avere al massimo 200 caratteri.');
            $cambiati[] = 'ente';
        }
        if ($ha('scadenza')) {
            $scadenza = $this->data($dati['scadenza'], 'scadenza');
            $cambiati[] = 'scadenza';
        }
        if ($ha('codiceAvviso')) {
            $codice = $this->codice($dati['codiceAvviso'], 18, 'Il codice avviso ha 18 cifre.');
            $cambiati[] = 'codiceAvviso';
        }
        if ($ha('codiceFiscaleEnte')) {
            $cf = $this->codice($dati['codiceFiscaleEnte'], 11, 'Il codice fiscale dell\'ente ha 11 cifre.');
            $cambiati[] = 'codiceFiscaleEnte';
        }
        if ($ha('documentoId')) {
            $documento = $this->documento($tenant, $dati['documentoId'], 'documentoId');
            $cambiati[] = 'documento';
        }

        $primaSenzaScadenza = null === $pagamento->getScadenza();
        $pagamento->update($titolo, $ente, $importo, $scadenza, $codice, $cf, $documento);
        // In modifica il promemoria nasce solo se richiesto o se la scadenza compare ora.
        $this->sincronizzaScadenza($pagamento, $chi, $this->promemoria($dati, $primaSenzaScadenza && $ha('scadenza')));
        if ($ha('documentoId')) {
            $documento?->chiudiPagamentoProposta(Documento::PROPOSTA_ACCETTATA);
        }
        $this->em->flush();
        $this->log('pagamento.modificato', $pagamento, ['campi' => $cambiati]);
    }

    /** @param array<string, mixed> $dati */
    public function segnaPagato(Pagamento $pagamento, User $chi, array $dati): void
    {
        if (PagamentoStato::PAGATO === $pagamento->getStato()) {
            throw new Conflitto('Il pagamento è già stato segnato come pagato.');
        }
        $oggi = new \DateTimeImmutable('today');
        $pagatoIl = $this->data($dati['pagatoIl'] ?? null, 'pagatoIl') ?? $oggi;
        if ($pagatoIl > $oggi) {
            throw new DatiNonValidi('La data di pagamento non può essere nel futuro.');
        }
        $ricevuta = $this->documento($pagamento->getTenant(), $dati['ricevutaDocumentoId'] ?? null, 'ricevutaDocumentoId');
        $pagamento->segnaPagato($pagatoIl, $ricevuta);
        $scadenza = $pagamento->getScadenzaCollegata();
        if (null !== $scadenza && ScadenzaStato::APERTA === $scadenza->getStato()) {
            $scadenza->complete($chi);
        }
        $this->em->flush();
        $this->log('pagamento.pagato', $pagamento, ['conRicevuta' => null !== $ricevuta]);
    }

    public function annullaPagato(Pagamento $pagamento): void
    {
        if (PagamentoStato::PAGATO !== $pagamento->getStato()) {
            throw new Conflitto('Il pagamento è ancora da pagare.');
        }
        $pagamento->annullaPagato();
        $scadenza = $pagamento->getScadenzaCollegata();
        if (null !== $scadenza && ScadenzaStato::FATTA === $scadenza->getStato()) {
            $scadenza->riapri();
        }
        $this->em->flush();
        $this->log('pagamento.modificato', $pagamento, ['campi' => ['stato']]);
    }

    public function elimina(Pagamento $pagamento): void
    {
        $this->log('pagamento.eliminato', $pagamento);
        $scadenza = $pagamento->getScadenzaCollegata();
        if (null !== $scadenza && ScadenzaStato::APERTA === $scadenza->getStato()) {
            $this->em->remove($scadenza);
        }
        $this->em->remove($pagamento);
        $this->em->flush();
    }

    /** L'avviso proposto dal testo del documento non interessa. */
    public function ignoraProposta(Documento $documento): void
    {
        $documento->chiudiPagamentoProposta(Documento::PROPOSTA_IGNORATA);
        $this->em->flush();
        $this->audit->log('documento.pagamento_proposta_ignorata', AuditOutcome::SUCCESS, resourceType: 'documento', resourceId: (string) $documento->getId(), tenantId: $documento->getTenant()->getId());
    }

    /**
     * Allinea la scadenza collegata: senza data la scadenza aperta sparisce;
     * con una data la si crea (se richiesto, e solo per pagamenti da pagare) o la si aggiorna.
     */
    private function sincronizzaScadenza(Pagamento $pagamento, User $chi, bool $promemoria): void
    {
        $scadenza = $pagamento->getScadenzaCollegata();
        $data = $pagamento->getScadenza();
        $titolo = mb_substr('Pagare: '.$pagamento->getTitolo(), 0, 200);
        if (null === $data) {
            if (null !== $scadenza && ScadenzaStato::APERTA === $scadenza->getStato()) {
                $this->em->remove($scadenza);
                $pagamento->collegaScadenza(null);
            }

            return;
        }
        if (null === $scadenza) {
            if (!$promemoria || PagamentoStato::DA_PAGARE !== $pagamento->getStato()) {
                return;
            }
            $scadenza = new Scadenza($pagamento->getTenant(), $chi, $titolo, $data);
            $scadenza->update($titolo, null, $data, ScadenzaRicorrenza::NESSUNA, self::PROMEMORIA_GIORNI, $pagamento->getDocumento(), null, null);
            $this->em->persist($scadenza);
            $pagamento->collegaScadenza($scadenza);
            // La scadenza del pagamento sostituisce quella proposta dallo stesso documento.
            $pagamento->getDocumento()?->chiudiScadenzaProposta(Documento::PROPOSTA_ACCETTATA);

            return;
        }
        if (ScadenzaStato::APERTA === $scadenza->getStato()) {
            $scadenza->update($titolo, $scadenza->getNote(), $data, $scadenza->getRicorrenza(), $scadenza->getPromemoriaGiorni(), $pagamento->getDocumento(), $scadenza->getMembro(), $scadenza->getCategoria());
        }
    }

    /** @param array<string, mixed> $dati */
    private function promemoria(array $dati, bool $predefinito): bool
    {
        if (!\array_key_exists('promemoria', $dati)) {
            return $predefinito;
        }

        return true === filter_var($dati['promemoria'], \FILTER_VALIDATE_BOOLEAN);
    }

    private function titolo(mixed $valore): string
    {
        $titolo = \is_scalar($valore) ? trim((string) $valore) : '';
        if ('' === $titolo) {
            throw new DatiNonValidi('Il titolo è obbligatorio.');
        }
        if (mb_strlen($titolo) > 200) {
            throw new DatiNonValidi('Il titolo può avere al massimo 200 caratteri.');
        }

        return $titolo;
    }

    /** @return int centesimi */
    private function importo(mixed $valore): int
    {
        if (!\is_int($valore) && !\is_float($valore) && !\is_string($valore)) {
            throw new DatiNonValidi('L\'importo è obbligatorio.');
        }
        $testo = trim(str_replace(',', '.', (string) $valore));
        if ('' === $testo) {
            throw new DatiNonValidi('L\'importo è obbligatorio.');
        }
        if (1 !== preg_match('/^-?\d{1,12}(\.\d{1,2})?$/', $testo)) {
            throw new DatiNonValidi('L\'importo non è valido: usa il formato 180.40.');
        }
        $centesimi = (int) round((float) $testo * 100);
        if ($centesimi <= 0) {
            throw new DatiNonValidi('L\'importo deve essere maggiore di zero.');
        }
        if ($centesimi > Pagamento::MAX_CENTESIMI) {
            throw new DatiNonValidi('L\'importo è troppo alto.');
        }

        return $centesimi;
    }

    private function testo(mixed $valore, int $max, string $errore): ?string
    {
        $testo = \is_scalar($valore) ? trim((string) $valore) : '';
        if (mb_strlen($testo) > $max) {
            throw new DatiNonValidi($errore);
        }

        return '' === $testo ? null : $testo;
    }

    private function data(mixed $valore, string $campo): ?\DateTimeImmutable
    {
        $testo = \is_scalar($valore) ? trim((string) $valore) : '';
        if ('' === $testo) {
            return null;
        }
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $testo);
        if (false === $data || $data->format('Y-m-d') !== $testo) {
            throw new DatiNonValidi(sprintf('La data "%s" non è valida: usa il formato AAAA-MM-GG.', $campo));
        }

        return $data;
    }

    /** Solo cifre (spazi e trattini tolti), esattamente $cifre. */
    private function codice(mixed $valore, int $cifre, string $errore): ?string
    {
        $testo = \is_scalar($valore) ? (string) preg_replace('/[\s\-.]+/', '', trim((string) $valore)) : '';
        if ('' === $testo) {
            return null;
        }
        if (1 !== preg_match('/^\d{'.$cifre.'}$/', $testo)) {
            throw new DatiNonValidi($errore);
        }

        return $testo;
    }

    /** Documento della famiglia che l'utente può leggere; altrimenti per lui non esiste. */
    private function documento(Tenant $tenant, mixed $id, string $campo): ?Documento
    {
        if (null === $id || '' === $id) {
            return null;
        }
        $documento = \is_string($id) ? $this->documenti->findOneInTenant($tenant, $id) : null;
        if (null === $documento || !$this->access->isGranted('documento:read', $documento)) {
            throw new DatiNonValidi(sprintf('Il documento indicato (%s) non è stato trovato in questa famiglia.', $campo));
        }

        return $documento;
    }

    /** @param array<string, mixed> $contesto */
    private function log(string $azione, Pagamento $pagamento, array $contesto = []): void
    {
        $this->audit->log($azione, AuditOutcome::SUCCESS, resourceType: 'pagamento', resourceId: (string) $pagamento->getId(), tenantId: $pagamento->getTenant()->getId(), context: $contesto);
    }
}
