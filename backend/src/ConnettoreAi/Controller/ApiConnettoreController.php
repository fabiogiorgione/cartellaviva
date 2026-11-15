<?php

namespace App\ConnettoreAi\Controller;

use App\ConnettoreAi\Entity\ConnettoreAi;
use App\ConnettoreAi\LavoriAi;
use App\ConnettoreAi\Security\ConnettoreUser;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonConsentito;
use App\Shared\Errore\TroppiTentativi;
use App\Shared\Http\JsonBodyTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API del connettore AI (firewall "ai_connector", token del connettore).
 * Il server riceve solo i valori letti dal modello e li ricontrolla.
 */
final class ApiConnettoreController extends AbstractController
{
    use JsonBodyTrait;

    private const ID = ['id' => '[0-9a-fA-F-]{36}'];
    /** Al massimo una richiesta di lavori ogni tanti secondi per connettore. */
    private const PAUSA_LAVORI = 5;
    private const CORPO_MAX = 65536;

    public function __construct(
        private readonly LavoriAi $lavori,
        private readonly EntityManagerInterface $em,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/api/ai-connector-plugin/me', name: 'api_connettore_me', methods: ['PUT'])]
    public function me(Request $request): JsonResponse
    {
        $c = $this->connettore();
        $d = $this->body($request);
        $testo = fn (string $k, int $max) => $this->str($d, $k, $max);
        $numero = static fn (string $k) => \is_int($d[$k] ?? null) && $d[$k] > 0 && $d[$k] < 1000000 ? $d[$k] : null;
        $motore = $testo('motore', 10);
        $c->presenta([
            'versione' => $testo('versione', 20),
            'sistema' => $testo('sistema', 40),
            'chip' => $testo('chip', 40),
            'memoriaGb' => $numero('memoriaGb'),
            'motore' => \in_array($motore, ['ollama', 'apple'], true) ? $motore : null,
            'modello' => $testo('modello', 100),
            'digest' => $testo('digest', 80),
            'contesto' => $numero('contesto'),
        ]);
        $this->em->flush();

        return $this->json(['famiglia' => ['nome' => $c->getTenant()->getName()]] + $this->lavori->impostazioni());
    }

    #[Route('/api/ai-connector-plugin/lavori', name: 'api_connettore_lavori', methods: ['GET'])]
    public function lavori(Request $request): JsonResponse
    {
        $c = $this->connettore();
        $voce = $this->cache->getItem('connettore_lavori_'.$c->getId()->toRfc4122());
        if ($voce->isHit()) {
            throw new TroppiTentativi('Chiedi i lavori al massimo una volta ogni '.self::PAUSA_LAVORI.' secondi.');
        }
        $this->cache->save($voce->set(true)->expiresAfter(self::PAUSA_LAVORI));

        return $this->json($this->lavori->prendi($c, (int) $request->query->get('limite', '1'), (int) $request->query->get('caratteri', '6000')));
    }

    #[Route('/api/ai-connector-plugin/lavori/{id}/proroga', name: 'api_connettore_proroga', methods: ['POST'], requirements: self::ID)]
    public function proroga(string $id): JsonResponse
    {
        $this->lavori->proroga($this->connettore(), $id);

        return $this->json(['ok' => true]);
    }

    #[Route('/api/ai-connector-plugin/lavori/{id}/risultato', name: 'api_connettore_risultato', methods: ['POST'], requirements: self::ID)]
    public function risultato(Request $request, string $id): JsonResponse
    {
        if (\strlen($request->getContent()) > self::CORPO_MAX) {
            throw new DatiNonValidi('Risultato troppo grande.');
        }

        return $this->json(['voci' => $this->lavori->risultato($this->connettore(), $id, $this->body($request))]);
    }

    #[Route('/api/ai-connector-plugin/lavori/{id}/errore', name: 'api_connettore_errore', methods: ['POST'], requirements: self::ID)]
    public function errore(Request $request, string $id): JsonResponse
    {
        $this->lavori->errore($this->connettore(), $id, (string) ($this->body($request)['codice'] ?? ''));

        return $this->json(['ok' => true]);
    }

    private function connettore(): ConnettoreAi
    {
        $u = $this->getUser();
        if (!$u instanceof ConnettoreUser) {
            throw new NonConsentito('Serve il token del connettore.');
        }

        return $u->connettore;
    }
}
