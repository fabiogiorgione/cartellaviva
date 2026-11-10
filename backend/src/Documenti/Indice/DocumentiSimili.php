<?php

namespace App\Documenti\Indice;

use App\Documenti\Entity\Documento;
use App\Documenti\Repository\DocumentoRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Documenti simili a uno dato, nello stesso tenant:
 * 1. prima quelli con un riferimento in comune (stessa targa, stesso POD, stessa polizza);
 * 2. poi quelli vicini per contenuto (vettori), entro una distanza massima.
 * Solo documenti non cifrati (gli unici indicizzati) e solo quelli che l'utente
 * può leggere: il permesso si verifica documento per documento.
 */
final class DocumentiSimili
{
    public function __construct(
        private readonly IndiceDocumenti $indice,
        private readonly DocumentoRepository $documenti,
        private readonly TenantAccess $access,
        #[Autowire('%app.simili_distanza_max%')]
        private readonly float $distanzaMax,
    ) {
    }

    /**
     * @return list<array{documento: Documento, motivo: 'riferimento'|'contenuto', riferimento: ?string}>
     */
    public function trova(Documento $documento, int $limite = 8): array
    {
        if ($documento->isCifrato()) {
            return [];
        }
        $riferimenti = $this->indice->leggi($documento)['riferimenti'] ?? [];
        $candidati = [];
        foreach ($this->indice->conRiferimenti($documento, $riferimenti, $limite) as $r) {
            $candidati[$r['id']] = ['motivo' => 'riferimento', 'riferimento' => $r['riferimento']];
        }
        foreach ($this->indice->vicini($documento, $limite) as $v) {
            if ($v['distanza'] <= $this->distanzaMax) {
                $candidati[$v['id']] ??= ['motivo' => 'contenuto', 'riferimento' => null];
            }
        }

        $out = [];
        foreach ($candidati as $id => $info) {
            $altro = $this->documenti->findOneInTenant($documento->getTenant(), $id);
            // Ricontrollo: nel frattempo può essere stato cifrato, o l'utente può non poterlo leggere.
            if (null === $altro || $altro->isCifrato() || !$this->access->isGranted('documento:read', $altro)) {
                continue;
            }
            $out[] = ['documento' => $altro, 'motivo' => $info['motivo'], 'riferimento' => $info['riferimento']];
            if (\count($out) >= $limite) {
                break;
            }
        }

        return $out;
    }
}
