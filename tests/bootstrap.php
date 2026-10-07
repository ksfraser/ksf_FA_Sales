<?php
/**
 * PHPUnit bootstrap for ksf_FA_Sales.
 *
 * Stubs FA's Cart and the customer/branch accessors so the invoice service's
 * call sequence and failure handling can be asserted without a database.
 *
 * @package ksf_FA_Sales
 * @since 1.0.0
 */

$loader = require_once dirname(__DIR__) . '/vendor/autoload.php';
if (!$loader instanceof \Composer\Autoload\ClassLoader) {
    foreach (spl_autoload_functions() as $fn) {
        if (is_array($fn) && isset($fn[0]) && $fn[0] instanceof \Composer\Autoload\ClassLoader) {
            $loader = $fn[0];
            break;
        }
    }
}
if ($loader instanceof \Composer\Autoload\ClassLoader) {
    $loader->addPsr4('Ksfraser\\FA\\Sales\\', dirname(__DIR__) . '/src/Ksfraser/FA/Sales');
}

if (!defined('ST_SALESINVOICE')) {
    define('ST_SALESINVOICE', 3);
}

$GLOBALS['__fa_carts'] = [];
$GLOBALS['__fa_added_lines'] = [];
$GLOBALS['__fa_next_invoice_no'] = 900;
$GLOBALS['__fa_write_should_fail'] = false;
$GLOBALS['__fa_add_to_cart_should_fail'] = false;
$GLOBALS['__fa_has_cart_class'] = true;
$GLOBALS['__fa_customer'] = array(
    'name' => 'Ada Lovelace Inc',
    'curr_code' => 'CAD',
    'discount' => 0,
    'payment_terms' => 1,
    'phone' => '555-1234',
    'email' => 'ada@example.com',
);
$GLOBALS['__fa_default_branch'] = array('branch_id' => 3);

if (!function_exists('get_customer')) {
    function get_customer($customer_id)
    {
        return $GLOBALS['__fa_customer'];
    }
}

if (!function_exists('get_default_branch')) {
    function get_default_branch($customer_id, $ar_account = null)
    {
        return $GLOBALS['__fa_default_branch'];
    }
}

if (!function_exists('get_invoice_duedate')) {
    function get_invoice_duedate($terms, $invdate)
    {
        return $invdate;
    }
}

if (!defined('FA_SALES_TEST_STUBS_LOADED')) {
    define('FA_SALES_TEST_STUBS_LOADED', true);

    /**
     * Recording double for FA's Cart class (sales/includes/cart_class.inc).
     *
     * Mirrors the real API used by the service:
     *   __construct($type, $trans_no = 0, $prepare_child = false)
     *   set_customer($customer_id, $customer_name, $currency, $discount, $payment, $cdiscount = 0)
     *   set_branch($branch_id, $tax_group_id, $tax_group_name, $phone, $email)
     *   add_to_cart($line_no, $stock_id, $qty, $price, $disc, $qty_done, $standard_cost, $description, ...)
     *   write($policy = 0)
     */
    class Cart
    {
        public $trans_type = 0;
        public $trans_no = 0;
        public $customer_id = 0;
        public $branch_id = 0;
        public $document_date = null;
        public $due_date = null;
        public $reference = '';
        public $Comments = '';
        public $order_no = null;
        public $src_docs = null;
        public $line_items = array();

        public function __construct($type, $trans_no = 0, $prepare_child = false)
        {
            $this->trans_type = $type;
            $this->trans_no = $trans_no;
            $GLOBALS['__fa_carts'][] = $this;
        }

        public function set_customer($customer_id, $customer_name, $currency, $discount, $payment, $cdiscount = 0)
        {
            $this->customer_id = $customer_id;
            return true;
        }

        public function set_branch($branch_id, $tax_group_id, $tax_group_name, $phone = '', $email = '')
        {
            $this->branch_id = $branch_id;
            return true;
        }

        public function add_to_cart(
            $line_no,
            $stock_id,
            $qty,
            $price,
            $disc,
            $qty_done = 0,
            $standard_cost = 0,
            $description = null,
            $id = 0,
            $src_no = 0,
            $src_id = 0
        ) {
            if ($GLOBALS['__fa_add_to_cart_should_fail']) {
                return 0;
            }
            $this->line_items[$line_no] = array(
                'stock_id' => $stock_id,
                'qty' => $qty,
                'price' => $price,
                'disc' => $disc,
                'description' => $description,
            );
            $GLOBALS['__fa_added_lines'][] = $this->line_items[$line_no];
            return 1;
        }

        public function write($policy = 0)
        {
            if ($GLOBALS['__fa_write_should_fail']) {
                return 0;
            }
            return $GLOBALS['__fa_next_invoice_no']++;
        }
    }
}

// TB_PREF and db_escape are pure and side-effect free, so they are safe to
// define here. db_query is deliberately NOT defined: tests assert the 'no FA
// environment' branch (findCandidates must return array() when db_query is
// absent), and PHP cannot undefine a function, so a global stub would disarm
// them for every later test.
defined('TB_PREF') || define('TB_PREF', '0_');

if (!function_exists('db_escape')) {
    function db_escape($value)
    {
        return "'" . addslashes((string)$value) . "'";
    }
}
