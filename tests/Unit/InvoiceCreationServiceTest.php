<?php
declare(strict_types=1);

namespace Ksfraser\FA\Sales\Tests\Unit;

use Ksfraser\FA\Sales\Entity\InvoiceDTO;
use Ksfraser\FA\Sales\Service\InvoiceCreationService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InvoiceCreationService and InvoiceDTO.
 *
 * @package ksf_FA_Sales
 * @since 1.0.0
 *
 * @BABOK Related: FR-SALES-001, UT-SALES-001-001
 */
class InvoiceCreationServiceTest extends TestCase
{
    /**
     * @var InvoiceCreationService
     */
    private $service;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__fa_carts'] = [];
        $GLOBALS['__fa_added_lines'] = [];
        $GLOBALS['__fa_next_invoice_no'] = 900;
        $GLOBALS['__fa_write_should_fail'] = false;
        $GLOBALS['__fa_add_to_cart_should_fail'] = false;
        $GLOBALS['__fa_customer'] = array(
            'name' => 'Ada Lovelace Inc',
            'curr_code' => 'CAD',
            'discount' => 0,
            'payment_terms' => 1,
            'phone' => '555-1234',
            'email' => 'ada@example.com',
        );
        $GLOBALS['__fa_default_branch'] = array('branch_id' => 3);

        $this->service = new InvoiceCreationService();
    }

    /**
     * @return array A minimal valid payload.
     */
    private function payload(array $overrides = array())
    {
        return array_merge(array(
            'customer_id' => 42,
            'document_date' => '2026-10-05',
            'reference' => 'WO-1001',
            'lines' => array(
                array('stock_id' => 'SKU-A', 'quantity' => 2, 'price' => 25.00, 'description' => 'Widget'),
            ),
        ), $overrides);
    }

    /**
     * The happy path must build a Cart and write an ST_SALESINVOICE.
     *
     * @test
     */
    public function createsInvoiceThroughFaCart(): void
    {
        $result = $this->service->createInvoice(InvoiceDTO::fromArray($this->payload()));

        $this->assertTrue($result['success']);
        $this->assertSame(900, $result['invoice_no']);
        $this->assertSame(ST_SALESINVOICE, $result['trans_type']);
        $this->assertSame(42, $result['debtor_no']);
        $this->assertSame(3, $result['branch_code']);
        $this->assertSame(1, $result['line_count']);

        $this->assertCount(1, $GLOBALS['__fa_carts'], 'exactly one cart should be built');
        $cart = $GLOBALS['__fa_carts'][0];
        $this->assertSame(ST_SALESINVOICE, $cart->trans_type);
        $this->assertSame(0, $cart->trans_no, 'trans_no 0 means create, not edit');
        $this->assertSame('WO-1001', $cart->reference);

        $this->assertCount(1, $GLOBALS['__fa_added_lines']);
        $line = $GLOBALS['__fa_added_lines'][0];
        $this->assertSame('SKU-A', $line['stock_id']);
        $this->assertEquals(2, $line['qty']);
        $this->assertSame(25.00, $line['price']);
    }

    /**
     * An invoice raised from a sales order must carry the link, otherwise the
     * order never shows as invoiced.
     *
     * @test
     */
    public function linksInvoiceToSourceOrder(): void
    {
        $result = $this->service->createInvoice(
            InvoiceDTO::fromArray($this->payload(array('source_order_no' => 55)))
        );

        $this->assertTrue($result['success']);
        $this->assertSame(55, $result['source_order_no']);

        $cart = $GLOBALS['__fa_carts'][0];
        $this->assertSame(55, $cart->order_no);
        $this->assertSame(array(55), $cart->src_docs);
    }

    /**
     * A missing debtor must be rejected, not turned into a nameless invoice.
     *
     * @test
     */
    public function rejectsMissingDebtor(): void
    {
        $payload = $this->payload();
        unset($payload['customer_id']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('debtor_no');

        $this->service->createInvoice(InvoiceDTO::fromArray($payload));
    }

    /**
     * @test
     */
    public function rejectsInvoiceWithNoLines(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least one line');

        $this->service->createInvoice(InvoiceDTO::fromArray($this->payload(array('lines' => array()))));
    }

    /**
     * @test
     */
    public function rejectsZeroQuantityLine(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('quantity must be greater than zero');

        $this->service->createInvoice(InvoiceDTO::fromArray($this->payload(array(
            'lines' => array(array('stock_id' => 'SKU-A', 'quantity' => 0, 'price' => 10.0)),
        ))));
    }

    /**
     * An import must carry the price the source system charged; recomputing
     * from FA's price lists would rewrite history.
     *
     * @test
     */
    public function rejectsLineWithoutPrice(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no price');

        $this->service->createInvoice(InvoiceDTO::fromArray($this->payload(array(
            'lines' => array(array('stock_id' => 'SKU-A', 'quantity' => 1)),
        ))));
    }

    /**
     * @test
     */
    public function acceptsAlternateLineKeySpellings(): void
    {
        $payload = $this->payload();
        $payload['lines'] = array(
            array('stockid' => 'SKU-B', 'qty' => 3, 'unit_price' => 7.25),
        );

        $result = $this->service->createInvoice(InvoiceDTO::fromArray($payload));

        $this->assertTrue($result['success']);
        $line = $GLOBALS['__fa_added_lines'][0];
        $this->assertSame('SKU-B', $line['stock_id']);
        $this->assertEquals(3, $line['qty']);
        $this->assertSame(7.25, $line['price']);
    }

    /**
     * @test
     */
    public function acceptsItemsAsAnAliasForLines(): void
    {
        $payload = $this->payload();
        unset($payload['lines']);
        $payload['items'] = array(array('stock_id' => 'SKU-C', 'quantity' => 1, 'price' => 5.0));

        $result = $this->service->createInvoice(InvoiceDTO::fromArray($payload));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['line_count']);
    }

    /**
     * A cart write that returns nothing must not be reported as success.
     *
     * @test
     */
    public function failsWhenCartWriteReturnsNothing(): void
    {
        $GLOBALS['__fa_write_should_fail'] = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not return an invoice number');

        $this->service->createInvoice(InvoiceDTO::fromArray($this->payload()));
    }

    /**
     * A line FA refuses must abort the whole invoice rather than writing a
     * partial one.
     *
     * @test
     */
    public function failsWhenLineIsRejectedByCart(): void
    {
        $GLOBALS['__fa_add_to_cart_should_fail'] = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rejected invoice line');

        $this->service->createInvoice(InvoiceDTO::fromArray($this->payload()));
    }

    /**
     * @test
     */
    public function usesExplicitBranchWhenSupplied(): void
    {
        $result = $this->service->createInvoice(
            InvoiceDTO::fromArray($this->payload(array('branch_code' => 7)))
        );

        $this->assertSame(7, $result['branch_code']);
        $this->assertSame(7, $GLOBALS['__fa_carts'][0]->branch_id);
    }

    /**
     * @test
     */
    public function fallsBackToFaDefaultBranch(): void
    {
        $GLOBALS['__fa_default_branch'] = array();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no sales branch');

        $this->service->createInvoice(InvoiceDTO::fromArray($this->payload()));
    }
}