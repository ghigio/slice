<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Evening;
use App\Enum\EveningStatus;
use App\Evening\Exception\InvalidTransitionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EveningTransitionsTest extends TestCase
{
    /** Azione => [stato di partenza => stato di arrivo]; le combinazioni assenti sono vietate. */
    private const array ALLOWED = [
        'closeOrders' => ['orders_open' => 'orders_closed'],
        'reopenOrders' => ['orders_closed' => 'orders_open'],
        'archive' => ['bill_entered' => 'archived'],
        'unarchive' => ['archived' => 'bill_entered'],
    ];

    public static function transitions(): iterable
    {
        foreach (self::ALLOWED as $action => $allowed) {
            foreach (EveningStatus::cases() as $from) {
                $to = $allowed[$from->value] ?? null;
                yield sprintf('%s da %s', $action, $from->value) => [$action, $from, null === $to ? null : EveningStatus::from($to)];
            }
        }
    }

    #[DataProvider('transitions')]
    public function testMatriceDelleTransizioni(string $action, EveningStatus $from, ?EveningStatus $expected): void
    {
        $evening = $this->eveningIn($from);

        if (null === $expected) {
            $this->expectException(InvalidTransitionException::class);
        }

        $evening->{$action}();

        self::assertSame($expected, $evening->getStatus());
    }

    public function testGestioneGruppiVietataSoloDaArchiviata(): void
    {
        foreach (EveningStatus::cases() as $status) {
            $evening = $this->eveningIn($status);
            self::assertSame(EveningStatus::Archived !== $status, $evening->canManageEaters(), $status->value);
            self::assertSame(EveningStatus::Archived === $status, $evening->isArchived(), $status->value);
        }
    }

    public function testEtichetteInItaliano(): void
    {
        self::assertSame('Ordini aperti', EveningStatus::OrdersOpen->label());
        self::assertSame('Ordini chiusi', EveningStatus::OrdersClosed->label());
        self::assertSame('Conto inserito', EveningStatus::BillEntered->label());
        self::assertSame('Archiviata', EveningStatus::Archived->label());
    }

    public function testMessaggioDiTransizioneNonAmmessa(): void
    {
        $this->expectExceptionMessage('Operazione non consentita nello stato «Ordini aperti».');
        $this->eveningIn(EveningStatus::OrdersOpen)->archive();
    }

    private function eveningIn(EveningStatus $status): Evening
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $evening->setStatus($status);

        return $evening;
    }
}
