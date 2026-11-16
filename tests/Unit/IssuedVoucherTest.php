<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    prestahaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShopHeaven\Runtime\Voucher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaShopHeaven\Runtime\Voucher\IssuedVoucher;

/**
 * IssuedVoucher keeps its plain values and is immutable.
 */
final class IssuedVoucherTest extends TestCase
{
    public function testItKeepsItsValues(): void
    {
        $until = new \DateTimeImmutable('2026-11-18 10:00:00');
        $voucher = new IssuedVoucher(12, 'CART-ABCD2345', $until, 10.0, 0.0, 0);

        self::assertSame(12, $voucher->id);
        self::assertSame('CART-ABCD2345', $voucher->code);
        self::assertSame($until, $voucher->validUntil);
        self::assertSame(10.0, $voucher->percent);
        self::assertSame(0.0, $voucher->amount);
        self::assertSame(0, $voucher->currencyId);
    }

    public function testItIsImmutable(): void
    {
        foreach (['id', 'code', 'validUntil', 'percent', 'amount', 'currencyId'] as $property) {
            self::assertTrue((new \ReflectionProperty(IssuedVoucher::class, $property))->isReadOnly(), $property);
        }
    }
}
