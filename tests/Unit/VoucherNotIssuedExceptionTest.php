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
use PrestaShopHeaven\Runtime\Voucher\VoucherNotIssuedException;

/**
 * VoucherNotIssuedException is a RuntimeException that keeps its message and cause.
 */
final class VoucherNotIssuedExceptionTest extends TestCase
{
    public function testItKeepsMessageAndCause(): void
    {
        $cause = new \LogicException('database');
        $exception = new VoucherNotIssuedException('failed', 0, $cause);

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame('failed', $exception->getMessage());
        self::assertSame($cause, $exception->getPrevious());
    }
}
