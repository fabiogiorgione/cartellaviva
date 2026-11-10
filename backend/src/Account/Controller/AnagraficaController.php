<?php

namespace App\Account\Controller;

use App\Account\Anagrafica\AnagraficaMapper;
use App\Account\Anagrafica\CodiceFiscaleService;
use App\Account\Entity\Anagrafica\Address;
use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\TenantProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Anagrafica dell'utente corrente (privato o azienda) e onboarding.
 *
 *   GET  /api/me/anagrafica                  -> anagrafica (null se non compilata)
 *   PUT  /api/me/anagrafica                  -> salva; 422 con le violazioni per campo
 *   GET  /api/anagrafica/options             -> valori ammessi (sesso, stato civile, documenti...)
 *   POST /api/anagrafica/codice-fiscale      -> calcola il CF dai dati anagrafici
 *
 * Il primo salvataggio valido completa il profilo; se l'utente non appartiene
 * ancora a nessun tenant (es. nuovo account Google) crea il suo spazio famiglia.
 * I dati sono personali: li legge e modifica solo il titolare, nessun permesso
 * di tenant (né il superadmin) dà accesso.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AnagraficaController extends AbstractController
{
    public function __construct(private readonly AnagraficaMapper $mapper)
    {
    }

    #[Route('/api/me/anagrafica', name: 'api_me_anagrafica_get', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        $anagrafica = $user->getAnagrafica();

        return $this->json([
            'profileCompleted' => $user->isProfileCompleted(),
            'anagrafica' => null === $anagrafica ? null : $this->mapper->toArray($anagrafica),
        ]);
    }

    #[Route('/api/me/anagrafica', name: 'api_me_anagrafica_put', methods: ['PUT'])]
    public function save(
        #[CurrentUser] User $user,
        Request $request,
        ValidatorInterface $validator,
        EntityManagerInterface $em,
        MembershipRepository $memberships,
        TenantProvisioner $provisioner,
        AuditLogger $audit,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new UnprocessableEntityHttpException('Payload JSON non valido.');
        }

        $isNew = null === $user->getAnagrafica();
        $anagrafica = $user->getAnagrafica() ?? new Anagrafica((string) ($data['type'] ?? Anagrafica::TYPEPRIVATO));
        $this->mapper->apply($anagrafica, $data);

        $violations = $validator->validate($anagrafica, null, ['Default', $anagrafica->getType()]);
        if (\count($violations) > 0) {
            $em->clear(); // nessuna modifica parziale resta nella unit of work
            $audit->log('anagrafica.updated', AuditOutcome::FAILURE, resourceType: 'anagrafica', context: ['violations' => \count($violations)], actor: $user);
            throw HttpException::fromStatusCode(422, 'Controlla i dati inseriti.', new ValidationFailedException($anagrafica, $violations));
        }

        if ($isNew) {
            $em->persist($anagrafica);
            $user->setAnagrafica($anagrafica);
        }
        // Nome e cognome dell'account seguono l'anagrafica (per le aziende: legale rappresentante).
        $user->rename((string) $anagrafica->getNome(), (string) $anagrafica->getCognome());
        $firstCompletion = !$user->isProfileCompleted();
        $user->markProfileCompleted();
        $em->flush();

        // Solo per i privati: la prima anagrafica crea la famiglia. Un'anagrafica
        // azienda non è una famiglia, e gli enti hanno una registrazione propria.
        $tenantId = null;
        if ($firstCompletion && !$anagrafica->isAzienda() && [] === $memberships->findBy(['user' => $user]) && ($data['createTenant'] ?? true)) {
            $name = trim((string) ($data['tenantName'] ?? '')) ?: 'Famiglia '.$anagrafica->getCognome();
            $tenantId = (string) $provisioner->createWithOwner($user, $name, TenantType::PRIVATO)->getTenant()->getId();
        }

        $audit->log('anagrafica.updated', AuditOutcome::SUCCESS, resourceType: 'anagrafica', resourceId: (string) $anagrafica->getId(),
            context: ['type' => $anagrafica->getType(), 'first_completion' => $firstCompletion], actor: $user);

        return $this->json([
            'profileCompleted' => true,
            'createdTenantId' => $tenantId,
            'anagrafica' => $this->mapper->toArray($anagrafica),
        ]);
    }

    #[Route('/api/anagrafica/options', name: 'api_anagrafica_options', methods: ['GET'])]
    public function options(): JsonResponse
    {
        $list = static fn (array $options) => array_map(static fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys($options), $options);

        return $this->json([
            'type' => $list(Anagrafica::TYPE_OPTIONS),
            'sesso' => $list(Anagrafica::SESSO_OPTIONS),
            'statoCivile' => $list(Anagrafica::STATOCIVILE_OPTIONS),
            'tipoDocumentoIdentita' => $list(Anagrafica::DOCUMENTO_IDENTITA_OPTIONS),
            'addressType' => $list([Address::TYPE_RESIDENZA => 'Residenza / sede legale', Address::TYPE_DOMICILIO => 'Domicilio / sede operativa']),
        ]);
    }

    #[Route('/api/anagrafica/codice-fiscale', name: 'api_anagrafica_cf', methods: ['POST'])]
    public function calcolaCodiceFiscale(Request $request, CodiceFiscaleService $cf): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $probe = new Anagrafica();
        $this->mapper->apply($probe, \is_array($data) ? $data : []);

        $registryCode = $cf->getRegistryCode($probe);
        if (!$probe->getNome() || !$probe->getCognome() || !$probe->getDataDiNascita() || !$probe->getSesso() || !$registryCode) {
            throw new UnprocessableEntityHttpException('Servono nome, cognome, sesso, data e luogo di nascita.');
        }

        return $this->json([
            'codiceFiscale' => $cf->calculate($probe->getNome(), $probe->getCognome(), $probe->getDataDiNascita(), $probe->getSesso(), $registryCode),
        ]);
    }
}
