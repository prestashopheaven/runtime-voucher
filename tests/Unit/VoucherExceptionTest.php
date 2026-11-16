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
use PrestaShopHeaven\Runtime\Voucher\VoucherException;

/**
 * VoucherException is an InvalidArgumentException with a code per rule, each code different.
 */
final class VoucherExceptionTest extends TestCase
{
    public function testItCarriesTheCodeOfTheRule(): void
    {
        $exception = new VoucherException('bad', VoucherException::INVALID_HOURS);

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertSame(VoucherException::INVALID_HOURS, $exception->getCode());
        self::assertSame('bad', $exception->getMessage());
    }

    public function testEveryCodeIsDifferent(): void
    {
        $codes = (new \ReflectionClass(VoucherException::class))->getConstants();

        self::assertCount(7, $codes);
        self::assertSame(array_values(array_unique($codes)), array_values($codes));
    }
}
