<?php

namespace App\ConnettoreAi;

use App\ConnettoreAi\Entity\ConnettoreAi;
use App\ConnettoreAi\Entity\LavoroAi;
use App\ConnettoreAi\Repository\ConnettoreAiRepository;
use App\ConnettoreAi\Repository\LavoroAiRepository;
use App\Documenti\Classificazione\AiCategoryClassifier;
use App\Documenti\Classificazione\ClassificazioneAutomatica;
use App\Documenti\Classificazione\ClassificazioneAutomaticaListener;
use App\Documenti\Classificazione\PropostaCategoria;
use App\Documenti\Entity\Documento;
use App\Documenti\Message\ClassificaDocumentoMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Classificazione dei documenti sul Mac della famiglia (docs/connettore-ai.md):
 * la classificazione automatica degli import e la proposta di categoria del wizard.
 *
 * Sono catene di domande al modello (scelta, descrizione della categoria,
 * verifica, categoria nuova) con le regole di ClassificazioneAutomatica e
 * PropostaCategoria. Ogni volta la catena si rifà da capo con
 * ClassificatoreSulMac: le domande già fatte hanno il loro risultato, la prima
 * senza risposta va al Mac come passo del lavoro. Arrivata la risposta si
 * riparte, finché la catena finisce. Anche i documenti cifrati: il testo si
 * decifra in memoria a ogni passo e va solo al Mac.
 */
