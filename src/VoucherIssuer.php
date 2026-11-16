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
 * Creates personal vouchers as PrestaShop cart rules and reads them back. A voucher is used once (quantity 1, once per
 * customer), cannot be split over two orders, is shown in the cart's voucher block of its customer (highlight), and
 * is limited to a shop and to products when the spec says so (the same tables the back office fills: cart_rule_shop
 * and the product rule group). Works the same in PrestaShop 8.1 and 9.
 */
final class VoucherIssuer
{
    /** How often a new random code is tried when the code exists already. */
    public const MAX_ATTEMPTS = 5;

    /** reduction_product of a percentage of the products of the product restriction ("selected products"). */
    private const SELECTED_PRODUCTS = -2;

    /**
     * Creates the issuer.
     *
     * @param (\Closure(string): string)|null $codeFactory makes a code from the prefix (default VoucherCode::generate());
     *                                                     tests give a fixed code to check what happens when it exists
     */
    public function __construct(private readonly ?\Closure $codeFactory = null)
    {
    }

    /**
     * Creates the cart rule of the spec, valid from $now for the hours of the spec. $now is in the shop's time zone
     * (PrestaShop stores the dates of cart rules that way).
     *
     * @throws VoucherNotIssuedException when no free code was found or saving failed (the half-made rule is removed)
     */
    public function issue(VoucherSpec $spec, \DateTimeImmutable $now): IssuedVoucher
    {
        $code = $this->freeCode($spec->getPrefix());
        $rule = new \CartRule();
        $rule->code = $code;
        $rule->name = $this->names($spec, $code);
        $rule->description = '';
        $rule->id_customer = $spec->getCustomerId();
        $rule->date_from = $now->format('Y-m-d H:i:s');
        $rule->date_to = $spec->validUntil($now)->format('Y-m-d H:i:s');
        $rule->quantity = 1;
        $rule->quantity_per_user = 1;
        $rule->priority = 1;
        $rule->partial_use = false;
        $rule->minimum_amount = 0;
        $rule->highlight = $spec->isHighlighted();
        $rule->active = true;
        $rule->free_shipping = false;
        $rule->reduction_percent = $spec->getPercent();
        $rule->reduction_amount = $spec->getAmount();
        $rule->reduction_tax = $spec->isTaxIncluded();
        $rule->reduction_currency = $spec->isPercent() ? 0 : $spec->getCurrencyId();
        $rule->minimum_amount_currency = $spec->isPercent() ? (int) \Configuration::get('PS_CURRENCY_DEFAULT') : $spec->getCurrencyId();
        $rule->reduction_product = $spec->isPercent() && $spec->getProductIds() !== [] ? self::SELECTED_PRODUCTS : 0;
        $rule->reduction_exclude_special = false;
        $rule->shop_restriction = $spec->getShopId() > 0 && \Shop::isFeatureActive();
        $rule->product_restriction = $spec->getProductIds() !== [];
        try {
            if (!$rule->add()) {
                throw new VoucherNotIssuedException(sprintf('Saving the cart rule of voucher %s failed.', $code));
            }
            if ($rule->shop_restriction) {
                $this->restrictToShop((int) $rule->id, $spec->getShopId());
            }
            if ($rule->product_restriction) {
                $this->restrictToProducts((int) $rule->id, $spec->getProductIds());
            }
        } catch (\Throwable $e) {
            if ((int) $rule->id > 0) {
                $rule->delete();
            }
            throw $e instanceof VoucherNotIssuedException ? $e : new VoucherNotIssuedException('Creating voucher ' . $code . ' failed: ' . $e->getMessage(), 0, $e);
        }

        return $this->toVoucher($rule);
    }

    /**
     * The voucher of a cart rule, or null when the rule does not exist.
     */
    public function find(int $cartRuleId): ?IssuedVoucher
    {
        if ($cartRuleId <= 0) {
            return null;
        }
        $rule = new \CartRule($cartRuleId);

        return \Validate::isLoadedObject($rule) ? $this->toVoucher($rule) : null;
    }

