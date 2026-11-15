<?php

namespace App\ConnettoreAi;

use App\ConnettoreAi\Entity\ConnettoreAi;
use App\ConnettoreAi\Entity\LavoroAi;
use App\ConnettoreAi\Repository\ConnettoreAiRepository;
use App\ConnettoreAi\Repository\LavoroAiRepository;
use App\Documenti\Entity\Documento;
use App\Pratiche\Entity\Pratica;
use App\Pratiche\Entity\PraticaAnalisi;
use App\Pratiche\Enum\VoceOrigine;
use App\Pratiche\Message\AnalizzaDocumentoPraticaMessage;
use App\Pratiche\PraticheService;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Coda dei lavori per i connettori (docs/connettore-ai.md): instradamento,
 * assegnazione con lease, risultato, errori e ritorno al server.
 */
class LavoriAi
{
    public const ERRORI = ['modello_mancante', 'timeout', 'memoria', 'risposta_non_valida', 'interrotto'];
    /** Dal secondo errore il lavoro va al server. */
    public const TENTATIVI_MAX = 2;

    /**
     * @param list<string> $modelliVerificati
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LavoroAiRepository $lavori,
        private readonly ConnettoreAiRepository $connettori,
        private readonly PraticheService $pratiche,
        private readonly MessageBusInterface $bus,
        #[Autowire('%app.connettore_ai.modelli_verificati%')]
        private readonly array $modelliVerificati,
        #[Autowire('%app.connettore_ai.sperimentali_ammessi%')]
        private readonly bool $sperimentaliAmmessi,
        #[Autowire('%app.connettore_ai.attesa_massima%')]
        private readonly int $attesaMassimaSecondi,
    ) {
    }

    /**
     * Il documento va a un connettore della famiglia? Sì se ce n'è uno online
     * e, per un documento cifrato, se la pratica ha il consenso per i dispositivi.
     * In quel caso crea il lavoro (una volta sola) e il server non chiama Ollama.
     */
    public function instrada(Pratica $pratica, Documento $d): bool
    {
        if ($pratica->analisiSulTelefono() || ($d->isCifrato() && !$pratica->haConsensoCifratiDispositivi())) {
            return false;
        }
        if (null === $this->connettori->online($pratica->getTenant(), new \DateTimeImmutable())) {
            return false;
        }
        if (null === $this->lavori->perPraticaDocumento($pratica, $d)) {
            $this->em->persist(new LavoroAi($pratica, $d));
            $this->em->flush();
        }

        return true;
    }

    /** @return array{intervalloSecondi: int, caratteriMax: int, modelliVerificati: list<string>, sperimentaliAmmessi: bool} */
    public function impostazioni(): array
    {
        return ['intervalloSecondi' => 20, 'caratteriMax' => 12000, 'modelliVerificati' => $this->modelliVerificati, 'sperimentaliAmmessi' => $this->sperimentaliAmmessi];
    }

    /**
     * Assegna fino a $limite lavori al connettore e li prepara (istruzioni,
     * prompt, schema in memoria). I lavori non più da fare si cancellano.
     *
     * @return array{lavori: list<array<string, mixed>>, restano: int}
     */
    public function prendi(ConnettoreAi $c, int $limite, int $caratteri): array
    {
        $caratteri = max(1500, min(12000, $caratteri));
        $ora = new \DateTimeImmutable();
        $presi = $this->em->wrapInTransaction(fn () => $this->lavori->prendi($c, $limite, $ora));
        $out = [];
        foreach ($presi as $l) {
            $p = $l->getPratica();
            $d = $l->getDocumento();
            if (!$this->pratiche->daAnalizzare($p, $d) || ($d->isCifrato() && !$p->haConsensoCifratiDispositivi())) {
                // Non più da fare qui (pratica chiusa, già analizzato, consenso revocato):
                // se serve ancora, ci pensa il server.
                $this->rimandaAlServer($l);
                continue;
            }
            $lavoro = $this->pratiche->lavoroPerDocumento($p, $d, $caratteri);
            if (null === $lavoro) {
                $this->em->remove($l);
                continue;
            }
            $out[] = [
                'id' => (string) $l->getId(),
                'tipo' => $l->getTipo(),
                'pratica' => $p->getModello().' '.$p->getAnno(),
                'documento' => $d->getTitolo(),
                'scadeIl' => $l->getScadeIl()?->format(\DATE_ATOM),
            ] + $lavoro;
        }
        $this->em->flush();

        return ['lavori' => $out, 'restano' => $this->lavori->inAttesa($c->getTenant())];
    }

