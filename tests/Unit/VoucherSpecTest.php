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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrestaShopHeaven\Runtime\Voucher\VoucherException;
use PrestaShopHeaven\Runtime\Voucher\VoucherSpec;

/**
 * VoucherSpec: the two kinds of discount, every with-method (a copy, the original unchanged), the defaults and every
 * refused value with its code.
 */
final class VoucherSpecTest extends TestCase
{
    public function testAPercentageHasDefaults(): void
    {
        $spec = VoucherSpec::percent(10);

        self::assertTrue($spec->isPercent());
        self::assertSame(10.0, $spec->getPercent());
        self::assertSame(0.0, $spec->getAmount());
        self::assertSame(0, $spec->getCurrencyId());
        self::assertTrue($spec->isTaxIncluded());
        self::assertSame('', $spec->getPrefix());
        self::assertSame(VoucherSpec::DEFAULT_HOURS, $spec->getValidHours());
        self::assertSame(0, $spec->getCustomerId());
        self::assertSame(0, $spec->getShopId());
        self::assertSame([], $spec->getProductIds());
        self::assertSame([], $spec->getNames());
        self::assertTrue($spec->isHighlighted());
    }

    public function testAPercentageIsRoundedToTwoDecimals(): void
    {
        self::assertSame(12.35, VoucherSpec::percent(12.345)->getPercent());
        self::assertSame(100.0, VoucherSpec::percent(100)->getPercent());
        self::assertSame(0.01, VoucherSpec::percent(0.01)->getPercent());
    }

