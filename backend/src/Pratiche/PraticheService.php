<?php

namespace App\Pratiche;

use App\Account\Entity\User;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Repository\DocumentoRepository;
use App\Pratiche\Entity\Pratica;
use App\Pratiche\Entity\PraticaAnalisi;
use App\Pratiche\Entity\PraticaVoce;
use App\Pratiche\Enum\PraticaStato;
use App\Pratiche\Enum\VoceOrigine;
use App\Pratiche\Enum\VoceStato;
use App\Pratiche\Message\AnalizzaDocumentoPraticaMessage;
use App\Pratiche\Modello\ModelliPratica;
use App\Pratiche\Modello\ModelloPratica;
use App\Pratiche\Modello\Requisito;
use App\Pratiche\Repository\PraticaRepository;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Enum\ScadenzaRicorrenza;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Repository\MembroFamigliaRepository;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Azioni sulle pratiche e sulle loro voci. I permessi sulla pratica li verifica
 * il controller; qui i documenti indicati devono essere leggibili dall'utente.
 * Audit senza valori nel contesto.
 */
class PraticheService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly TenantAccess $access,
        private readonly ModelliPratica $modelli,
        private readonly PraticaRepository $pratiche,
        private readonly MembroFamigliaRepository $membri,
        private readonly DocumentoRepository $documenti,
        private readonly PraticaCifratura $cifratura,
        private readonly CandidatiPratica $candidati,
        private readonly MessageBusInterface $bus,
        private readonly EstrattoreVoci $estrattore,
        private readonly DocumentService $testi,
        private readonly DoppioniVoce $doppioni,
    ) {
    }

    public function modello(Pratica $pratica): ModelloPratica
    {
        return $this->modelli->trova($pratica->getModello(), $pratica->getAnno())
            ?? throw new Conflitto(sprintf('Le regole del %s per il %d non ci sono più: chiedi a chi gestisce CartellaViva.', $pratica->getModello(), $pratica->getAnno()));
    }

    /** @param array<string, mixed> $dati {modello, anno, persone: list<id>} */
    public function avvia(Tenant $tenant, User $chi, array $dati): Pratica
    {
        $codice = \is_scalar($dati['modello'] ?? null) ? (string) $dati['modello'] : '';
        $anno = filter_var($dati['anno'] ?? null, \FILTER_VALIDATE_INT);
        if (!isset(ModelliPratica::NOMI[$codice])) {
            throw new DatiNonValidi('Scegli il tipo di pratica.');
        }
        if (false === $anno || null === $this->modelli->trova($codice, $anno)) {
            throw new DatiNonValidi(sprintf('Per il %s non ci sono ancora le regole di quell\'anno.', $codice));
        }
        if (null !== $this->pratiche->findOneBy(['tenant' => $tenant, 'modello' => $codice, 'anno' => $anno])) {
            throw new Conflitto(sprintf('C\'è già una pratica %s per il %d.', $codice, $anno));
        }
        $persone = $this->persone($tenant, $dati['persone'] ?? null);
        $id = Uuid::v7();
        $chiave = $this->cifratura->nuovaChiave($tenant, $id);
        $pratica = new Pratica($tenant, $chi, $codice, $anno, $chiave['wrapped'], $chiave['version'], $id);
        $pratica->replacePersone($persone);
        $pratica->setAnalisiSu(\is_string($dati['analisiSu'] ?? null) ? $dati['analisiSu'] : Pratica::ANALISI_SERVER);
        $this->em->persist($pratica);
        $this->em->flush();
        $this->log('pratica.avviata', $pratica, ['modello' => $codice, 'anno' => $anno, 'persone' => \count($persone), 'analisiSu' => $pratica->getAnalisiSu()]);
        $this->analizza($pratica);

        return $pratica;
    }

    /** @param array<string, mixed> $dati {stato?, persone?} */
    public function aggiorna(Pratica $pratica, User $chi, array $dati): void
    {
        $campi = [];
        if (\array_key_exists('persone', $dati)) {
            $pratica->replacePersone($this->persone($pratica->getTenant(), $dati['persone']));
            $campi[] = 'persone';
        }
        if (\array_key_exists('analisiSu', $dati)) {
            if (!\in_array($dati['analisiSu'], [Pratica::ANALISI_SERVER, Pratica::ANALISI_TELEFONO], true)) {
                throw new DatiNonValidi('analisiSu vale "server" o "telefono".');
            }
            $pratica->setAnalisiSu($dati['analisiSu']);
            $campi[] = 'analisiSu';
        }
        if (\array_key_exists('stato', $dati)) {
            $stato = \is_string($dati['stato']) ? PraticaStato::tryFrom($dati['stato']) : null;
            if (null === $stato) {
                throw new DatiNonValidi('Lo stato vale in_corso, pronta, inviata o archiviata.');
            }
            $pratica->setStato($stato);
            $campi[] = 'stato';
        }
        $inviata = PraticaStato::INVIATA === $pratica->getStato() && \in_array('stato', $campi, true);
        if ($inviata) {
            $this->promemoriaRinnovo($pratica, $chi);
        }
        $this->em->flush();
        $this->log($inviata ? 'pratica.inviata' : 'pratica.modificata', $pratica, ['campi' => $campi]);
        if (\in_array('persone', $campi, true) || \in_array('analisiSu', $campi, true) || PraticaStato::IN_CORSO === $pratica->getStato()) {
            $this->analizza($pratica);
        }
    }

    /**
     * Pratiche che scadono a fine anno (l'ISEE vale fino al 31 dicembre): alla
     * presentazione nasce la scadenza del rinnovo, con il promemoria.
     */
    private function promemoriaRinnovo(Pratica $pratica, User $chi): void
    {
        $rinnovo = $this->modello($pratica)->rinnovo;
        if (null === $rinnovo || null !== $pratica->getScadenzaRinnovo()) {
            return;
        }
        $data = new \DateTimeImmutable($pratica->getAnno().'-12-31');
        $scadenza = new Scadenza($pratica->getTenant(), $chi, $rinnovo['titolo'], $data);
        $scadenza->update($rinnovo['titolo'], 'Dal 1° gennaio si può presentare la nuova DSU.', $data, ScadenzaRicorrenza::NESSUNA, $rinnovo['giorni'], null, null, null);
        $this->em->persist($scadenza);
        $pratica->collegaScadenzaRinnovo($scadenza);
    }

    public function elimina(Pratica $pratica): void
    {
        $this->log('pratica.eliminata', $pratica);
        $this->em->remove($pratica);
        $this->em->flush();
    }

    /** Consenso (o revoca) per analizzare i documenti cifrati: una volta per pratica. */
    public function consensoCifrati(Pratica $pratica, User $chi, bool $consenso): void
    {
        if ($consenso) {
            $pratica->daiConsensoCifrati($chi);
        } else {
            $pratica->revocaConsensoCifrati();
        }
        $this->em->flush();
        $this->log('pratica.consenso_cifrati', $pratica, ['consenso' => $consenso]);
        if ($consenso) {
            $this->analizza($pratica);
        }
    }

    /**
     * Mette in coda i documenti ancora da analizzare (e riprova quelli andati in
     * errore o scartati dalle regole senza passare dall'AI).
     *
     * @return int documenti messi in coda
     */
    public function analizza(Pratica $pratica): int
    {
        if (PraticaStato::IN_CORSO !== $pratica->getStato()) {
            return 0;
        }
        // Si riprovano gli errori e i documenti scartati dalle sole regole (senza AI): costa
        // poco, e dopo un aggiornamento delle regole possono servire.
        $this->em->getConnection()->executeStatement("DELETE FROM pratica_analisi WHERE pratica_id = :p AND (esito = 'errore' OR (esito = 'nessuna' AND metodo = 'regole'))", ['p' => $pratica->getId()->toRfc4122()]);
        $stato = $this->candidati->stato($this->modello($pratica), $pratica);
        // Sul telefono: i documenti li prende l'app (lavoriTelefono), il server non li manda all'AI.
        if ($pratica->analisiSulTelefono()) {
            return \count($stato['daAnalizzare']);
        }
        foreach ($stato['daAnalizzare'] as $d) {
            $this->bus->dispatch(new AnalizzaDocumentoPraticaMessage((string) $pratica->getId(), (string) $d->getId()));
        }

        return \count($stato['daAnalizzare']);
    }

    /** "Non serve": un requisito per una persona, o un codice scoperto (un IBAN, una targa) con [dettaglio]. */
    /**
     * I prossimi documenti da analizzare sull'iPhone: istruzioni, prompt (con le
     * parti utili del testo entro [caratteri]) e schema delle risposte. Il testo
     * dei cifrati si decifra in memoria (c'è il consenso) e va solo al telefono.
     *
     * @return array{lavori: list<array<string, mixed>>, restano: int}
     */
    public function lavoriTelefono(Pratica $pratica, int $caratteri, int $limite): array
    {
        $modello = $this->modello($pratica);
        $stato = $this->candidati->stato($modello, $pratica);
        $lavori = [];
        foreach ($stato['daAnalizzare'] as $d) {
            if (\count($lavori) >= $limite) {
                break;
            }
            if (!$this->access->isGranted('documento:read', $d)) {
                continue;
            }
            $testo = (string) $this->testi->readOcrText($d);
            $requisiti = '' === trim($testo) ? [] : EstrattoreVoci::candidati($modello, $d->getCategoria()->getSlug(), $testo, $d->getNomeFileOriginale(), CandidatiPratica::vecchio($modello, $d));
            if ([] === $requisiti) {
                // Cifrato senza parole utili: niente da chiedere al telefono.
                $this->registraAnalisi($pratica, $d, PraticaAnalisi::NESSUNA, 'regole');
                continue;
            }
            $richiesta = AiVociSuggeritore::richiesta($requisiti, $d->getNomeFileOriginale(), AiVociSuggeritore::testoPerModello($requisiti, $testo, $caratteri));
            unset($testo);
            $lavori[] = [
                'documentoId' => (string) $d->getId(),
                'titolo' => $d->getTitolo(),
                'istruzioni' => AiVociSuggeritore::istruzioni($modello->titolo),
                'prompt' => $richiesta['prompt'],
                'schema' => json_encode($richiesta['schema'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            ];
        }

        return ['lavori' => $lavori, 'restano' => \count($stato['daAnalizzare'])];
    }

    /**
     * Risultato dal telefono: solo i valori letti dal modello, che si
     * ricontrollano qui sul testo con le stesse regole dell'analisi sul server.
     *
     * @param array<string, mixed> $dati {documentoId, risposta?: JSON del modello, modello?: string, errore?: string}
     *
     * @return int voci proposte
     */
    public function risultatoTelefono(Pratica $pratica, array $dati): int
    {
        $modello = $this->modello($pratica);
        $d = $this->documento($pratica->getTenant(), $dati['documentoId'] ?? null);
        if (null === $d || !CandidatiPratica::interessa($modello, $pratica, $d) || \in_array($d->getId()->toRfc4122(), $this->pratiche->documentiAnalizzati($pratica), true)) {
            throw new Conflitto('Questo documento non è da analizzare (o è già stato analizzato).');
        }
        $versione = mb_substr(preg_replace('/[^\w.\-]/', '', (string) ($dati['modello'] ?? 'apple')) ?: 'apple', 0, 11);
        if (\is_string($dati['errore'] ?? null) && '' !== $dati['errore']) {
            $this->registraAnalisi($pratica, $d, PraticaAnalisi::ERRORE, 'tel:'.$versione);

            return 0;
        }
        $risposta = \is_string($dati['risposta'] ?? null) ? json_decode($dati['risposta'], true) : ($dati['risposta'] ?? null);
        $testo = (string) $this->testi->readOcrText($d);
        $persone = $pratica->getPersone();
        $membri = array_values(array_filter($d->getMembri()->toArray(), static fn ($m) => \in_array($m, $persone, true)));
        $voci = $this->estrattore->daGrezze($modello, $d->getCategoria()->getSlug(), $testo, $persone, 1 === \count($membri) ? $membri[0] : null, AiVociSuggeritore::vociDaJson($risposta), $d->getNomeFileOriginale(), CandidatiPratica::vecchio($modello, $d));
        $voci = $this->doppioni->nuove($modello, $pratica, $voci);
        unset($testo);
        foreach ($voci as $v) {
            $voce = new PraticaVoce($pratica, $v['requisito'], $v['persona'], $d, VoceStato::PROPOSTA, VoceOrigine::AI_TELEFONO);
            $this->cifratura->cifra($voce, $v['valori']);
            $this->em->persist($voce);
        }
        $this->registraAnalisi($pratica, $d, [] === $voci ? PraticaAnalisi::NESSUNA : PraticaAnalisi::VOCI, 'tel:'.$versione);

        return \count($voci);
    }

    private function registraAnalisi(Pratica $pratica, Documento $d, string $esito, string $metodo): void
    {
        $this->em->persist(new PraticaAnalisi($pratica, $d, $esito, $metodo));
        $this->em->flush();
    }

    public function nonServe(Pratica $pratica, mixed $requisito, mixed $personaId, bool $nonServe, mixed $dettaglio = null): void
    {
        $r = $this->requisito($pratica, $requisito);
        if (\is_string($dettaglio) && '' !== $dettaglio) {
            if (null === $r->scopri || 1 !== preg_match('/^[0-9A-Z]{5,34}$/', $dettaglio)) {
                throw new DatiNonValidi('Codice non valido.');
            }
            $pratica->segnaNonServe($r->codice.':'.$dettaglio, null, $nonServe);
            $this->em->flush();

            return;
        }
        $persona = $r->perPersona ? $this->personaDellaPratica($pratica, $personaId) : null;
        if ($r->perPersona && null === $persona) {
            throw new DatiNonValidi('Indica la persona.');
        }
        $pratica->segnaNonServe($r->codice, $persona, $nonServe);
        $this->em->flush();
    }

    /**
     * Voce aggiunta a mano: nasce confermata, quindi servono i campi obbligatori.
     *
     * @param array<string, mixed> $dati {requisito, personaId?, documentoId?, valori: {...}}
     */
    public function creaVoce(Pratica $pratica, array $dati): PraticaVoce
    {
        $r = $this->requisito($pratica, $dati['requisito'] ?? null);
        $valori = ValoriVoce::normalizza($r, \is_array($dati['valori'] ?? null) ? $dati['valori'] : []);
        $this->confermabile($r, $valori);
        $voce = new PraticaVoce($pratica, $r->codice, $r->perPersona ? $this->personaDellaPratica($pratica, $dati['personaId'] ?? null) : null, $this->documento($pratica->getTenant(), $dati['documentoId'] ?? null), VoceStato::CONFERMATA, VoceOrigine::MANUALE);
        $this->cifratura->cifra($voce, $valori);
        $this->em->persist($voce);
        $this->em->flush();
        $this->log('pratica.voce_aggiunta', $pratica, ['requisito' => $r->codice]);

        return $voce;
    }

    /**
     * Correzione di una voce; con "conferma": true la conferma anche.
     *
     * @param array<string, mixed> $dati {requisito?, personaId?, documentoId?, valori?: {...}, conferma?}
     */
    public function modificaVoce(PraticaVoce $voce, array $dati): void
    {
        $pratica = $voce->getPratica();
        $r = \array_key_exists('requisito', $dati) ? $this->requisito($pratica, $dati['requisito']) : $this->requisito($pratica, $voce->getRequisito());
        $attuali = $this->cifratura->decifra($voce);
        $valori = ValoriVoce::normalizza($r, \is_array($dati['valori'] ?? null) ? $dati['valori'] : [], $attuali);
        if (isset($r->campi['tipo']) && null === $r->tipo($valori['tipo'] ?? null)) {
            $valori['tipo'] = (string) array_key_first($r->tipi);
        }
        $voce->setRequisito($r->codice);
        if (!$r->perPersona) {
            $voce->setPersona(null);
        } elseif (\array_key_exists('personaId', $dati)) {
            $voce->setPersona($this->personaDellaPratica($pratica, $dati['personaId']));
        }
        if (\array_key_exists('documentoId', $dati)) {
            $voce->setDocumento($this->documento($pratica->getTenant(), $dati['documentoId']));
        }
        if (true === ($dati['conferma'] ?? false)) {
            $this->confermabile($r, $valori);
            $voce->setStato(VoceStato::CONFERMATA);
        }
        $this->cifratura->cifra($voce, $valori);
        $voce->touchUpdatedAt();
        $this->em->flush();
    }

    public function statoVoce(PraticaVoce $voce, VoceStato $stato): void
    {
        if (VoceStato::CONFERMATA === $stato) {
            $this->confermabile($this->requisito($voce->getPratica(), $voce->getRequisito()), $this->cifratura->decifra($voce));
        }
        $voce->setStato($stato);
        $voce->touchUpdatedAt();
        $this->em->flush();
    }

    public function eliminaVoce(PraticaVoce $voce): void
    {
        $this->em->remove($voce);
        $this->em->flush();
    }

    /**
     * Voci della pratica che l'utente può vedere (quelle dei documenti che può
     * leggere, e quelle a mano), con i valori decifrati in memoria.
     *
     * @return list<array{voce: PraticaVoce, valori: array<string, mixed>}>
     */
    public function vociVisibili(Pratica $pratica): array
    {
        $out = [];
        foreach ($this->pratiche->voci($pratica) as $v) {
            if (null === $v->getDocumento() || $this->access->isGranted('documento:read', $v->getDocumento())) {
                $out[] = ['voce' => $v, 'valori' => $this->cifratura->decifra($v)];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $valori */
    private function confermabile(Requisito $r, array $valori): void
    {
        $mancano = ValoriVoce::mancanti($r, $valori);
        if ([] !== $mancano) {
            throw new DatiNonValidi('Per confermare manca: '.implode(', ', $mancano).'.');
        }
    }

    private function requisito(Pratica $pratica, mixed $codice): Requisito
    {
        return (\is_string($codice) ? $this->modello($pratica)->requisito($codice) : null) ?? throw new DatiNonValidi('Requisito non valido per questa pratica.');
    }

    /** @return list<MembroFamiglia> */
    private function persone(Tenant $tenant, mixed $ids): array
    {
        if (!\is_array($ids) || [] === $ids) {
            throw new DatiNonValidi('Scegli almeno una persona della famiglia.');
        }
        $persone = $this->membri->findAllInTenant($tenant, array_values(array_map('strval', array_filter($ids, 'is_string'))));
        if (null === $persone || \count($persone) !== \count(array_unique($ids))) {
            throw new DatiNonValidi('Una delle persone indicate non è nella famiglia.');
        }

        return $persone;
    }

    private function personaDellaPratica(Pratica $pratica, mixed $id): ?MembroFamiglia
    {
        if (null === $id || '' === $id) {
            return null;
        }
        foreach ($pratica->getPersone() as $p) {
            if ($p->getId()->toRfc4122() === $id) {
                return $p;
            }
        }
        throw new DatiNonValidi('La persona indicata non fa parte di questa pratica.');
    }

    private function documento(Tenant $tenant, mixed $id): ?Documento
    {
        if (null === $id || '' === $id) {
            return null;
        }
        $d = \is_string($id) ? $this->documenti->findOneInTenant($tenant, $id) : null;
        if (null === $d || !$this->access->isGranted('documento:read', $d)) {
            throw new DatiNonValidi('Il documento indicato non è stato trovato in questa famiglia.');
        }

        return $d;
    }

    /** @param array<string, mixed> $contesto */
    public function log(string $azione, Pratica $pratica, array $contesto = []): void
    {
        $this->audit->log($azione, AuditOutcome::SUCCESS, resourceType: 'pratica', resourceId: (string) $pratica->getId(), tenantId: $pratica->getTenant()->getId(), context: $contesto);
    }
}
