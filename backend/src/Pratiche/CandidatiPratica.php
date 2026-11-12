<?php

namespace App\Pratiche;

use App\Documenti\Entity\Documento;
use App\Documenti\Enum\OcrStatus;
use App\Pratiche\Entity\Pratica;
use App\Pratiche\Modello\ModelloPratica;
use App\Pratiche\Repository\PraticaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Quali documenti della famiglia guardare per una pratica.
 *
 * - Finestra (ModelloPratica::finestra): dal 1° gennaio dell'anno di
 *   riferimento al 30 giugno dopo (la CU arriva a marzo) o a fine anno della
 *   pratica se è più tardi (ISEE), o senza data e caricati da allora; solo
 *   con l'OCR completato.
 * - In chiaro: parole chiave di un requisito nel testo (la categoria da sola no).
 * - Cifrati: solo con il consenso della pratica, e solo nelle categorie dei
 *   requisiti o "Da categorizzare" (le parole chiave si controllano dopo,
 *   leggendo il testo in memoria). Senza consenso si contano e basta.
 */
class CandidatiPratica
{
    private const SEMPRE_CIFRATI = ['da_categorizzare'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PraticaRepository $pratiche,
    ) {
    }

    /** @return list<string> slug delle categorie che interessano al modello */
    public static function categorie(ModelloPratica $modello): array
    {
        $slug = [];
        foreach ($modello->requisiti as $r) {
            array_push($slug, ...$r->categorie);
        }

        return array_values(array_unique($slug));
    }

    /** Il documento è da guardare per questa pratica? (senza decifrare nulla) */
    public static function interessa(ModelloPratica $modello, Pratica $pratica, Documento $d): bool
    {
        if (OcrStatus::COMPLETATO !== $d->getOcrStatus() || !self::nellaFinestra($modello, $d)) {
            return false;
        }
        $slug = $d->getCategoria()->getSlug();
        if ($d->isCifrato()) {
            return $pratica->haConsensoCifrati() && \in_array($slug, [...self::categorie($modello), ...self::SEMPRE_CIFRATI], true);
        }
        // In chiaro serve una parola chiave nel testo: la sola categoria (es. Casa,
        // con centinaia di documenti) manderebbe all'AI troppi documenti inutili.
        $basso = mb_strtolower((string) $d->getOcrText());
        foreach ($modello->requisiti as $r) {
            if ($r->parlaDi($basso)) {
                return true;
            }
        }

        return false;
    }

    public static function nellaFinestra(ModelloPratica $modello, Documento $d): bool
    {
        [$da, $a] = $modello->finestra();
        $data = $d->getDataDocumento();
        if (null !== $data) {
            return $data >= $da && $data <= $a;
        }

        return $d->getCreatedAt() >= $da;
    }

    /**
     * Codici da completare trovati nei documenti in chiaro che parlano del
     * requisito: IBAN dei conti (negli estratti conto, non nelle bollette, dove
     * l'IBAN è del fornitore) e targhe dei veicoli.
     *
     * @return array<string, list<string>> requisito => codici
     */
    public function scoperti(ModelloPratica $modello, Pratica $pratica): array
    {
        $daScoprire = array_filter($modello->requisiti, static fn ($r) => null !== $r->scopri);
        if ([] === $daScoprire) {
            return [];
        }
        $out = [];
        foreach ($this->finestra($modello, $pratica) as $d) {
            if ($d->isCifrato()) {
                continue;
            }
            $testo = (string) $d->getOcrText();
            $basso = mb_strtolower($testo);
            foreach ($daScoprire as $r) {
                if (!$r->parlaDi($basso) && !\in_array($d->getCategoria()->getSlug(), $r->categorie, true)) {
                    continue;
                }
                foreach ('iban' === $r->scopri ? TestoVoci::iban($testo) : TestoVoci::targhe($testo) as $codice) {
                    $out[$r->codice][$codice] = true;
                }
            }
        }

        return array_map(static fn (array $c) => array_keys($c), $out);
    }

    /**
     * @return array{daAnalizzare: list<Documento>, analizzati: int, cifratiInAttesa: int}
     */
    public function stato(ModelloPratica $modello, Pratica $pratica): array
    {
        $analizzati = array_flip($this->pratiche->documentiAnalizzati($pratica));
        $daAnalizzare = [];
        $fatti = 0;
        $inAttesa = 0;
        $categorie = [...self::categorie($modello), ...self::SEMPRE_CIFRATI];
        foreach ($this->finestra($modello, $pratica) as $d) {
            if ($d->isCifrato() && !$pratica->haConsensoCifrati()) {
                if (\in_array($d->getCategoria()->getSlug(), $categorie, true)) {
                    ++$inAttesa;
                }
                continue;
            }
            if (!self::interessa($modello, $pratica, $d)) {
                continue;
            }
            if (isset($analizzati[$d->getId()->toRfc4122()])) {
                ++$fatti;
            } else {
                $daAnalizzare[] = $d;
            }
        }

        return ['daAnalizzare' => $daAnalizzare, 'analizzati' => $fatti, 'cifratiInAttesa' => $inAttesa];
    }

    /** @return list<Documento> documenti del tenant nella finestra, con l'OCR completato */
    private function finestra(ModelloPratica $modello, Pratica $pratica): array
    {
        [$da, $a] = $modello->finestra();

        return $this->em->createQueryBuilder()
            ->select('d', 'c')
            ->from(Documento::class, 'd')
            ->join('d.categoria', 'c')
            ->andWhere('d.tenant = :t')
            ->andWhere('d.ocrStatus = :ocr')
            ->andWhere('(d.dataDocumento BETWEEN :da AND :a) OR (d.dataDocumento IS NULL AND d.createdAt >= :caricato)')
            ->setParameter('t', $pratica->getTenant()->getId(), 'uuid')
            ->setParameter('ocr', OcrStatus::COMPLETATO)
            ->setParameter('da', $da, 'date_immutable')
            ->setParameter('a', $a, 'date_immutable')
            ->setParameter('caricato', $da)
            ->orderBy('d.createdAt', 'ASC')
            ->setMaxResults(3000)
            ->getQuery()
            ->getResult();
    }
}