    /**
     * Percentages that are refused.
     *
     * @return iterable<string, array{0: float}>
     */
    public static function wrongPercentages(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-5.0];
        yield 'more than 100' => [100.01];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('wrongPercentages')]
    public function testAWrongPercentageIsRefused(float $percent): void
    {
        $this->expectException(VoucherException::class);
        $this->expectExceptionCode(VoucherException::INVALID_PERCENT);

        VoucherSpec::percent($percent);
    }

    public function testAnAmountKeepsItsCurrencyAndTax(): void
    {
        $spec = VoucherSpec::amount(7.5, 3, false);

        self::assertFalse($spec->isPercent());
        self::assertSame(7.5, $spec->getAmount());
        self::assertSame(0.0, $spec->getPercent());
        self::assertSame(3, $spec->getCurrencyId());
        self::assertFalse($spec->isTaxIncluded());
        self::assertTrue(VoucherSpec::amount(5, 1)->isTaxIncluded());
    }

    /**
     * Amounts and currencies that are refused.
     *
     * @return iterable<string, array{0: float, 1: int}>
     */
    public static function wrongAmounts(): iterable
    {
        yield 'zero' => [0.0, 1];
        yield 'negative' => [-1.0, 1];
        yield 'too large' => [1000000001.0, 1];
        yield 'infinite' => [INF, 1];
        yield 'no currency' => [5.0, 0];
        yield 'negative currency' => [5.0, -1];
    }

    #[DataProvider('wrongAmounts')]
    public function testAWrongAmountIsRefused(float $amount, int $currencyId): void
    {
        $this->expectException(VoucherException::class);
        $this->expectExceptionCode(VoucherException::INVALID_AMOUNT);

        VoucherSpec::amount($amount, $currencyId);
    }

    public function testWithMethodsReturnChangedCopies(): void
    {
        $base = VoucherSpec::percent(10);

        $spec = $base->withPrefix(' cart- ')
            ->validFor(24)
            ->forCustomer(5)
            ->forShop(2)
            ->onlyProducts([3, 4, 3])
            ->named([1 => 'Your discount', 2 => 'Uw korting'])
            ->highlighted(false);

        self::assertSame('CART-', $spec->getPrefix());
        self::assertSame(24, $spec->getValidHours());
        self::assertSame(5, $spec->getCustomerId());
        self::assertSame(2, $spec->getShopId());
        self::assertSame([3, 4], $spec->getProductIds());
        self::assertSame([1 => 'Your discount', 2 => 'Uw korting'], $spec->getNames());
        self::assertFalse($spec->isHighlighted());
        self::assertSame(10.0, $spec->getPercent(), 'the discount stays');
        self::assertSame('', $base->getPrefix(), 'the original is unchanged');
        self::assertSame([], $spec->onlyProducts([])->getProductIds(), 'an empty list removes the limit');
    }

    public function testTheHoursHaveLimits(): void
    {
        self::assertSame(1, VoucherSpec::percent(5)->validFor(1)->getValidHours());
        self::assertSame(VoucherSpec::MAX_HOURS, VoucherSpec::percent(5)->validFor(VoucherSpec::MAX_HOURS)->getValidHours());
    }

    /**
     * Hours that are refused.
     *
     * @return iterable<string, array{0: int}>
     */
    public static function wrongHours(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'more than a year' => [VoucherSpec::MAX_HOURS + 1];
    }

    #[DataProvider('wrongHours')]
    public function testWrongHoursAreRefused(int $hours): void
    {
        $this->expectException(VoucherException::class);
        $this->expectExceptionCode(VoucherException::INVALID_HOURS);

        VoucherSpec::percent(5)->validFor($hours);
    }

    public function testAWrongPrefixIsRefused(): void
    {
        $this->expectException(VoucherException::class);
        $this->expectExceptionCode(VoucherException::INVALID_PREFIX);

        VoucherSpec::percent(5)->withPrefix('NO SPACES');
    }

    /**
     * Ids that are refused, per with-method.
     *
     * @return iterable<string, array{0: callable(VoucherSpec): VoucherSpec}>
     */
    public static function wrongIds(): iterable
    {
        yield 'customer 0' => [static fn (VoucherSpec $s): VoucherSpec => $s->forCustomer(0)];
        yield 'negative customer' => [static fn (VoucherSpec $s): VoucherSpec => $s->forCustomer(-3)];
        yield 'shop 0' => [static fn (VoucherSpec $s): VoucherSpec => $s->forShop(0)];
        yield 'product 0' => [static fn (VoucherSpec $s): VoucherSpec => $s->onlyProducts([1, 0])];
        yield 'product as text' => [static fn (VoucherSpec $s): VoucherSpec => $s->onlyProducts(['2'])];
        yield 'language 0' => [static fn (VoucherSpec $s): VoucherSpec => $s->named([0 => 'Name'])];
    }

    /**
     * @param callable(VoucherSpec): VoucherSpec $change
     */
    #[DataProvider('wrongIds')]
    public function testAWrongIdIsRefused(callable $change): void
    {
        $this->expectException(VoucherException::class);
        $this->expectExceptionCode(VoucherException::INVALID_ID);

        $change(VoucherSpec::percent(5));
    }

    /**
     * Names that are refused.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function wrongNames(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ['   '];
        yield 'too long' => [str_repeat('a', VoucherSpec::MAX_NAME_LENGTH + 1)];
        yield 'html' => ['<b>Name</b>'];
        yield 'braces' => ['{name}'];
        yield 'not text' => [12];
    }

    #[DataProvider('wrongNames')]
    public function testAWrongNameIsRefused(mixed $name): void
    {
        $this->expectException(VoucherException::class);
        $this->expectExceptionCode(VoucherException::INVALID_NAME);

        VoucherSpec::percent(5)->named([1 => $name]);
    }

    public function testANameOfTheMaximumLengthIsKept(): void
    {
        $name = str_repeat('é', VoucherSpec::MAX_NAME_LENGTH);

        self::assertSame([1 => $name], VoucherSpec::percent(5)->named([1 => $name])->getNames());
    }

    public function testValidUntilAddsTheHours(): void
    {
        $issued = new \DateTimeImmutable('2026-11-16 10:00:00');

        self::assertSame('2026-11-18 10:00:00', VoucherSpec::percent(5)->validUntil($issued)->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-16 11:00:00', VoucherSpec::percent(5)->validFor(1)->validUntil($issued)->format('Y-m-d H:i:s'));
    }
}
