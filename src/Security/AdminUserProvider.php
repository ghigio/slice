<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Unico account dell'area di gestione delle pizzerie, da ADMIN_USERNAME e ADMIN_PASSWORD_HASH.
 * Con una delle due variabili vuota nessuno può entrare.
 *
 * @implements UserProviderInterface<InMemoryUser>
 */
final class AdminUserProvider implements UserProviderInterface
{
    public function __construct(
        #[Autowire('%env(string:default::ADMIN_USERNAME)%')]
        private readonly string $username,
        #[Autowire('%env(string:default::ADMIN_PASSWORD_HASH)%')]
        private readonly string $passwordHash,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if ('' === $this->username || '' === $this->passwordHash || !hash_equals($this->username, $identifier)) {
            throw new UserNotFoundException();
        }

        return new InMemoryUser($this->username, $this->passwordHash, ['ROLE_ADMIN']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof InMemoryUser) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class;
    }
}
