<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Sales\Service;

use ksfraser\FrontAccounting\Sales\Entity\InvoiceDTO;

/**
 * InvoiceSearchService — finds candidate FA sales invoices for a staged order.
 *
 * EXISTS FOR THE SECOND STAGE OF INVOICE MATCHING. A shortlist on debtor, date
 * and a loose total is cheap but unreliable: FA and the source system compute
 * different totals for the same order (freight, tax rounding, coupons applied at
 * line or invoice level). So this returns CANDIDATE INVOICES WITH THEIR LINE
 * ITEMS, and ISU's LineItemComparer decides whether they are actually the same
 * order. Totals are returned for context and for ordering, never as the verdict.
 *
 * Line storage was verified against FA 2.4.3: a sales invoice header is a row in
 * `debtor_trans` with `type = ST_SALESINVOICE`, and its lines are rows in
 * `debtor_trans_details` (there is no `sales_invoice_details` table in 2.4.3).
 * The line columns mirror FA's own `line_details` class: `stock_id`, `quantity`,
 * `price`, `discount_percent`, `description`.
 *
 * PHP 7.3 compatible: no typed properties.
 *
 * @package ksf_FA_Sales
 */
class InvoiceSearchService
{
    /**
     * Candidate invoices, each with its lines.
     *
     * @param array $criteria See SEARCH_SALES_INVOICE on the hooks class.
     * @return array List of ['_module','_entity','invoice_no','debtor_no','branch_id',
     *                        'tran_date','total','lines'=>[...]]
     */
    public function findCandidates(array $criteria)
    {
        if (!$this->isCoreRoutineLoaded('db_query')) {
            return array();
        }

        $sql = $this->buildSearchSql($criteria);
        if ($sql === null) {
            return array();
        }

        $result = db_query($sql, 'Could not search sales invoices');
        $rows = array();
        while ($row = db_fetch($result)) {
            $rows[] = $row;
        }

        if ($rows === array()) {
            return array();
        }

        $lineMap = $this->linesFor(array_column($rows, 'trans_no'));

        $candidates = array();
        foreach ($rows as $row) {
            $transNo = (int)$row['trans_no'];
            $candidates[] = array(
                'invoice_no' => $transNo,
                'debtor_no' => isset($row['debtor_no']) ? (int)$row['debtor_no'] : 0,
                'branch_id' => isset($row['branch_id']) ? (int)$row['branch_id'] : 0,
                'reference' => isset($row['reference']) ? $row['reference'] : '',
                'tran_date' => isset($row['tran_date']) ? $row['tran_date'] : null,
                'total' => isset($row['amount']) ? (float)$row['amount'] : 0.0,
                // The reason this responder exists: the line items, so ISU can
                // compare them instead of trusting the total.
                'lines' => isset($lineMap[$transNo]) ? $lineMap[$transNo] : array(),
            );
        }

        return $candidates;
    }

    /**
     * Load the line items for a set of invoices in ONE query.
     *
     * A per-invoice query would be N+1; the whole point is that the caller
     * shortlists a handful and compares their lines.
     *
     * @param array $transNos
     * @return array trans_no => list of normalised lines
     */
    private function linesFor(array $transNos)
    {
        $transNos = array_values(array_unique(array_filter(array_map('intval', $transNos))));
        if ($transNos === array()) {
            return array();
        }

        $inList = array();
        foreach ($transNos as $no) {
            $inList[] = (string)$no;
        }

        $sql = 'SELECT trans_no, stock_id, quantity, price, discount_percent, description'
            . ' FROM ' . TB_PREF . 'debtor_trans_details'
            . ' WHERE trans_type = ' . (defined('ST_SALESINVOICE') ? ST_SALESINVOICE : 10)
            . ' AND trans_no IN (' . implode(',', $inList) . ')'
            . ' ORDER BY trans_no, id';

        $result = db_query($sql, 'Could not load sales invoice lines');

        $map = array();
        while ($row = db_fetch($result)) {
            $transNo = (int)$row['trans_no'];
            $map[$transNo][] = array(
                // FA's debtor_trans_details column names mapped onto the shape
                // LineItemComparer expects.
                'stock_id' => isset($row['stock_id']) ? (string)$row['stock_id'] : '',
                'quantity' => isset($row['quantity']) ? (float)$row['quantity'] : 0.0,
                'price' => isset($row['price']) ? (float)$row['price'] : 0.0,
                'discount' => isset($row['discount_percent']) ? (float)$row['discount_percent'] : 0.0,
                'description' => isset($row['description']) ? $row['description'] : '',
            );
        }

        return $map;
    }

