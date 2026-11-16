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

namespace PrestaShopHeaven\Runtime\Voucher;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What a personal voucher gives and to whom: a percentage or an amount, the prefix of its code, how many hours it is
 * valid, the customer, the shop, the products it is limited to, its name per language and whether the cart shows it to
 * the customer. Immutable: every with-method returns a copy. Pure PHP; VoucherIssuer turns it into a cart rule.
 */
final class VoucherSpec
{
    /** Hours a voucher is valid when validFor() is not called. */
    public const DEFAULT_HOURS = 48;

    /** At most a year. */
    public const MAX_HOURS = 8760;

    /** The longest name PrestaShop keeps for a cart rule (cart_rule_lang.name). */
    public const MAX_NAME_LENGTH = 254;

    /**
     * Use percent() or amount().
     *
     * @param int[] $productIds
     * @param array<int, string> $names
     */
    private function __construct(
        private readonly float $percent,
        private readonly float $amount,
        private readonly int $currencyId,
        private readonly bool $taxIncluded,
        private readonly string $prefix = '',
        private readonly int $validHours = self::DEFAULT_HOURS,
        private readonly int $customerId = 0,
        private readonly int $shopId = 0,
        private readonly array $productIds = [],
        private readonly array $names = [],
        private readonly bool $highlighted = true,
    ) {
    }

    /**
     * A discount of a percentage (of the products it is limited to, or of the whole order).
     *
     * @throws VoucherException with INVALID_PERCENT when it is not more than 0 and at most 100
     */
    public static function percent(float $percent): self
    {
        if (!is_finite($percent) || $percent <= 0 || $percent > 100) {
            throw new VoucherException('A voucher percentage is more than 0 and at most 100.', VoucherException::INVALID_PERCENT);
        }

        return new self(round($percent, 2), 0.0, 0, true);
    }

    /**
     * A discount of an amount in a currency, with or without tax.
     *
     * @throws VoucherException with INVALID_AMOUNT when the amount is not more than 0 or the currency is not an id
     */
    public static function amount(float $amount, int $currencyId, bool $taxIncluded = true): self
    {
        if (!is_finite($amount) || $amount <= 0 || $amount > 1000000000) {
            throw new VoucherException('A voucher amount is more than 0 and at most 1000000000.', VoucherException::INVALID_AMOUNT);
        }
        if ($currencyId <= 0) {
            throw new VoucherException('A voucher amount needs a currency.', VoucherException::INVALID_AMOUNT);
        }

        return new self(0.0, round($amount, 6), $currencyId, $taxIncluded);
    }

    /**
     * The same voucher with this prefix for its code (A-Z, 0-9, "-" and "_", at most 20; lower case is upper-cased).
     *
     * @throws VoucherException with INVALID_PREFIX
     */
    public function withPrefix(string $prefix): self
    {
        return $this->copy(['prefix' => VoucherCode::normalizePrefix($prefix)]);
    }

    /**
     * The same voucher, valid for this many hours from the moment it is issued (1 to 8760).
     *
     * @throws VoucherException with INVALID_HOURS
     */
    public function validFor(int $hours): self
    {
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw new VoucherException(sprintf('A voucher is valid for 1 to %d hours, not %d.', self::MAX_HOURS, $hours), VoucherException::INVALID_HOURS);
        }

        return $this->copy(['validHours' => $hours]);
    }

    /**
     * The same voucher for one customer only.
     *
     * @throws VoucherException with INVALID_ID when the id is not positive
     */
    public function forCustomer(int $customerId): self
    {
        return $this->copy(['customerId' => self::positive($customerId, 'customer')]);
    }

    /**
     * The same voucher for one shop only (only applied when multistore is used).
     *
     * @throws VoucherException with INVALID_ID when the id is not positive
     */
    public function forShop(int $shopId): self
    {
        return $this->copy(['shopId' => self::positive($shopId, 'shop')]);
    }

    /**
     * The same voucher, valid only when one of these products is in the cart; a percentage is then taken of these
     * products only. An empty list removes the limit.
     *
     * @param array<mixed> $productIds product ids (anything that is not a positive int is refused)
     *
     * @throws VoucherException with INVALID_ID when an id is not positive
     */
    public function onlyProducts(array $productIds): self
    {
        $ids = [];
        foreach ($productIds as $id) {
            $ids[] = self::positive(is_int($id) ? $id : 0, 'product');
        }

        return $this->copy(['productIds' => array_values(array_unique($ids))]);
    }

    /**
     * The same voucher with its name per language id (shown in the cart and the order). A language without a name gets
     * the first one.
     *
     * @param array<mixed> $names language id => name (anything else is refused)
     *
     * @throws VoucherException with INVALID_ID or INVALID_NAME
     */
    public function named(array $names): self
    {
        $checked = [];
        foreach ($names as $languageId => $name) {
            $name = is_string($name) ? trim($name, " \t\n\r") : '';
            if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH || preg_match('/[<>={}]/', $name)) {
                throw new VoucherException('A voucher name has 1 to 254 characters, without <, >, = or braces.', VoucherException::INVALID_NAME);
            }
            $checked[self::positive((int) $languageId, 'language')] = $name;
        }

        return $this->copy(['names' => $checked]);
    }

    /**
     * The same voucher, shown to the customer in the cart's voucher block (true, the default) or not.
     */
    public function highlighted(bool $highlighted): self
    {
        return $this->copy(['highlighted' => $highlighted]);
    }

    /**
     * Whether the discount is a percentage (else an amount).
     */
    public function isPercent(): bool
    {
        return $this->percent > 0;
    }

    /**
     * The percentage (0 for an amount).
     */
    public function getPercent(): float
    {
        return $this->percent;
    }

    /**
     * The amount (0 for a percentage).
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * The currency of the amount (0 for a percentage).
     */
    public function getCurrencyId(): int
    {
        return $this->currencyId;
    }

    /**
     * Whether the amount includes tax.
     */
    public function isTaxIncluded(): bool
    {
        return $this->taxIncluded;
    }

    /**
     * The prefix of the code ('' = none).
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * How many hours the voucher is valid.
     */
    public function getValidHours(): int
    {
        return $this->validHours;
    }

    /**
     * The customer (0 = anyone).
     */
    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    /**
     * The shop (0 = every shop).
     */
    public function getShopId(): int
    {
        return $this->shopId;
    }

    /**
     * The products the voucher is limited to (empty = none).
     *
     * @return int[]
     */
    public function getProductIds(): array
    {
        return $this->productIds;
    }

    /**
     * The name per language id.
     *
     * @return array<int, string>
     */
    public function getNames(): array
    {
        return $this->names;
    }

    /**
     * Whether the cart shows the voucher to the customer.
     */
    public function isHighlighted(): bool
    {
        return $this->highlighted;
    }

    /**
     * Until when a voucher issued at this moment is valid.
     */
    public function validUntil(\DateTimeImmutable $issuedAt): \DateTimeImmutable
    {
        return $issuedAt->modify(sprintf('+%d hours', $this->validHours));
    }

    /**
     * A copy with some values changed.
     *
     * @param array<string, mixed> $changes
     */
    private function copy(array $changes): self
    {
        $values = get_object_vars($this);

        return new self(...array_merge($values, $changes));
    }

    /**
     * The id when it is positive.
     *
     * @throws VoucherException with INVALID_ID
     */
    private static function positive(int $id, string $what): int
    {
        if ($id <= 0) {
            throw new VoucherException(sprintf('A voucher %s id is positive, not %d.', $what, $id), VoucherException::INVALID_ID);
        }

        return $id;
    }
}
