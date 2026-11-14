<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BonusCalcMath;
use PHPUnit\Framework\TestCase;

final class BonusCalcMathTest extends TestCase
{
    public function testFinancialYearAndMonth(): void
    {
        self::assertSame('2026-27', BonusCalcMath::financialYear('2026-27'));
        self::assertSame('2099-00', BonusCalcMath::financialYear('2099-00'));
        self::assertSame(BonusCalcMath::DEFAULT_YEAR, '2026-27');
        self::assertNull(BonusCalcMath::financialYear('2026-2027'));
        self::assertNull(BonusCalcMath::financialYear('2026-28'));
        self::assertNull(BonusCalcMath::financialYear('1999-00'));
        self::assertSame(4, BonusCalcMath::month('4'));
        self::assertSame(12, BonusCalcMath::month(12));
        self::assertNull(BonusCalcMath::month('04'));
        self::assertNull(BonusCalcMath::month('13'));
        self::assertSame('April', BonusCalcMath::MONTHS[4]);
    }

    public function testQuantityRules(): void
    {
        self::assertSame(['10.50', null], BonusCalcMath::quantity('10.5', 'OP'));
        self::assertSame(['10.00', null], BonusCalcMath::quantity(10, 'OP'));
        self::assertSame(['0.00', null], BonusCalcMath::quantity('', 'OP'));
        self::assertSame(['0.00', null], BonusCalcMath::quantity(null, 'OP'));
        self::assertSame('OP cannot be negative.', BonusCalcMath::quantity('-1', 'OP')[1]);
        self::assertSame('OP must be a number with at most two decimal places.', BonusCalcMath::quantity('1.234', 'OP')[1]);
        self::assertSame('OP must be a number with at most two decimal places.', BonusCalcMath::quantity('10.', 'OP')[1]);
        self::assertSame('OP is too large.', BonusCalcMath::quantity('10000000000.00', 'OP')[1]);
        self::assertSame(['9999999999.99', null], BonusCalcMath::quantity('9999999999.99', 'OP'));
    }

    public function testTotalProductAndBonusRounding(): void
    {
        self::assertSame('15.50', BonusCalcMath::totalProduct('10.00', '5.50'));
        self::assertSame('4.00', BonusCalcMath::totalProduct('1.25', '2.75'));
        self::assertSame(['320.00', null], BonusCalcMath::bonusAmount('4.00', '80.00'));
        self::assertSame(['805.00', null], BonusCalcMath::bonusAmount('10.00', '80.50'));
        self::assertSame(['1.21', null], BonusCalcMath::bonusAmount('1.10', '1.10'));
        // 0.05 × 0.10 = 0.005, half up to 0.01. 0.04 × 0.10 = 0.004, stays 0.00.
        self::assertSame(['0.01', null], BonusCalcMath::bonusAmount('0.05', '0.10'));
        self::assertSame(['0.00', null], BonusCalcMath::bonusAmount('0.04', '0.10'));
        self::assertSame(['0.00', null], BonusCalcMath::bonusAmount('4.00', null));
        self::assertSame(['0.00', null], BonusCalcMath::bonusAmount('4.00', ''));
        self::assertSame('341.00', BonusCalcMath::sum(['320.00', '21.00']));
        self::assertSame('0.00', BonusCalcMath::sum([null, '']));
        self::assertSame('9999999999.99', BonusCalcMath::bonusAmount('9999999999.99', '1.00')[0]);
        self::assertSame('Bonus amount is too large.', BonusCalcMath::bonusAmount('9999999999.99', '9999999999.99')[1]);
    }
}
