<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Sales\Tests\Unit;

use ksfraser\FrontAccounting\Sales\Service\InvoiceSearchService;
use PHPUnit\Framework\TestCase;

/**
 * @package ksf_FA_Sales
 */
class InvoiceSearchServiceTest extends TestCase
{
    /**
     * Exposes the protected SQL builder.
     */
    private function builder()
    {
        return new class extends InvoiceSearchService {
            public function sql(array $criteria)
            {
                return $this->buildSearchSql($criteria);
            }
        };
    }

    public function testTypeIsAlwaysConstrainedToSalesInvoices(): void
    {
        $this->assertStringContainsString(
            'type = ' . (defined('ST_SALESINVOICE') ? ST_SALESINVOICE : 10),
            $this->builder()->sql(array('debtor_no' => 1))
        );
    }

    /**
     * With no filter the query would return every sales invoice in the ledger.
     * Refusing is correct: a caller with nothing to match on has not asked a
     * question.
     */
    public function testUnfilteredCriteriaProducesNoStatement(): void
    {
        $this->assertNull($this->builder()->sql(array()));
        $this->assertNull($this->builder()->sql(array('limit' => 10)));
    }

    public function testDebtorIsConstrainedAndCastToInt(): void
    {
        $this->assertStringContainsString(
            'debtor_no = 42',
            $this->builder()->sql(array('debtor_no' => '42abc'))
        );
    }

    public function testDateWindowIsInclusive(): void
    {
        $sql = $this->builder()->sql(array(
            'debtor_no' => 1,
            'date_from' => '2026-09-28',
            'date_to' => '2026-10-12',
        ));

        $this->assertStringContainsString("tran_date >= '2026-09-28'", $sql);
        $this->assertStringContainsString("tran_date <= '2026-10-12'", $sql);
        // Strict bounds would drop an invoice dated exactly on the edge.
        $this->assertSame(0, preg_match('/tran_date >(?!=)/', $sql));
        $this->assertSame(0, preg_match('/tran_date <(?!=)/', $sql));
    }

    /**
     * The amount filter is a SHORTLIST tolerance, deliberately wide by default:
     * the caller confirms with a line comparison, so a tight total filter here
     * would throw away the very invoice it needs to compare.
     */
    public function testAmountUsesAPercentageSlackByDefault(): void
    {
        $sql = $this->builder()->sql(array('debtor_no' => 1, 'amount' => 100.0));

        $this->assertSame(
            1,
            preg_match('/amount BETWEEN (-?[0-9.]+) AND (-?[0-9.]+)/', $sql, $m),
            "no amount BETWEEN in: $sql"
        );
        // Default 10% -> 90 .. 110
        $this->assertEqualsWithDelta(90.0, (float)$m[1], 1e-6);
        $this->assertEqualsWithDelta(110.0, (float)$m[2], 1e-6);
    }

    public function testAmountPercentageIsConfigurable(): void
    {
        $sql = $this->builder()->sql(array(
            'debtor_no' => 1,
            'amount' => 100.0,
            'amount_tolerance_percent' => 2.0,
        ));

        preg_match('/amount BETWEEN (-?[0-9.]+) AND (-?[0-9.]+)/', $sql, $m);
        $this->assertEqualsWithDelta(98.0, (float)$m[1], 1e-6);
        $this->assertEqualsWithDelta(102.0, (float)$m[2], 1e-6);
    }

    public function testZeroAmountIsSkippedRatherThanMadeDegenerate(): void
    {
        $sql = $this->builder()->sql(array('debtor_no' => 1, 'amount' => 0.0));

        // A zero-amount window (-0.00 .. 0.00) could never match a real
        // invoice, so it would silently discard every candidate. Omitting the
        // filter is the honest behaviour.
        $this->assertStringNotContainsString('amount BETWEEN', $sql);
        $this->assertStringContainsString('debtor_no = 1', $sql);
    }

    /**
     * Bounds must be fixed-decimal: PHP renders small floats in scientific
     * notation, which is fragile to embed in SQL.
     */
    public function testBoundsAreFixedDecimalNotScientific(): void
    {
        $sql = $this->builder()->sql(array('debtor_no' => 1, 'amount' => 0.0000001));

        $this->assertStringNotContainsString('E-', $sql);
        $this->assertStringNotContainsString('e-', $sql);
    }

    public function testReferenceMatchesEitherTextOrNumeric(): void
    {
        $sql = $this->builder()->sql(array('debtor_no' => 1, 'reference' => '900'));

        $this->assertStringContainsString('reference LIKE', $sql);
        $this->assertStringContainsString('trans_no = 900', $sql);
    }

    public function testReferenceIsEscaped(): void
    {
        $sql = $this->builder()->sql(array('debtor_no' => 1, 'reference' => "O'Brien"));

        $this->assertStringContainsString("O\\'Brien", $sql);
    }

    public function testLimitIsClamped(): void
    {
        $this->assertStringContainsString('LIMIT 1', $this->builder()->sql(array('debtor_no' => 1, 'limit' => 0)));
        $this->assertStringContainsString('LIMIT 50', $this->builder()->sql(array('debtor_no' => 1, 'limit' => 50)));
    }

    public function testUnsupportedCriteriaAreIgnored(): void
    {
        $sql = $this->builder()->sql(array(
            'debtor_no' => 1,
            'evil' => "'; DROP TABLE x --",
            'order_by' => 'password_hash',
        ));

        $this->assertStringNotContainsString('DROP TABLE', $sql);
        $this->assertStringNotContainsString('password_hash', $sql);
    }

    public function testReturnsEmptyWithoutFa(): void
    {
        $service = new InvoiceSearchService();

        $this->assertSame(array(), $service->findCandidates(array('debtor_no' => 1)));
    }

    /**
     * Lines are the entire reason this responder exists, so the mapping from
     * FA's debtor_trans_details columns onto LineItemComparer's shape is pinned.
     */
    public function testLineColumnsAreMappedForTheLineComparer(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/src/FrontAccounting/Sales/Service/InvoiceSearchService.php'
        );

        // FA 2.4.3 stores sales invoice lines in debtor_trans_details.
        $this->assertStringContainsString('debtor_trans_details', $source);
        $this->assertStringContainsString('discount_percent', $source);

        foreach (array('stock_id', 'quantity', 'price', 'discount', 'description') as $key) {
            $this->assertStringContainsString(
                "'" . $key . "' =>",
                $source,
                "line map must expose '{$key}' for LineItemComparer"
            );
        }
    }
}