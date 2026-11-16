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

namespace PrestaShopHeaven\Runtime\Voucher\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PrestaShopHeaven\Runtime\Voucher\VoucherIssuer;
use PrestaShopHeaven\Runtime\Voucher\VoucherNotIssuedException;
use PrestaShopHeaven\Runtime\Voucher\VoucherSpec;

/**
 * VoucherIssuer in a real shop: a personal percentage voucher limited to a product and the shop becomes a cart rule
 * like the back office makes one; the customer sees it in a cart with that product (and not in one without), it gives
 * a discount, and it stops being usable when it expires or is used up. An amount voucher is for the whole order. A
 * code that exists already is never reused.
 */
final class VoucherIssuerTest extends TestCase
{
    private \Customer $customer;

    /** @var int[] two active products */
    private array $products = [];

    /** @var int[] cart rules and carts made by the test */
    private array $rules = [];

    /** @var \Cart[] */
    private array $carts = [];

    /**
     * A customer and two products.
     */
    protected function setUp(): void
    {
        // Cart::updateQty() runs hooks of other modules (ps_googleanalytics) that need the context currency (CLI).
        \Context::getContext()->currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $this->customer = new \Customer();
        $this->customer->firstname = 'Voucher';
        $this->customer->lastname = 'Fixture';
        $this->customer->email = 'voucher-' . bin2hex(random_bytes(4)) . '@example.com';
        $this->customer->passwd = md5('voucher-fixture-' . random_int(1, PHP_INT_MAX));
        $this->customer->id_shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        self::assertTrue((bool) $this->customer->add());
        $rows = \Db::getInstance()->executeS(
            'SELECT p.id_product FROM `' . _DB_PREFIX_ . 'product` p INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = '
            . (int) \Configuration::get('PS_SHOP_DEFAULT') . ' WHERE ps.active = 1 AND p.cache_is_pack = 0 AND p.product_type <> \'combinations\' ORDER BY p.id_product LIMIT 2'
        );
        $this->products = array_map('intval', array_column(is_array($rows) ? $rows : [], 'id_product'));
        self::assertCount(2, $this->products, 'the shop has two simple products');
    }

    /**
     * Removes what the test made.
     */
    protected function tearDown(): void
    {
        foreach ($this->rules as $id) {
            $rule = new \CartRule($id);
            if (\Validate::isLoadedObject($rule)) {
                $rule->delete();
            }
        }
        foreach ($this->carts as $cart) {
            $cart->delete();
        }
        $this->customer->delete();
    }

