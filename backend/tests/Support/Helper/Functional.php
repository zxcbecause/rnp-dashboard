<?php

declare(strict_types=1);

namespace App\Tests\Support\Helper;

use App\Entity\Auth\User;
use Codeception\Module;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Functional extends Module
{
    public const string PASSWORD = 'test-password';

    /**
     * The Symfony module reboots the kernel between requests, so always fetch a fresh manager.
     */
    public function grabEntityManager(): EntityManagerInterface
    {
        /** @var Module\Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        /** @var EntityManagerInterface $em */
        $em = $symfony->grabService('doctrine.orm.entity_manager');

        return $em;
    }

    /**
     * @param list<string> $roles
     */
    public function haveUser(string $email, string $name = 'Test User', array $roles = [], bool $active = true): User
    {
        /** @var Module\Symfony $symfony */
        $symfony = $this->getModule('Symfony');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $symfony->grabService(UserPasswordHasherInterface::class);

        $user = new User($email, $name);
        $user->setRoles($roles)->setIsActive($active);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $em = $this->grabEntityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * Logs in through the real /api/login endpoint and sets the Bearer header.
     */
    public function amLoggedInAs(string $email): string
    {
        /** @var Module\REST $rest */
        $rest = $this->getModule('REST');

        $rest->haveHttpHeader('Content-Type', 'application/json');
        $rest->sendPost('/api/login', ['username' => $email, 'password' => self::PASSWORD]);
        $rest->seeResponseCodeIs(200);

        /** @var array{token: string} $response */
        $response = json_decode($rest->grabResponse(), true, 512, \JSON_THROW_ON_ERROR);
        $rest->amBearerAuthenticated($response['token']);

        return $response['token'];
    }
}