    /**
     * Build the header SELECT for a candidate search, or null when there is
     * nothing to search for.
     *
     * Protected so the SQL is assertable without a database: a global db_query()
     * stub cannot be used because PHP cannot undefine a function, so it would
     * disarm the 'no FA environment' branch the rest of the suite asserts.
     *
     * @param array $criteria
     * @return string|null
     */
    protected function buildSearchSql(array $criteria)
    {
        $where = array('type = ' . (defined('ST_SALESINVOICE') ? ST_SALESINVOICE : 10));

        $hasFilter = false;

        if (isset($criteria['debtor_no']) && (int)$criteria['debtor_no'] > 0) {
            $where[] = 'debtor_no = ' . (int)$criteria['debtor_no'];
            $hasFilter = true;
        }

        // Inclusive bounds: a strict bound would drop an invoice dated exactly on
        // the edge of the +/-N day window.
        foreach (array('date_from' => '>=', 'date_to' => '<=') as $key => $operator) {
            if (!empty($criteria[$key])) {
                $where[] = 'tran_date ' . $operator . ' ' . db_escape((string)$criteria[$key]);
                $hasFilter = true;
            }
        }

        if (isset($criteria['reference']) && trim((string)$criteria['reference']) !== '') {
            $reference = trim((string)$criteria['reference']);
            $where[] = '(reference LIKE ' . db_escape('%' . $reference . '%')
                . ' OR trans_no = ' . (int)$reference . ')';
            $hasFilter = true;
        }

        // A loose total window. This is a SHORTLIST filter only -- ISU confirms
        // with line comparison, so a wide tolerance here is correct and cheap.
        //
        // A zero amount is skipped rather than turned into a window: it carries
        // no matching signal, and the resulting range (-0.00 .. 0.00) could
        // never match a real invoice, so it would silently drop every candidate.
        if (isset($criteria['amount'])
            && is_numeric($criteria['amount'])
            && abs((float)$criteria['amount']) > 0.0) {
            $amount = (float)$criteria['amount'];
            $percent = isset($criteria['amount_tolerance_percent']) && is_numeric($criteria['amount_tolerance_percent'])
                ? abs((float)$criteria['amount_tolerance_percent'])
                : 10.0;
            $slack = $amount * ($percent / 100.0);
            $slack += 1e-9;
            // Format explicitly: PHP renders small bounds in scientific
            // notation (1.0E-9), which is needlessly fragile in SQL and for a
            // zero-amount search collapses to a near-empty range either way.
            $where[] = 'amount BETWEEN ' . $this->num($amount - $slack)
                . ' AND ' . $this->num($amount + $slack);
            $hasFilter = true;
        }

        if (!$hasFilter) {
            // Nothing to match on. Returning every invoice would be worse than
            // returning none, so refuse rather than dump the ledger.
            return null;
        }

        $limit = isset($criteria['limit']) ? max(1, (int)$criteria['limit']) : 20;

        return 'SELECT trans_no, debtor_no, branch_id, reference, tran_date, amount'
            . ' FROM ' . TB_PREF . 'debtor_trans'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY tran_date DESC'
            . ' LIMIT ' . $limit;
    }

    /**
     * Render a bound as fixed-decimal SQL.
     *
     * @param float $value
     * @return string
     */
    private function num($value)
    {
        return sprintf('%.6F', (float)$value);
    }

    /**
     * Whether an FA core routine is available. Seam for testing.
     *
     * @param string $function
     * @return bool
     */
    protected function isCoreRoutineLoaded($function)
    {
        return function_exists($function);
    }
}