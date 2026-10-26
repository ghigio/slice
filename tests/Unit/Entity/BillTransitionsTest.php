<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Eater;
use App\Entity\Evening;
use App\Enum\DiscountMode;
use App\Enum\EveningStatus;
use App\Evening\Exception\InvalidTransitionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillTransitionsTest extends TestCase
{
    public static function statuses(): iterable
    {
        yield 'ordini aperti' => [EveningStatus::OrdersOpen, false];
        yield 'ordini chiusi' => [EveningStatus::OrdersClosed, true];
        yield 'conto inserito (modifica)' => [EveningStatus::BillEntered, true];
        yield 'archiviata' => [EveningStatus::Archived, false];
    }

    #[DataProvider('statuses')]
    public function testIlContoSiInserisceDaOrdiniChiusiOSiModificaAContoInserito(EveningStatus $status, bool $allowed): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $evening->setStatus($status);

        if (!$allowed) {
            $this->expectException(InvalidTransitionException::class);
        }
        $evening->enterBill(3000, DiscountMode::Equal);

        self::assertSame(EveningStatus::BillEntered, $evening->getStatus());
        self::assertSame(3000, $evening->getPaidTotalCents());
        self::assertSame(DiscountMode::Equal, $evening->getDiscountMode());
        self::assertTrue($evening->hasBill());
    }

    public static function allStatuses(): iterable
    {
        foreach (EveningStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    #[DataProvider('allStatuses')]
    public function testRimborsiSoloAContoInseritoOArchiviata(EveningStatus $status): void
    {
        $evening = new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org');
        $evening->setStatus($status);

        self::assertSame(\in_array($status, [EveningStatus::BillEntered, EveningStatus::Archived], true), $evening->canRecordReimbursements());
        self::assertFalse($evening->hasBill(), 'Senza totale pagato non c\'è conto');
    }

    public function testRimborsoDelGruppo(): void
    {
        $eater = new Eater(new Evening('Serata', new \DateTimeImmutable('2026-10-19'), 'Org'), 'Famiglia Rossi');
        $at = new \DateTimeImmutable('2026-10-19 22:15');

        $eater->markReimbursed($at);
        self::assertSame($at, $eater->getReimbursedAt());

        $eater->clearReimbursed();
        self::assertNull($eater->getReimbursedAt());
    }
}