class LavoriClassificazione
{
    public const TIPI = [LavoroAi::CLASSIFICAZIONE, LavoroAi::PROPOSTA_CATEGORIA];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LavoroAiRepository $lavori,
        private readonly ConnettoreAiRepository $connettori,
        private readonly AiCategoryClassifier $ai,
        private readonly ClassificazioneAutomatica $automatica,
        private readonly PropostaCategoria $proposta,
        private readonly MessageBusInterface $bus,
        #[Autowire(env: 'OLLAMA_MODEL')]
        private readonly string $modelloServer,
    ) {
    }

    /** La classificazione di un documento importato va al Mac? Sì se un Mac della famiglia è online. */
    public function instradaImport(Documento $d): bool
    {
        if (!$this->online($d)) {
            return false;
        }
        if (null === $this->lavori->perDocumento($d, LavoroAi::CLASSIFICAZIONE)) {
            $this->em->persist(new LavoroAi(null, $d, LavoroAi::CLASSIFICAZIONE));
            $this->em->flush();
        }

        return true;
    }

    /**
     * A che punto è la proposta di categoria del documento, per il wizard:
     * - ['esito' => …]: fatta dal Mac (il lavoro si cancella, l'esito si dà una volta);
     * - 'in_corso': il Mac ci sta lavorando;
     * - 'al_server': il Mac non l'ha fatta o non è più online, la fa il server;
     * - null: nessuna proposta in corso.
     *
     * @return array{esito: array<string, mixed>}|'in_corso'|'al_server'|null
     */
    public function proposta(Documento $d): array|string|null
    {
        $l = $this->lavori->perDocumento($d, LavoroAi::PROPOSTA_CATEGORIA);
        if (null === $l) {
            return null;
        }
        if (LavoroAi::PRONTO === $l->getStato()) {
            $esito = $l->getDati()['esito'] ?? null;
            $this->em->remove($l);
            $this->em->flush();

            return \is_array($esito) ? ['esito' => $esito] : 'al_server';
        }
        // Mac sparito a metà: inutile far aspettare il wizard fino al ritorno automatico.
        if (LavoroAi::AL_SERVER === $l->getStato() || (LavoroAi::IN_ATTESA === $l->getStato() && !$this->online($d))) {
            $this->em->remove($l);
            $this->em->flush();

            return 'al_server';
        }

        return 'in_corso';
    }

    /**
     * Comincia una proposta sul Mac, se un Mac della famiglia è online.
     *
     * @param list<string> $nuove
     * @param list<string> $escludi
     */
    public function avviaProposta(Documento $d, array $nuove, array $escludi): bool
    {
        if (!$this->online($d)) {
            return false;
        }
        $this->em->persist(new LavoroAi(null, $d, LavoroAi::PROPOSTA_CATEGORIA, ['nuove' => $nuove, 'escludi' => $escludi]));
        $this->em->flush();

        return true;
    }

    /**
     * La prossima domanda per il Mac, o null se la catena è finita senza bisogno
     * del Mac (il lavoro è già concluso: cancellato o pronto per il wizard).
     *
     * @return array{istruzioni: string, prompt: string, schema: string}|null
     */
    public function richiesta(LavoroAi $l): ?array
    {
        $c = $this->classificatore($l, null, null);
        try {
            $this->catena($l, $c);

            return null;
        } catch (InAttesaDelMac $e) {
            $this->passo($l, $e->chiave, $c);

            return $e->richiesta;
        }
    }

    /**
     * La risposta del Mac alla domanda del passo. Poi la catena va avanti: o
     * finisce, o il lavoro torna in attesa con la domanda successiva.
     *
     * @return bool false se la domanda nel frattempo è cambiata (la si rifà)
     */
    public function risultato(LavoroAi $l, ConnettoreAi $c, string $risposta, string $modello): bool
    {
        $classificatore = $this->classificatore($l, $risposta, $modello);
        try {
            $this->catena($l, $classificatore);

            return true;
        } catch (InAttesaDelMac $e) {
            $l->rimetti(false);
            $this->passo($l, $e->chiave, $classificatore, $modello);

            return $classificatore->rispostaUsata();
        }
    }

    /** Il Mac non ce la fa (errori, lease scaduti, Mac sparito): la classificazione torna al server. */
    public function rimandaAlServer(LavoroAi $l): void
    {
        if (LavoroAi::PROPOSTA_CATEGORIA === $l->getTipo()) {
            $l->alServer();

            return;
        }
        $id = (string) $l->getDocumento()->getId();
        $this->em->remove($l);
        $this->bus->dispatch(new ClassificaDocumentoMessage($id, forzaServer: true));
    }

    /** Proposte mai ritirate dal wizard (chiuso a metà): si cancellano dopo un'ora. */
    public function pulisci(\DateTimeImmutable $ora): int
    {
        $vecchie = $this->lavori->propostaNonRitirate($ora->modify('-1 hour'));
        foreach ($vecchie as $l) {
            $this->em->remove($l);
        }

        return \count($vecchie);
    }

    /** La catena dall'inizio. Finita: il lavoro si conclude (cancellato, o pronto con l'esito). */
    private function catena(LavoroAi $l, ClassificatoreSulMac $c): void
    {
        $d = $l->getDocumento();
        if (LavoroAi::CLASSIFICAZIONE === $l->getTipo()) {
            // Nel frattempo spostato a mano: non c'è più niente da classificare.
            if (ClassificazioneAutomaticaListener::isCandidate($d)) {
                $this->automatica->classifica($d, $c);
            }
            $this->em->remove($l);

            return;
        }
        $dati = $l->getDati();
        $esito = $this->proposta->proponiCon($c, $d, self::nomi($dati['nuove'] ?? []), self::nomi($dati['escludi'] ?? []));
        $l->pronto($esito);
    }

    private function classificatore(LavoroAi $l, ?string $risposta, ?string $modello): ClassificatoreSulMac
    {
        $dati = $l->getDati();
        $passo = $dati['passo'] ?? null;

        return new ClassificatoreSulMac(
            $this->ai,
            \is_array($dati['risposte'] ?? null) ? $dati['risposte'] : [],
            \is_string($passo) ? $passo : null,
            $risposta,
            $modello ?? (\is_string($dati['modello'] ?? null) ? $dati['modello'] : $this->modelloServer),
        );
    }

    private function passo(LavoroAi $l, string $chiave, ClassificatoreSulMac $c, ?string $modello = null): void
    {
        $dati = $l->getDati();
        $dati['passo'] = $chiave;
        $dati['risposte'] = $c->risposte();
        if (null !== $modello) {
            $dati['modello'] = $modello;
        }
        $l->setDati($dati);
    }

    private function online(Documento $d): bool
    {
        return null !== $this->connettori->online($d->getTenant(), new \DateTimeImmutable());
    }

    /** @return list<string> */
    private static function nomi(mixed $v): array
    {
        return \is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }
}