    public function proroga(ConnettoreAi $c, string $id): void
    {
        $l = $this->suo($c, $id);
        $l->proroga(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $dati {risposta, modello, digest?, durataMs?, tokenPrompt?, tokenRisposta?}
     *
     * @return int voci proposte
     */
    public function risultato(ConnettoreAi $c, string $id, array $dati): int
    {
        $l = $this->suo($c, $id);
        $modello = \is_string($dati['modello'] ?? null) ? trim($dati['modello']) : '';
        if ('' === $modello) {
            throw new DatiNonValidi('Manca il modello usato.');
        }
        if (!$this->sperimentaliAmmessi && !\in_array($modello, $this->modelliVerificati, true)) {
            throw new Conflitto(sprintf('Il modello "%s" non è tra quelli verificati: usa %s.', mb_substr($modello, 0, 60), implode(', ', $this->modelliVerificati)));
        }
        if (!\is_string($dati['risposta'] ?? null) || \strlen($dati['risposta']) > 60000) {
            throw new DatiNonValidi('Risposta del modello mancante o troppo lunga.');
        }
        $p = $l->getPratica();
        $d = $l->getDocumento();
        if (!$this->pratiche->daAnalizzare($p, $d)) {
            $this->em->remove($l);
            $this->em->flush();
            throw new Conflitto('Questo documento non è più da analizzare (o è già stato analizzato).');
        }
        $durata = \is_int($dati['durataMs'] ?? null) && $dati['durataMs'] > 0 ? $dati['durataMs'] : null;
        $n = $this->pratiche->risultatoDispositivo($p, $d, $dati['risposta'], VoceOrigine::AI_CONNETTORE, self::metodo($modello), [
            'modello' => $modello,
            'durataMs' => $durata,
            'connettore' => $c,
        ]);
        $c->lavoroFatto($durata);
        $this->em->remove($l);
        $this->em->flush();

        return $n;
    }

    /** Errore sul Mac (solo il codice, mai testo): il lavoro torna in attesa, dal secondo va al server. */
    public function errore(ConnettoreAi $c, string $id, string $codice): void
    {
        if (!\in_array($codice, self::ERRORI, true)) {
            throw new DatiNonValidi('Codice d\'errore sconosciuto.');
        }
        $l = $this->suo($c, $id);
        $l->rimetti();
        if ($l->getTentativi() >= self::TENTATIVI_MAX) {
            $this->rimandaAlServer($l);
        }
        $this->em->flush();
    }

    /**
     * Attività pianificata: lease scaduti rimessi in attesa; lavori fermi da
     * troppo (nessun Mac online) o falliti due volte rimandati al server.
     *
     * @return array{rimessi: int, alServer: int}
     */
    public function riprendi(): array
    {
        $ora = new \DateTimeImmutable();
        $rimessi = 0;
        foreach ($this->lavori->scaduti($ora) as $l) {
            $l->rimetti();
            ++$rimessi;
        }
        $this->em->flush();
        $alServer = 0;
        $online = [];
        foreach ($this->lavori->tuttiInAttesa() as $l) {
            $t = $l->getTenant()->getId()->toRfc4122();
            $online[$t] ??= null !== $this->connettori->online($l->getTenant(), $ora);
            $fermo = $l->getCreatoIl() < $ora->modify('-'.$this->attesaMassimaSecondi.' seconds') && !$online[$t];
            if ($fermo || $l->getTentativi() >= self::TENTATIVI_MAX) {
                $this->rimandaAlServer($l);
                ++$alServer;
            }
        }
        $this->em->flush();

        return ['rimessi' => $rimessi, 'alServer' => $alServer];
    }

    /** Consenso per i dispositivi tolto: i lavori sui documenti cifrati della pratica tornano al server. */
    public function rimandaCifrati(Pratica $pratica): void
    {
        foreach ($this->lavori->findBy(['pratica' => $pratica]) as $l) {
            if ($l->getDocumento()->isCifrato()) {
                $this->rimandaAlServer($l);
            }
        }
        $this->em->flush();
    }

    /** "mac:" e il nome breve del modello, entro i 20 caratteri di pratica_analisi.metodo. */
    public static function metodo(string $modello): string
    {
        $breve = (string) preg_replace('/[^a-z0-9.\-]/', '', strtolower(explode(':', $modello)[0].'-'.(explode('-', explode(':', $modello)[1] ?? '')[0])));

        return mb_substr('mac:'.trim($breve, '-'), 0, 20);
    }

    private function suo(ConnettoreAi $c, string $id): LavoroAi
    {
        $l = $this->lavori->find($id);
        if (null === $l || !$l->diConnettore($c, new \DateTimeImmutable())) {
            throw new Conflitto('Questo lavoro non è più tuo (scaduto o assegnato altrove).');
        }

        return $l;
    }

    private function rimandaAlServer(LavoroAi $l): void
    {
        $p = (string) $l->getPratica()->getId();
        $d = (string) $l->getDocumento()->getId();
        $this->em->remove($l);
        $this->bus->dispatch(new AnalizzaDocumentoPraticaMessage($p, $d, forzaServer: true));
    }
}
