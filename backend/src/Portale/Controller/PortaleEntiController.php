<?php

namespace App\Portale\Controller;

use App\Ente\EnteJson;
use App\Ente\EnteRegistrazione;
use App\Ente\Entity\EnteProfilo;
use App\Ente\Enum\EnteStato;
use App\Portale\PlatformAccess;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Portale: verifica degli enti. Il supporto vede gli enti e può chiedere
 * integrazioni; approvare o respingere spetta al superadmin.
 */
final class PortaleEntiController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EnteRegistrazione $registrazione,
        private readonly MembershipRepository $memberships,
        private readonly PlatformAccess $platform,
    ) {
    }

    #[Route('/api/admin/enti', name: 'api_admin_enti', methods: ['GET'])]
    public function elenco(Request $request): JsonResponse
    {
        $this->platform->require(PlatformAccess::SUPPORTO);
        $stato = EnteStato::tryFrom((string) $request->query->get('stato', ''));
        $criteria = null !== $stato ? ['stato' => $stato] : [];
        $profili = $this->em->getRepository(EnteProfilo::class)->findBy($criteria, ['createdAt' => 'ASC']);

        return $this->json(array_map(fn (EnteProfilo $p) => $this->dettaglio($p), $profili));
    }

    #[Route('/api/admin/enti/{tenantId}/decisione', name: 'api_admin_ente_decisione', methods: ['POST'])]
    public function decisione(string $tenantId, Request $request): JsonResponse
    {
        // Il supporto prepara la verifica (controlli e richiesta di integrazioni);
        // approvare o respingere spetta solo al superadmin.
        $azione = (string) ($this->body($request)['azione'] ?? '');
        $user = $this->platform->require('integrazioni' === $azione ? PlatformAccess::SUPPORTO : PlatformAccess::ADMIN);
        $profilo = Uuid::isValid($tenantId) ? $this->em->getRepository(EnteProfilo::class)->findOneBy(['tenant' => Uuid::fromString($tenantId)]) : null;
        if (null === $profilo) {
            throw new NotFoundHttpException('Ente non trovato.');
        }
        $body = $this->body($request);
        $this->registrazione->decidi($profilo, (string) ($body['azione'] ?? ''), $this->str($body, 'nota', 1000), \is_array($body['controlli'] ?? null) ? $body['controlli'] : [], $user);

        return $this->json($this->dettaglio($profilo));
    }

    /** @return array<string, mixed> */
    private function dettaglio(EnteProfilo $p): array
    {
        $referente = null;
        foreach ($this->memberships->findForTenant($p->getTenant()) as $m) {
            \assert($m instanceof Membership);
            if ($m->getRole()->isOwnerRole()) {
                $referente = ['nome' => $m->getUser()->getFullName(), 'email' => $m->getUser()->getEmail()];
                break;
            }
        }

        return EnteJson::profilo($p) + ['controlli' => $p->getControlli(), 'referente' => $referente];
    }
}
