<?php

namespace App\Tenancy;

use App\Account\Entity\User;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use Doctrine\DBAL\Connection;

/**
 * "Primi passi": cosa resta da fare per partire bene, calcolato dai dati
 * reali (mai una lista da spuntare a mano). Solo conteggi, nessun contenuto.
 * Testi e collegamenti sono nell'app; qui solo id e "fatto".
 */
final class PrimiPassi
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{tipo: string, passi: list<array{id: string, fatto: bool}>} */
    public function per(Tenant $tenant, User $user): array
    {
        $t = $tenant->getId()->toRfc4122();
        $conta = fn (string $sql, array $p = []) => (int) $this->db->fetchOne($sql, [$t, ...$p]);
        $esiste = fn (string $tabella, string $extra = '', array $p = []) => $conta("SELECT COUNT(*) FROM $tabella WHERE tenant_id = ?$extra", $p) > 0;

        $profilo = $this->db->fetchAssociative('SELECT stato FROM ente_profilo WHERE tenant_id = ?', [$t]);
        if (false !== $profilo || TenantType::PRIVATO !== $tenant->getType()) {
            $passi = [
                'ente_verificato' => \is_array($profilo) && 'verificato' === $profilo['stato'],
                'ente_gruppi' => $esiste('ente_gruppo'),
                'ente_iscritti' => $esiste('ente_iscritto'),
                'ente_inviti' => $conta("SELECT COUNT(*) FROM ente_referente r JOIN ente_iscritto i ON i.id = r.iscritto_id WHERE i.tenant_id = ? AND r.stato IN ('invitato', 'collegato')") > 0,
                'ente_richiesta' => $esiste('ente_richiesta'),
                'ente_operatori' => $conta('SELECT COUNT(*) FROM membership WHERE tenant_id = ?') > 1,
            ];
            $tipo = 'ente';
        } else {
            $passi = [
                'profilo' => $user->isProfileCompleted(),
                'persone' => $conta('SELECT COUNT(*) FROM membro_famiglia WHERE tenant_id = ?') > 1,
                'documento' => $esiste('documento'),
                'scadenza' => $esiste('scadenza'),
                'famigliare' => $conta('SELECT COUNT(*) FROM membership WHERE tenant_id = ?') > 1 || $esiste('invitation', ' AND accepted_at IS NULL AND revoked_at IS NULL'),
                'sicurezza' => $user->isTwoFactorEnabled(),
                'emergenza' => $esiste('contatto_emergenza', " AND stato IN ('invitato', 'attivo')"),
            ];
            $tipo = 'famiglia';
        }

        return ['tipo' => $tipo, 'passi' => array_map(static fn (string $id, bool $fatto) => ['id' => $id, 'fatto' => $fatto], array_keys($passi), array_values($passi))];
    }
}
