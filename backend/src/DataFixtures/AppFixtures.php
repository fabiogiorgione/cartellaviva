<?php

namespace App\DataFixtures;

use App\Account\Anagrafica\CodiceFiscaleService;
use App\Account\Anagrafica\GeoImporter;
use App\Account\Entity\Anagrafica\Address;
use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\Anagrafica\Email;
use App\Account\Entity\Anagrafica\Telefono;
use App\Account\Entity\Geo\GeoComune;
use App\Account\Entity\Geo\GeoCountry;
use App\Account\Entity\User;
use App\Documenti\CategorieSynchronizer;
use App\Ente\EnteRegistrazione;
use App\Ente\Entity\AnnoEnte;
use App\Ente\Entity\Collegamento;
use App\Ente\Entity\EnteProfilo;
use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Iscritto;
use App\Ente\Entity\Referente;
use App\Ente\Enum\EnteStato;
use App\Ente\Enum\EnteTipo;
use App\Ente\Enum\ReferenteStato;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Permission\DefaultRolesSynchronizer;
use App\Tenancy\Permission\PermissionSynchronizer;
use App\Tenancy\Repository\RoleRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Dati di sviluppo. Password di tutti gli utenti: "CartellaViva!2026".
 *
 *   admin@cartellaviva.local   superadmin (nessuna membership)
 *   mario.rossi@example.com   owner  di "Famiglia Rossi"
 *   giulia.rossi@example.com  member di "Famiglia Rossi"
 *   nonno.rossi@example.com   viewer di "Famiglia Rossi"
 *   luca.bianchi@example.com  owner  di "Famiglia Bianchi" (nessun accesso ai Rossi)
 *   nuovo.utente@example.com  nessun tenant, anagrafica da compilare (prova l'onboarding)
 *   segreteria@scuola.example.org      proprietaria (segreteria) di "Scuola Primaria Demo", ente verificato
 *   maestra.bianchi@scuola.example.org operatrice della 3ªB
 *
 * Nella scuola: Leo Rossi (3ªB) è già collegato alla Famiglia Rossi tramite Mario.
 * In "Famiglia Rossi" c'è anche Leo, figlio minorenne SENZA account (MembroFamiglia).
 *
 * Tutti tranne l'ultimo hanno un'anagrafica completa con codice fiscale calcolato.
 */
final class AppFixtures extends Fixture
{
    public const PASSWORD = 'CartellaViva!2026';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PermissionSynchronizer $permissionSynchronizer,
        private readonly DefaultRolesSynchronizer $rolesSynchronizer,
        private readonly RoleRepository $roles,
        private readonly GeoImporter $geoImporter,
        private readonly CodiceFiscaleService $codiceFiscale,
        private readonly CategorieSynchronizer $categorieSynchronizer,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // doctrine:fixtures:load svuota il DB: catalogo, ruoli e dati geografici vanno ricreati.
        $this->geoImporter->import();
        $this->permissionSynchronizer->synchronize();
        $this->rolesSynchronizer->synchronize();
        $this->categorieSynchronizer->synchronize();

        $milano = $manager->getRepository(GeoComune::class)->findOneBy(['registryCode' => 'F205', 'status' => GeoComune::STATUS_ACTIVE]);
        $roma = $manager->getRepository(GeoComune::class)->findOneBy(['registryCode' => 'H501', 'status' => GeoComune::STATUS_ACTIVE]);

        $admin = $this->user($manager, 'admin@cartellaviva.local', 'Admin', 'CartellaViva');
        $admin->setSuperAdmin(true);
        $this->anagrafica($manager, $admin, 'Andrea', 'Amministratore', 'm', '1988-02-11', $milano);

        $mario = $this->user($manager, 'mario.rossi@example.com', 'Mario', 'Rossi');
        $this->anagrafica($manager, $mario, 'Mario', 'Rossi', 'm', '1980-05-10', $milano);
        $giulia = $this->user($manager, 'giulia.rossi@example.com', 'Giulia', 'Rossi');
        $this->anagrafica($manager, $giulia, 'Giulia', 'Rossi', 'f', '1982-03-15', $milano);
        $nonno = $this->user($manager, 'nonno.rossi@example.com', 'Giuseppe', 'Rossi');
        $this->anagrafica($manager, $nonno, 'Giuseppe', 'Rossi', 'm', '1950-01-20', $milano);
        $luca = $this->user($manager, 'luca.bianchi@example.com', 'Luca', 'Bianchi');
        $this->anagrafica($manager, $luca, 'Luca', 'Bianchi', 'm', '1985-07-01', $roma);
        $this->user($manager, 'nuovo.utente@example.com', 'Nuovo', 'Utente');

        $rossi = new Tenant('Famiglia Rossi', TenantType::PRIVATO);
        $bianchi = new Tenant('Famiglia Bianchi', TenantType::PRIVATO);
        $manager->persist($rossi);
        $manager->persist($bianchi);

        $manager->persist(new Membership($mario, $rossi, $this->roles->getSystemRole(Role::OWNER)));
        $manager->persist(new Membership($giulia, $rossi, $this->roles->getSystemRole(Role::MEMBER), $mario));
        $manager->persist(new Membership($nonno, $rossi, $this->roles->getSystemRole(Role::VIEWER), $mario));
        $manager->persist(new Membership($luca, $bianchi, $this->roles->getSystemRole(Role::OWNER)));
        $manager->flush();

        // Figlio minorenne senza account, a cui collegare i documenti.
        $figlio = new Anagrafica(Anagrafica::TYPEPRIVATO);
        $figlio->setNome('Leo');
        $figlio->setCognome('Rossi');
        $figlio->setSesso('m');
        $figlio->setDataDiNascita(new \DateTimeImmutable('2017-09-03'));
        $membro = new MembroFamiglia($rossi, $mario, $figlio);
        $membro->setRelazione('figlio');
        $manager->persist($membro);
        $manager->flush();

        $this->enteDemo($manager, $admin, $mario, $rossi, $membro);
    }

    /**
     * Ente di demo già verificato dal portale: segreteria (proprietaria), una
     * maestra operatrice della 3ªB, iscritti e referenti. Leo Rossi è già
     * collegato alla "Famiglia Rossi" tramite Mario; gli altri referenti sono
     * da invitare (Paola Verdi non ha un account: prova la risposta dal link).
     */
    private function enteDemo(ObjectManager $manager, User $admin, User $mario, Tenant $famigliaRossi, MembroFamiglia $leoInFamiglia): void
    {
        $segreteria = $this->user($manager, 'segreteria@scuola.example.org', 'Silvia', 'Conti');
        $this->anagrafica($manager, $segreteria, 'Silvia', 'Conti', 'f', '1975-11-22', $manager->getRepository(GeoComune::class)->findOneBy(['registryCode' => 'F205', 'status' => GeoComune::STATUS_ACTIVE]));
        $maestra = $this->user($manager, 'maestra.bianchi@scuola.example.org', 'Laura', 'Bianchi');
        $this->anagrafica($manager, $maestra, 'Laura', 'Bianchi', 'f', '1984-04-09', $manager->getRepository(GeoComune::class)->findOneBy(['registryCode' => 'F205', 'status' => GeoComune::STATUS_ACTIVE]));

        $scuola = new Tenant('Scuola Primaria Demo', TenantType::AZIENDA);
        $manager->persist($scuola);
        $manager->persist(new Membership($segreteria, $scuola, $this->roles->getSystemRole(Role::OWNER)));
        $mMaestra = new Membership($maestra, $scuola, $this->roles->getSystemRole(Role::STAFF), $segreteria);
        $manager->persist($mMaestra);

        $profilo = new EnteProfilo($scuola, EnteTipo::SCUOLA, '80012345676', 'segreteria@scuola.example.org');
        $profilo->aggiornaDati('80012345676', 'MIEE000001', null, 'segreteria@scuola.example.org', 'Via delle Scuole 1, 20100 Milano');
        $profilo->decidi(EnteStato::VERIFICATO, $admin, 'Ente di demo per lo sviluppo.', ['registro' => true, 'email' => true]);
        $manager->persist($profilo);

        $anno = new AnnoEnte($scuola, EnteRegistrazione::annoCorrente(new \DateTimeImmutable()));
        $manager->persist($anno);
        $terzaB = new Gruppo($scuola, $anno, '3ªB');
        $terzaB->replaceOperatori([$mMaestra]);
        $quartaA = new Gruppo($scuola, $anno, '4ªA');
        $manager->persist($terzaB);
        $manager->persist($quartaA);

        // Iscritti: [nome, cognome, nascita, gruppo, referenti [nome, cognome, email, relazione]]
        $iscritti = [
            ['Leo', 'Rossi', '2017-09-03', $terzaB, [['Mario', 'Rossi', 'mario.rossi@example.com', 'padre'], ['Giulia', 'Rossi', 'giulia.rossi@example.com', 'madre']]],
            ['Sara', 'Bianchi', '2017-05-12', $terzaB, [['Luca', 'Bianchi', 'luca.bianchi@example.com', 'padre']]],
            ['Omar', 'Haddad', '2017-02-28', $terzaB, [['Karim', 'Haddad', null, 'padre']]],
            ['Anna', 'Verdi', '2016-10-30', $quartaA, [['Paola', 'Verdi', 'paola.verdi@example.com', 'madre']]],
        ];
        foreach ($iscritti as [$nome, $cognome, $nascita, $gruppo, $referenti]) {
            $iscritto = new Iscritto($scuola, $nome, $cognome, new \DateTimeImmutable($nascita));
            $iscritto->iscriviA($gruppo);
            $manager->persist($iscritto);
            foreach ($referenti as [$rn, $rc, $email, $relazione]) {
                $referente = new Referente($iscritto, $rn, $rc, $email, $relazione);
                $iscritto->aggiungiReferente($referente);
                $manager->persist($referente);
                if ('mario.rossi@example.com' === $email) {
                    $manager->persist(new Collegamento($famigliaRossi, $referente, $leoInFamiglia, $mario));
                    $referente->concludiInvito(ReferenteStato::COLLEGATO);
                }
            }
        }
        $manager->flush();
    }

    private function user(ObjectManager $manager, string $email, string $firstName, string $lastName): User
    {
        $user = new User($email, $firstName, $lastName);
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $user->segnaEmailVerificata();
        $manager->persist($user);

        return $user;
    }

    private function anagrafica(ObjectManager $manager, User $user, string $nome, string $cognome, string $sesso, string $nascita, GeoComune $comune): void
    {
        $a = new Anagrafica(Anagrafica::TYPEPRIVATO);
        $a->setNome($nome);
        $a->setCognome($cognome);
        $a->setSesso($sesso);
        $a->setDataDiNascita(new \DateTimeImmutable($nascita));
        $a->setNazioneDiNascita(GeoCountry::ITALIA);
        $a->setComuneDiNascita($comune->getId());
        $a->setCittadinanza(GeoCountry::ITALIA);
        $a->setCodiceFiscale($this->codiceFiscale->calculate($nome, $cognome, $a->getDataDiNascita(), $sesso, $comune->getRegistryCode()));

        $residenza = new Address();
        $residenza->setIndirizzo('Via Roma 1');
        $residenza->setComune($comune->getId());
        $residenza->setProvincia($comune->getProvincialCode());
        $residenza->setCap('H501' === $comune->getRegistryCode() ? '00100' : '20100');
        $a->addAddress($residenza);
        $a->addTelefono(new Telefono('+393331234567', null, true));
        $a->addEmail(new Email($user->getEmail(), null, true));

        $manager->persist($a);
        $user->setAnagrafica($a);
        $user->markProfileCompleted();
    }
}
