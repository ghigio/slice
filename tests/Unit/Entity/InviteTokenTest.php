<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Evening;
use PHPUnit\Framework\TestCase;

final class InviteTokenTest extends TestCase
{
    public function testLaSerataNasceConUnLinkDInvito(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $evening->getInviteToken());
        self::assertNotSame($evening->getAdminToken(), $evening->getInviteToken());
    }

    public function testRigenerareIlLinkDInvitoCambiaIlToken(): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $old = $evening->getInviteToken();

        $evening->regenerateInviteToken();

        self::assertNotSame($old, $evening->getInviteToken());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $evening->getInviteToken());
    }
}