    /**
     * Whether the voucher can still be used at $now: it exists, is active, has not been used up, and $now is within its
     * dates (shop time zone).
     */
    public function isUsable(int $cartRuleId, \DateTimeImmutable $now): bool
    {
        if ($cartRuleId <= 0) {
            return false;
        }
        $rule = new \CartRule($cartRuleId);
        if (!\Validate::isLoadedObject($rule) || !$rule->active || (int) $rule->quantity <= 0) {
            return false;
        }
        $moment = $now->format('Y-m-d H:i:s');

        return (string) $rule->date_from <= $moment && $moment < (string) $rule->date_to;
    }

    /**
     * A random code with the prefix that no cart rule has yet.
     *
     * @throws VoucherNotIssuedException when every attempt hit an existing code
     */
    private function freeCode(string $prefix): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $code = $this->codeFactory !== null ? (string) ($this->codeFactory)($prefix) : VoucherCode::generate($prefix);
            if ((int) \CartRule::getIdByCode($code) === 0) {
                return $code;
            }
        }
        throw new VoucherNotIssuedException(sprintf('No free voucher code with prefix "%s" after %d attempts.', $prefix, self::MAX_ATTEMPTS));
    }

    /**
     * A name for every language of the shop: the spec's name of that language, else its first name, else the code.
     *
     * @return array<int, string>
     */
    private function names(VoucherSpec $spec, string $code): array
    {
        $names = $spec->getNames();
        $fallback = $names === [] ? $code : (string) reset($names);
        $result = [];
        foreach (\Language::getLanguages(false) as $language) {
            $result[(int) $language['id_lang']] = $names[(int) $language['id_lang']] ?? $fallback;
        }

        return $result;
    }

    /**
     * Limits the rule to one shop (cart_rule_shop).
     *
     * @throws VoucherNotIssuedException when the row cannot be written
     */
    private function restrictToShop(int $ruleId, int $shopId): void
    {
        if (!\Db::getInstance()->insert('cart_rule_shop', ['id_cart_rule' => $ruleId, 'id_shop' => $shopId])) {
            throw new VoucherNotIssuedException('Limiting the voucher to its shop failed.');
        }
    }

    /**
     * Limits the rule to carts with at least one of the products: one rule group (quantity 1) with one "products" rule
     * and a value per product.
     *
     * @param int[] $productIds
     *
     * @throws VoucherNotIssuedException when a row cannot be written
     */
    private function restrictToProducts(int $ruleId, array $productIds): void
    {
        $db = \Db::getInstance();
        if (!$db->insert('cart_rule_product_rule_group', ['id_cart_rule' => $ruleId, 'quantity' => 1])) {
            throw new VoucherNotIssuedException('Limiting the voucher to products failed (rule group).');
        }
        $groupId = (int) $db->Insert_ID();
        if (!$db->insert('cart_rule_product_rule', ['id_product_rule_group' => $groupId, 'type' => 'products'])) {
            throw new VoucherNotIssuedException('Limiting the voucher to products failed (rule).');
        }
        $productRuleId = (int) $db->Insert_ID();
        $rows = [];
        foreach ($productIds as $productId) {
            $rows[] = ['id_product_rule' => $productRuleId, 'id_item' => (int) $productId];
        }
        if (!$db->insert('cart_rule_product_rule_value', $rows)) {
            throw new VoucherNotIssuedException('Limiting the voucher to products failed (values).');
        }
    }

    /**
     * The plain values of a cart rule.
     */
    private function toVoucher(\CartRule $rule): IssuedVoucher
    {
        $until = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $rule->date_to);

        return new IssuedVoucher(
            (int) $rule->id,
            (string) $rule->code,
            $until instanceof \DateTimeImmutable ? $until : new \DateTimeImmutable('@0'),
            (float) $rule->reduction_percent,
            (float) $rule->reduction_amount,
            (float) $rule->reduction_amount > 0 ? (int) $rule->reduction_currency : 0
        );
    }
}
