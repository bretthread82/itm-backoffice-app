<?php

declare(strict_types=1);

namespace App\Services\Entra;

use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;

/**
 * Decides which local account a Microsoft identity may sign in to. Fails closed:
 *  - never creates accounts (administrators provision people first);
 *  - never signs in an inactive user, or a placeholder with no email;
 *  - matches on the stable object id once known, otherwise on email, and then remembers the object id;
 *  - refuses an email match when that account is already linked to a different Microsoft identity.
 */
final class EntraLogin
{
    public const UNKNOWN  = 'unknown';
    public const INACTIVE = 'inactive';
    public const CONFLICT = 'conflict';

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * @return array{user: User|null, reason: string|null, needs_link: bool}
     */
    public function findUser(EntraIdentity $identity): array
    {
        $users = model(UserModel::class);
        $user  = null;
        $link  = false;

        $row = $this->db->table('users')->select('id')->where('entra_oid', $identity->oid)->get()->getRowArray();
        if ($row !== null) {
            $user = $users->findById((int) $row['id']);
        } elseif ($identity->email !== '') {
            $found = $users->findByCredentials(['email' => $identity->email]);
            if ($found instanceof User) {
                $existing = (string) ($this->db->table('users')->select('entra_oid')->where('id', $found->id)->get()->getRow()->entra_oid ?? '');
                if ($existing !== '' && $existing !== $identity->oid) {
                    return ['user' => null, 'reason' => self::CONFLICT, 'needs_link' => false];
                }
                $user = $found;
                $link = true;
            }
        }

        if (! $user instanceof User) {
            return ['user' => null, 'reason' => self::UNKNOWN, 'needs_link' => false];
        }
        if (! $user->active || (int) ($user->email_missing ?? 0) === 1) {
            return ['user' => null, 'reason' => self::INACTIVE, 'needs_link' => false];
        }

        return ['user' => $user, 'reason' => null, 'needs_link' => $link];
    }

    public function link(int $userId, EntraIdentity $identity): void
    {
        $this->db->table('users')->where('id', $userId)->update(['entra_oid' => $identity->oid]);
    }

    public function unlink(int $userId): void
    {
        $this->db->table('users')->where('id', $userId)->update(['entra_oid' => null]);
    }
}
