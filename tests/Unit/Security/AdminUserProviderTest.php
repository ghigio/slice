<?php

namespace App\Tests\Unit\Security;

use App\Security\AdminUserProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class AdminUserProviderTest extends TestCase
{
    public function testCaricaLUnicoAccount(): void
    {
        $user = (new AdminUserProvider('gestore', 'hash'))->loadUserByIdentifier('gestore');

        self::assertSame('gestore', $user->getUserIdentifier());
        self::assertSame(['ROLE_ADMIN'], $user->getRoles());
    }

    public static function refused(): iterable
    {
        yield 'username diverso' => ['gestore', 'hash', 'altro'];
        yield 'username vuoto' => ['', 'hash', ''];
        yield 'hash vuoto' => ['gestore', '', 'gestore'];
    }

    #[DataProvider('refused')]
    public function testSenzaConfigurazioneNessunoEntra(string $username, string $hash, string $identifier): void
    {
        $this->expectException(UserNotFoundException::class);
        (new AdminUserProvider($username, $hash))->loadUserByIdentifier($identifier);
    }
}
