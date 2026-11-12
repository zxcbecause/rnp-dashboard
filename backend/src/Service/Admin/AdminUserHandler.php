<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\DTO\Admin\AdminUserDto;
use App\DTO\Request\AdminUserCreateRequest;
use App\DTO\Request\AdminUserUpdateRequest;
use App\Entity\Auth\User;
use App\Entity\Org\OrgMembership;
use App\Service\Auth\UserAccountManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Accounts on the administration screen (ROLE_ADMIN; the same rules as app:user, see UserAccountManager).
 * Generated passwords are returned once and never stored in clear text.
 */
final readonly class AdminUserHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserAccountManager $accounts,
    ) {
    }

    /**
     * @return list<AdminUserDto> active first, then by name
     */
    public function list(): array
    {
        $byUser = [];
        foreach ($this->em->getRepository(OrgMembership::class)->findAll() as $membership) {
            $byUser[(int) $membership->getUser()->getId()][] = $membership;
        }

        $users = $this->em->getRepository(User::class)->findAll();
        usort($users, static fn (User $a, User $b): int => [!$a->isActive(), mb_strtolower($a->getName())] <=> [!$b->isActive(), mb_strtolower($b->getName())]);

        return array_map(static fn (User $user): AdminUserDto => AdminUserDto::fromEntity($user, $byUser[(int) $user->getId()] ?? []), $users);
    }

    /**
     * @return array{password: string, item: AdminUserDto}
     */
    public function create(AdminUserCreateRequest $request): array
    {
        try {
            [$user, $password] = $this->accounts->create($request->email, $request->name, $request->isAdmin);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return ['password' => $password, 'item' => $this->dto($user)];
    }

    public function update(int $id, AdminUserUpdateRequest $request, User $actor): AdminUserDto
    {
        $user = $this->user($id);
        try {
            // A DBAL transaction: the ORM one would close the entity manager on a refused change.
            $this->em->getConnection()->transactional(function () use ($user, $request, $actor): void {
                $this->accounts->rename($user, $request->name);
                $this->accounts->setAdmin($user, $request->isAdmin, $actor);
                $this->accounts->setActive($user, $request->isActive, $actor);
            });
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->dto($user);
    }

    /**
     * A new password ends every session of the account.
     */
    public function resetPassword(int $id): string
    {
        return $this->accounts->resetPassword($this->user($id));
    }

    /**
     * Only accounts without history; the others are deactivated (UserAccountManager::delete).
     */
    public function delete(int $id, User $actor): void
    {
        $user = $this->user($id);
        if ($user === $actor) {
            throw new UnprocessableEntityHttpException('Нельзя удалить свою учётку.');
        }
        try {
            $this->accounts->delete($user);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
    }

    private function user(int $id): User
    {
        return $this->em->find(User::class, $id) ?? throw new NotFoundHttpException('Пользователь не найден.');
    }

    private function dto(User $user): AdminUserDto
    {
        /** @var list<OrgMembership> $memberships */
        $memberships = $this->em->getRepository(OrgMembership::class)->findBy(['user' => $user]);

        return AdminUserDto::fromEntity($user, $memberships);
    }
}