    public function testAPersonalPercentageVoucherBecomesACartRuleLimitedToTheProductAndShop(): void
    {
        $now = new \DateTimeImmutable('-1 minute');
        $shopId = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $languageId = (int) \Configuration::get('PS_LANG_DEFAULT');

        $voucher = $this->issue(
            VoucherSpec::percent(10)->withPrefix('fix-')->validFor(24)->forCustomer((int) $this->customer->id)
                ->forShop($shopId)->onlyProducts([$this->products[0]])->named([$languageId => 'Fixture discount']),
            $now
        );

        self::assertMatchesRegularExpression('/^FIX-[A-Z2-9]{8}$/', $voucher->code);
        self::assertSame(10.0, $voucher->percent);
        self::assertSame(0.0, $voucher->amount);
        self::assertSame($now->modify('+24 hours')->format('Y-m-d H:i:s'), $voucher->validUntil->format('Y-m-d H:i:s'));
        $rule = new \CartRule($voucher->id);
        self::assertSame($voucher->code, $rule->code);
        self::assertSame((int) $this->customer->id, (int) $rule->id_customer);
        self::assertSame(1, (int) $rule->quantity);
        self::assertSame(1, (int) $rule->quantity_per_user);
        self::assertTrue((bool) $rule->highlight);
        self::assertFalse((bool) $rule->partial_use);
        self::assertSame(-2, (int) $rule->reduction_product, 'the percentage of the selected products');
        self::assertTrue((bool) $rule->product_restriction);
        self::assertSame('Fixture discount', $rule->name[$languageId] ?? null);
        self::assertSame(\Shop::isFeatureActive(), (bool) $rule->shop_restriction);
        self::assertSame(
            \Shop::isFeatureActive() ? [$shopId] : [],
            array_map('intval', array_column(\Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'cart_rule_shop` WHERE id_cart_rule = ' . $voucher->id) ?: [], 'id_shop'))
        );
        self::assertSame([$this->products[0]], array_map('intval', array_column(\Db::getInstance()->executeS(
            'SELECT v.id_item FROM `' . _DB_PREFIX_ . 'cart_rule_product_rule_group` g'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cart_rule_product_rule` r ON r.id_product_rule_group = g.id_product_rule_group AND r.type = \'products\''
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cart_rule_product_rule_value` v ON v.id_product_rule = r.id_product_rule'
            . ' WHERE g.id_cart_rule = ' . $voucher->id
        ) ?: [], 'id_item')));
    }

    public function testTheCustomerSeesTheVoucherOnlyInACartWithTheProductAndGetsTheDiscount(): void
    {
        $languageId = (int) \Configuration::get('PS_LANG_DEFAULT');
        $voucher = $this->issue(
            VoucherSpec::percent(10)->forCustomer((int) $this->customer->id)->onlyProducts([$this->products[0]]),
            new \DateTimeImmutable('-1 hour')
        );
        $withProduct = $this->cart($this->products[0]);
        $withoutProduct = $this->cart($this->products[1]);

        // As on the cart page, the cart is the one of the context (PrestaShop 8.1 checks the product restriction on it).
        $context = \Context::getContext();
        $previous = $context->cart;
        try {
            $context->cart = $withProduct;
            self::assertContains($voucher->code, array_column(\CartRule::getCustomerHighlightedDiscounts($languageId, (int) $this->customer->id, $withProduct), 'code'));
            $context->cart = $withoutProduct;
            self::assertNotContains($voucher->code, array_column(\CartRule::getCustomerHighlightedDiscounts($languageId, (int) $this->customer->id, $withoutProduct), 'code'));
        } finally {
            $context->cart = $previous;
        }

        self::assertTrue($withProduct->addCartRule($voucher->id));
        self::assertGreaterThan(0, $withProduct->getOrderTotal(true, \Cart::ONLY_DISCOUNTS));
    }

    public function testAnAmountVoucherIsForTheWholeOrder(): void
    {
        $currencyId = (int) \Configuration::get('PS_CURRENCY_DEFAULT');

        $voucher = $this->issue(VoucherSpec::amount(5, $currencyId)->forCustomer((int) $this->customer->id), new \DateTimeImmutable('-1 minute'));

        $rule = new \CartRule($voucher->id);
        self::assertSame(5.0, $voucher->amount);
        self::assertSame($currencyId, $voucher->currencyId);
        self::assertSame(0, (int) $rule->reduction_product);
        self::assertFalse((bool) $rule->product_restriction);
        self::assertFalse((bool) $rule->shop_restriction);
        self::assertSame($voucher->code, (string) $rule->name[(int) \Configuration::get('PS_LANG_DEFAULT')], 'without names the code is the name');
    }

    public function testFindAndIsUsableFollowTheRule(): void
    {
        $issuer = new VoucherIssuer();
        $now = new \DateTimeImmutable('-1 minute');
        $voucher = $this->issue(VoucherSpec::percent(15)->validFor(2)->forCustomer((int) $this->customer->id), $now);

        $rule = new \CartRule($voucher->id);
        self::assertSame($now->modify('-1 day')->format('Y-m-d H:i:s'), (string) $rule->date_from);

        $found = $issuer->find($voucher->id);
        self::assertNotNull($found);
        self::assertSame($voucher->code, $found->code);
        self::assertSame(15.0, $found->percent);
        self::assertSame(0, $found->currencyId);
        self::assertNull($issuer->find(0));
        self::assertNull($issuer->find(999999999));

        self::assertTrue($issuer->isUsable($voucher->id, $now->modify('+1 hour')));
        self::assertFalse($issuer->isUsable($voucher->id, $now->modify('+3 hours')), 'expired');
        self::assertTrue($issuer->isUsable($voucher->id, $now->modify('-1 hour')), 'starts a day early (time zones)');
        self::assertFalse($issuer->isUsable($voucher->id, $now->modify('-25 hours')), 'not started');
        self::assertFalse($issuer->isUsable(0, $now));
        self::assertFalse($issuer->isUsable(999999999, $now));

        $rule = new \CartRule($voucher->id);
        $rule->quantity = 0;
        self::assertTrue((bool) $rule->update());
        self::assertFalse($issuer->isUsable($voucher->id, $now->modify('+1 hour')), 'used up');
    }

    public function testACodeThatExistsIsNeverReused(): void
    {
        $existing = $this->issue(VoucherSpec::percent(5), new \DateTimeImmutable('-1 minute'));
        $issuer = new VoucherIssuer(static fn (string $prefix): string => $existing->code);

        $this->expectException(VoucherNotIssuedException::class);
        $this->expectExceptionMessage('No free voucher code');

        $issuer->issue(VoucherSpec::percent(5), new \DateTimeImmutable());
    }

    /**
     * Issues a voucher and remembers it for tearDown().
     */
    private function issue(VoucherSpec $spec, \DateTimeImmutable $now): \PrestaShopHeaven\Runtime\Voucher\IssuedVoucher
    {
        $voucher = (new VoucherIssuer())->issue($spec, $now);
        $this->rules[] = $voucher->id;

        return $voucher;
    }

    /**
     * A cart of the customer with one product.
     */
    private function cart(int $productId): \Cart
    {
        $cart = new \Cart();
        $cart->id_customer = (int) $this->customer->id;
        $cart->id_shop = (int) \Configuration::get('PS_SHOP_DEFAULT');
        $cart->id_shop_group = (int) \Shop::getGroupFromShop($cart->id_shop);
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->secure_key = (string) $this->customer->secure_key;
        self::assertTrue((bool) $cart->add());
        self::assertNotFalse($cart->updateQty(1, $productId));
        $this->carts[] = $cart;

        return $cart;
    }
}
