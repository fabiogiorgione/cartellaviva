<?php

namespace App\Tenancy\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\User;
use App\Account\Repository\UserRepository;
use App\Shared\State\ValidationHelper;
use App\Tenancy\ApiResource\MembroFamigliaResource;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Repository\MembroFamigliaRepository;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<MembroFamigliaResource, MembroFamigliaResource|null>
 */
final class MembroFamigliaProcessor implements ProcessorInterface
{
    private const PATH_MAP = [
        'anagrafica.dataDiNascita' => 'dataNascita',
        'anagrafica.nazioneDiNascita' => 'nazioneNascita',
        'anagrafica.comuneDiNascita' => 'comuneNascita',
        'anagrafica.' => '',
        'userId' => 'userId',
    ];

    public function __construct(
        private readonly TenantAccess $access,
        private readonly MembroFamigliaRepository $membri,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly ValidationHelper $validation,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?MembroFamigliaResource
    {
        $actor = $this->access->currentUser();
        $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], $operation instanceof Post ? 'membro_famiglia:manage' : 'membro_famiglia:read');

        if ($operation instanceof Post) {
            \assert($data instanceof MembroFamigliaResource);
            $membro = new MembroFamiglia($tenant, $actor, new Anagrafica(Anagrafica::TYPEPRIVATO));
            $this->apply($membro, $data);
            $this->validation->validateOrFail($membro, ['Default', MembroFamiglia::VALIDATION_GROUP], self::PATH_MAP);
            $this->em->persist($membro);
            $this->em->flush();

            return MembroFamigliaResource::fromEntity($membro);
        }

        $membro = $this->membri->findOneInTenant($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Membro della famiglia non trovato.');
        $this->access->require('membro_famiglia:manage', $membro);

        if ($operation instanceof DeleteOperationInterface) {
            $this->em->remove($membro);
            $this->em->flush();

            return null;
        }

        \assert($data instanceof MembroFamigliaResource);
        $this->apply($membro, $data);
        $this->validation->validateOrFail($membro, ['Default', MembroFamiglia::VALIDATION_GROUP], self::PATH_MAP);
        $this->em->flush();

        return MembroFamigliaResource::fromEntity($membro);
    }

    private function apply(MembroFamiglia $membro, MembroFamigliaResource $data): void
    {
        $a = $membro->getAnagrafica();
        $a->setNome($this->clean($data->nome));
        $a->setCognome($this->clean($data->cognome));
        // 'm' o 'f'; si accettano anche le maiuscole.
        $sesso = $this->clean($data->sesso);
        $a->setSesso(null === $sesso ? null : strtolower($sesso));
        $a->setNazioneDiNascita($data->nazioneNascita);
        $a->setComuneDiNascita($data->comuneNascita);
        $a->setCodiceFiscale($this->clean($data->codiceFiscale));
        $a->setCodiceFiscaleManuale($data->codiceFiscaleManuale);
        $a->setDataDiNascita(null !== $data->dataNascita && '' !== $data->dataNascita
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $data->dataNascita) ?: throw new UnprocessableEntityHttpException('dataNascita: formato atteso AAAA-MM-GG'))
            : null);
        $membro->setRelazione($data->relazione);
        $membro->setUser($this->user($data->userId));
    }

    private function user(?string $id): ?User
    {
        if (null === $id || '' === $id) {
            return null;
        }

        return (Uuid::isValid($id) ? $this->users->find(Uuid::fromString($id)) : null)
            ?? throw new UnprocessableEntityHttpException('userId: account non trovato.');
    }

    private function clean(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : null;

        return '' === $value ? null : $value;
    }
}
