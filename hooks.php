<?php

declare(strict_types=1);

/**
 * ksf_FA_Sales — CREATE_SALES_INVOICE responder.
 *
 * Owns exactly one capability: raising a native FA sales invoice from staged
 * order lines, so Import Staging can orchestrate invoicing without owning FA's
 * transactional mechanics. The write is delegated to FA's own Cart
 * (`Cart::write()` -> `write_sales_invoice`), so GL, tax, allocation and
 * numbering are FA's, not ours.
 *
 * Source systems (Square, WooCommerce) MUST NOT call this. They stage orders
 * into ISU; ISU, after human review and duplicate-matching, is what requests
 * the invoice. Same rule as CRM's CREATE_CUSTOMER and ksf_FA_Payment's
 * CREATE_PAYMENT.
 *
 * PHP 7.3 compatible.
 *
 * @package ksf_FA_Sales
 * @since 1.0.0
 */

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

define('SS_ksf_FA_Sales', 152 << 8);

class hooks_ksf_FA_Sales extends hooks
{
    var $module_name = 'ksf_FA_Sales';
    var $version = '1.0.0';

    /**
     * @return array [0] => security_areas, [1] => security_sections
     */
    function install_access()
    {
        $security_sections[SS_ksf_FA_Sales] = _("Sales");

        $security_areas['SA_ksf_FA_SALES_INVOICE'] = array(SS_ksf_FA_Sales | 1, _("Import Invoice"));

        return array($security_areas, $security_sections);
    }

    /**
     * Advertise capabilities for other modules.
     *
     * @return array
     */
    protected function _getAdvertisedValues()
    {
        return array(
            'sales.hooks_version' => '1.0',
            'sales.module_version' => '1.0.0',
            'sales.features' => array('create_sales_invoice'),
        );
    }

    /**
     * Create a native FA sales invoice.
     *
     * Accepts an InvoiceDTO or a loosely-keyed array, normalises to a DTO, and
     * delegates the write to InvoiceCreationService. The by-reference $data is
     * REPLACED with the response, because a DTO cannot carry response offsets.
     *
     * @param array|\Ksfraser\FA\Sales\Entity\InvoiceDTO $data Request DTO or array
     * @param array|null $opts
     * @return array Response: success, invoice_no, trans_type, debtor_no, line_count
     */
    function CREATE_SALES_INVOICE(&$data, $opts = null)
    {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            $data = ['success' => false, 'error' => 'ksf_FA_Sales autoloader missing'];
            return $data;
        }
        require_once $autoload;

        if ($data instanceof \Ksfraser\FA\Sales\Entity\InvoiceDTO) {
            $dto = $data;
        } elseif (is_array($data)) {
            $dto = \Ksfraser\FA\Sales\Entity\InvoiceDTO::fromArray($data);
        } else {
            $data = [
                'success' => false,
                'error' => 'CREATE_SALES_INVOICE requires an InvoiceDTO or array payload',
            ];
            return $data;
        }

        try {
            $service = new \Ksfraser\FA\Sales\Service\InvoiceCreationService();
            $response = $service->createInvoice($dto);
        } catch (\Exception $e) {
            $response = ['success' => false, 'error' => $e->getMessage()];
        }

        $data = $response;
        return $response;
    }
}