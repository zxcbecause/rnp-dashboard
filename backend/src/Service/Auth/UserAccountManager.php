<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\Auth\RefreshToken;
use App\Entity\Auth\User;
use App\Entity\Metric\Metric;
use App\Entity\Metric\UserMetricPref;
use App\Entity\Org\OrgMembership;
use App\Entity\Org\OrgUnit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Local accounts (until the platform login): creation with a generated password, password resets
 * and changes. Generated passwords are returned to the caller once and never stored in clear text.
 */
final readonly class UserAccountManager
{
    public const int MIN_PASSWORD_LENGTH = 10;

    /** No look-alike characters (0/O, 1/l/I), so a password can be read out and typed. */
    private const string ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * @return array{User, string} the user and its initial password
     *
     * @throws \InvalidArgumentException on a bad or taken email
     */
    public function create(string $email, string $name, bool $admin = false): array
    {
        $email = mb_strtolower(trim($email));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(\sprintf('«%s» — не email.', $email));
        }
        if (null !== $this->find($email)) {
            throw new \InvalidArgumentException(\sprintf('Пользователь %s уже есть.', $email));
        }
        $name = $this->validName($name);

        $user = new User($email, $name);
        $user->setRoles($admin ? ['ROLE_ADMIN'] : []);
        $password = self::generatePassword();
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return [$user, $password];
    }

    public function resetPassword(User $user): string
    {
        $password = self::generatePassword();
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->flush();
        $this->revokeSessions($user);

        return $password;
    }

    /**
     * @throws \InvalidArgumentException with a user-facing message
     */
    public function changePassword(User $user, string $current, string $new): void
    {
        if (!$this->hasher->isPasswordValid($user, $current)) {
            throw new \InvalidArgumentException('Текущий пароль указан неверно.');
        }
        if (mb_strlen($new) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Новый пароль — не короче %d символов.', self::MIN_PASSWORD_LENGTH));
        }
        if ($new === $current) {
            throw new \InvalidArgumentException('Новый пароль совпадает с текущим.');
        }

        $user->setPassword($this->hasher->hashPassword($user, $new));
        $this->em->flush();
        $this->revokeSessions($user);
    }

    public function find(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public static function generatePassword(int $length = 14): string
    {
        $password = '';
        for ($i = 0; $i < $length; ++$i) {
            $password .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $password;
    }

    /**
     * A new password ends every session: refresh tokens are deleted, so a stolen one stops sliding.
     * Access tokens (JWT, 15 minutes) simply expire.
     */
    private function revokeSessions(User $user): void
    {
        $this->em->createQuery(\sprintf('DELETE FROM %s t WHERE t.username = :username', RefreshToken::class))
            ->setParameter('username', $user->getUserIdentifier())
            ->execute();
    }

    /**
     * Deletes an account that left no trace in the history (test accounts before going live). Metrics it
     * owns or fills pass to the head of the metric's unit (none when the unit has no other head); units it
     * heads lose the head; memberships, view preferences and sessions go with it. Any other reference —
     * an entered value, a plan, a check, a journal entry, AI usage, including tables added later — makes
     * it refuse: such an account is deactivated instead.
     *
     * @return array{metrics: int, units: int, memberships: int}
     *
     * @throws \InvalidArgumentException when the account is referenced by history
     */
    public function delete(User $user): array
    {
        $id = $user->getId() ?? throw new \InvalidArgumentException('Unsaved user.');
        $connection = $this->em->getConnection();
        $handled = ['metric.owner_user_id', 'metric.filler_user_id', 'org_unit.head_user_id', 'org_membership.user_id', 'user_metric_pref.user_id'];

        /** @var list<array{t: string, c: string}> $references */
        $references = $connection->fetchAllAssociative(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'user' AND REFERENCED_COLUMN_NAME = 'id'",
        );
        $used = [];
        foreach ($references as $ref) {
            if (\in_array($ref['t'].'.'.$ref['c'], $handled, true)) {
                continue;
            }
            $count = (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', $ref['t'], $ref['c']), [$id]);
            if ($count > 0) {
                $used[] = \sprintf('%s.%s (%d)', $ref['t'], $ref['c'], $count);
            }
        }
        $this->assertNotLastAdmin($user);
        if ([] !== $used) {
            throw new \InvalidArgumentException(\sprintf('У %s есть история (%s) — такую учётку можно только отключить.', $user->getEmail(), implode(', ', $used)));
        }

        return $this->em->wrapInTransaction(function () use ($user): array {
            $metrics = 0;
            foreach ($this->em->getRepository(Metric::class)->findAll() as $metric) {
                $owner = $metric->getOwnerUser() === $user;
                $filler = $metric->getFillerUser() === $user;
                if (!$owner && !$filler) {
                    continue;
                }
                $head = $metric->getOrgUnit()->getHeadUser();
                $successor = $head === $user ? null : $head;
                if ($owner) {
                    $metric->setOwnerUser($successor);
                }
                if ($filler) {
                    $metric->setFillerUser($successor);
                }
                ++$metrics;
            }

            $units = 0;
            foreach ($this->em->getRepository(OrgUnit::class)->findBy(['headUser' => $user]) as $unit) {
                $unit->setHeadUser(null);
                ++$units;
            }

            $memberships = 0;
            foreach ($this->em->getRepository(OrgMembership::class)->findBy(['user' => $user]) as $membership) {
                $this->em->remove($membership);
                ++$memberships;
            }
            foreach ($this->em->getRepository(UserMetricPref::class)->findBy(['user' => $user]) as $pref) {
                $this->em->remove($pref);
            }
            $this->revokeSessions($user);
            $this->em->remove($user);
            $this->em->flush();

            return ['metrics' => $metrics, 'units' => $units, 'memberships' => $memberships];
        });
    }

    /**
     * @throws \InvalidArgumentException on an empty name
     */
    public function rename(User $user, string $name): void
    {
        $user->setName($this->validName($name));
        $this->em->flush();
    }

    /**
     * A deactivated account cannot log in and its sessions end (refresh tokens are deleted; the access
     * token is refused by ActiveUserChecker). $actor (the admin doing it) cannot switch itself off.
     *
     * @throws \InvalidArgumentException when the actor deactivates itself or the last active administrator
     */
    public function setActive(User $user, bool $active, ?User $actor = null): void
    {
        if ($active === $user->isActive()) {
            return;
        }
        if (!$active) {
            if (null !== $actor && $actor === $user) {
                throw new \InvalidArgumentException('Нельзя отключить свою учётку.');
            }
            $this->assertNotLastAdmin($user);
        }
        $user->setIsActive($active);
        $this->em->flush();
        if (!$active) {
            $this->revokeSessions($user);
        }
    }

    /**
     * Global administrator: sees the whole tree and manages plans, accounts, tokens and sync.
     *
     * @throws \InvalidArgumentException when the actor drops its own rights or those of the last administrator
     */
    public function setAdmin(User $user, bool $admin, ?User $actor = null): void
    {
        if ($admin === $user->isAdmin()) {
            return;
        }
        if (!$admin) {
            if (null !== $actor && $actor === $user) {
                throw new \InvalidArgumentException('Нельзя снять права администратора с себя.');
            }
            $this->assertNotLastAdmin($user);
        }
        $roles = array_values(array_diff($user->getRoles(), ['ROLE_ADMIN', 'ROLE_USER']));
        $user->setRoles($admin ? [...$roles, 'ROLE_ADMIN'] : $roles);
        $this->em->flush();
    }

    /**
     * Someone must keep the keys: the last active administrator can be neither demoted nor switched off.
     */
    private function assertNotLastAdmin(User $user): void
    {
        if (!$user->isAdmin() || !$user->isActive()) {
            return;
        }
        foreach ($this->em->getRepository(User::class)->findBy(['isActive' => true]) as $other) {
            if ($other !== $user && $other->isAdmin()) {
                return;
            }
        }

        throw new \InvalidArgumentException('Это последний активный администратор — сначала назначьте другого.');
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Имя не может быть пустым.');
        }
        if (mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Имя — не длиннее 255 символов.');
        }

        return $name;
    }
}
