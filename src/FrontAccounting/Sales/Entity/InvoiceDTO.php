<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Sales\Entity;

/**
 * InvoiceDTO — creation request payload for the CREATE_SALES_INVOICE responder.
 *
 * Classic property style on purpose: the cross-module compatibility floor is
 * PHP 7.3 and the FA container runs 7.4, so constructor property promotion
 * (PHP 8.0) and `readonly` (PHP 8.1) would be parse errors.
 *
 * @package ksf_FA_Sales
 * @since 1.0.0
 *
 * @BABOK Related: FR-SALES-001 (CREATE_SALES_INVOICE responder)
 */
class InvoiceDTO
{
    /** @var int */
    private $debtorNo;
    /** @var int */
    private $branchCode;
    /** @var string */
    private $date;
    /** @var string */
    private $dueDate;
    /** @var string */
    private $reference;
    /** @var string */
    private $currency;
    /** @var array */
    private $lines;
    /** @var int */
    private $sourceOrderNo;
    /** @var string */
    private $memo;

    /**
     * @param int    $debtorNo
     * @param int    $branchCode
     * @param string $date
     * @param string $dueDate
     * @param string $reference
     * @param string $currency
     * @param array  $lines
     * @param int    $sourceOrderNo
     * @param string $memo
     */
    public function __construct(
        $debtorNo = 0,
        $branchCode = 0,
        $date = '',
        $dueDate = '',
        $reference = '',
        $currency = '',
        $lines = array(),
        $sourceOrderNo = 0,
        $memo = ''
    ) {
        $this->debtorNo = (int)$debtorNo;
        $this->branchCode = (int)$branchCode;
        $this->date = (string)$date !== '' ? (string)$date : date('Y-m-d');
        $this->dueDate = (string)$dueDate;
        $this->reference = (string)$reference;
        $this->currency = (string)$currency;
        $this->lines = is_array($lines) ? $lines : array();
        $this->sourceOrderNo = (int)$sourceOrderNo;
        $this->memo = (string)$memo;
    }

    /**
     * Build from the loosely-keyed array callers send over the hook boundary.
     *
     * Accepts both ISU key spellings and the native FA ones.
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        $debtor = 0;
        foreach (array('debtor_no', 'customer_id', 'debtorNo') as $key) {
            if (isset($data[$key])) {
                $debtor = (int)$data[$key];
                break;
            }
        }

        $branch = 0;
        foreach (array('branch_code', 'branch_id', 'branchCode') as $key) {
            if (isset($data[$key])) {
                $branch = (int)$data[$key];
                break;
            }
        }

        $date = '';
        foreach (array('document_date', 'invoice_date', 'date') as $key) {
            if (isset($data[$key])) {
                $date = (string)$data[$key];
                break;
            }
        }

        $due = '';
        foreach (array('due_date', 'dueDate') as $key) {
            if (isset($data[$key])) {
                $due = (string)$data[$key];
                break;
            }
        }

        $sourceOrder = 0;
        foreach (array('source_order_no', 'order_no', 'src_doc') as $key) {
            if (isset($data[$key])) {
                $sourceOrder = (int)$data[$key];
                break;
            }
        }

        $lines = array();
        if (isset($data['lines']) && is_array($data['lines'])) {
            $lines = $data['lines'];
        } elseif (isset($data['items']) && is_array($data['items'])) {
            $lines = $data['items'];
        } elseif (isset($data['line_items']) && is_array($data['line_items'])) {
            $lines = $data['line_items'];
        }

        return new self(
            $debtor,
            $branch,
            $date,
            $due,
            isset($data['reference']) ? (string)$data['reference'] : '',
            isset($data['currency']) || isset($data['curr_code'])
                ? (string)(isset($data['currency']) ? $data['currency'] : $data['curr_code'])
                : '',
            $lines,
            $sourceOrder,
            isset($data['memo']) ? (string)$data['memo'] : ''
        );
    }

    /** @return int */
    public function getDebtorNo()
    {
        return $this->debtorNo;
    }

    /** @return int */
    public function getBranchCode()
    {
        return $this->branchCode;
    }

    /** @return string */
    public function getDate()
    {
        return $this->date;
    }

    /** @return string */
    public function getDueDate()
    {
        return $this->dueDate;
    }

    /** @return string */
    public function getReference()
    {
        return $this->reference;
    }

    /** @return string */
    public function getCurrency()
    {
        return $this->currency;
    }

    /** @return array */
    public function getLines()
    {
        return $this->lines;
    }

    /** @return int */
    public function getSourceOrderNo()
    {
        return $this->sourceOrderNo;
    }

    /** @return string */
    public function getMemo()
    {
        return $this->memo;
    }

    /** @return array */
    public function toArray()
    {
        return [
            'debtor_no' => $this->debtorNo,
            'branch_code' => $this->branchCode,
            'document_date' => $this->date,
            'due_date' => $this->dueDate,
            'reference' => $this->reference,
            'currency' => $this->currency,
            'lines' => $this->lines,
            'source_order_no' => $this->sourceOrderNo,
            'memo' => $this->memo,
        ];
    }
}