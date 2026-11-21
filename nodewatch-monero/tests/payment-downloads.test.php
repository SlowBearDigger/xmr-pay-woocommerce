<?php
/** Offline invariants for missing-only permission repair; native SQL rollback is tested in application E2E. */
define('ABSPATH', __DIR__ . '/');
require_once __DIR__ . '/../includes/class-xmrpay-payment-transaction.php';

$settings = array('woocommerce_downloads_grant_access_after_payment' => 'yes');
function get_option($key) { return $GLOBALS['settings'][$key] ?? ''; }
class PermissionDatabase {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = array();
    public $unavailable = false;
    public function prepare($query, ...$arguments) { return $arguments; }
    public function get_var($arguments) {
        $this->last_error = $this->unavailable ? 'database unavailable' : '';
        return $this->rows[implode(':', $arguments)] ?? 0;
    }
}
class PermissionProduct {
    public function get_id() { return 42; }
    public function is_downloadable() { return true; }
    public function get_downloads() { return array('existing' => true, 'missing' => true); }
}
class PermissionItem {
    public function get_product() { return new PermissionProduct(); }
    public function get_quantity() { return 2; }
}
class PermissionStore {
    public $granted = false;
    public function set_download_permissions_granted($order, $value) { $this->granted = $value; }
}
class PermissionOrder {
    public $status = 'completed';
    public $paid = true;
    public $gateway = 'xmrpay';
    public $store;
    public function __construct() { $this->store = new PermissionStore(); }
    public function get_id() { return 7; }
    public function is_paid() { return $this->paid; }
    public function get_payment_method() { return $this->gateway; }
    public function has_status($status) { return $this->status === $status; }
    public function get_items() { return array(new PermissionItem()); }
    public function get_data_store() { return $this->store; }
}
$created = array();
$creation_fails = false;
function wc_downloadable_file_permission($download_id, $product, $order, $quantity, $item) {
    if ($GLOBALS['creation_fails']) { return 0; }
    $key = $order->get_id() . ':' . $product->get_id() . ':' . $download_id;
    $GLOBALS['wpdb']->rows[$key] = ($GLOBALS['wpdb']->rows[$key] ?? 0) + 1;
    $GLOBALS['created'][] = array($download_id, $quantity);
    return 1;
}
$passed = 0;
$failed = 0;
function check($name, $condition) {
    if ($condition) { $GLOBALS['passed']++; echo "PASS $name\n"; }
    else { $GLOBALS['failed']++; echo "FAIL $name\n"; }
}
$wpdb = new PermissionDatabase();
$wpdb->rows['7:42:existing'] = 2;
$order = new PermissionOrder();
$repair = new ReflectionMethod(XmrPay_Payment_Transaction::class, 'repair_missing_downloads');
if (PHP_VERSION_ID < 80100) { $repair->setAccessible(true); }
check('missing-only repair succeeds', $repair->invoke(null, $order) === true);
check('existing native permissions are retained', $wpdb->rows['7:42:existing'] === 2);
check('only the missing download is created with the native item quantity', $created === array(array('missing', 2)));
check('permission flag is set after persistence', $order->store->granted);
$repair->invoke(null, $order);
check('repeated repair does not duplicate permission creation', count($created) === 1 && $wpdb->rows['7:42:missing'] === 1);
check('already complete paid permissions need no repair transaction', XmrPay_Payment_Transaction::repair($order) === true);

unset($wpdb->rows['7:42:missing']);
$order->store->granted = false;
$creation_fails = true;
$rejected = false;
try { $repair->invoke(null, $order); } catch (RuntimeException $error) { $rejected = true; }
check('failed native creation is rejected before the granted flag', $rejected && !$order->store->granted && !isset($wpdb->rows['7:42:missing']));
check('a rejected repair preserves existing permissions', $wpdb->rows['7:42:existing'] === 2);
$creation_fails = false;
$wpdb->unavailable = true;
$rejected = false;
try { $repair->invoke(null, $order); } catch (RuntimeException $error) { $rejected = true; }
check('unavailable lookup cannot create a speculative permission', $rejected && count($created) === 1 && !$order->store->granted);
$wpdb->unavailable = false;

$order->status = 'processing';
$settings['woocommerce_downloads_grant_access_after_payment'] = 'no';
check('processing orders retain the native delayed-access policy', $repair->invoke(null, $order) === true && count($created) === 1 && !$order->store->granted);
$order->paid = false;
check('unpaid orders cannot enter paid permission repair', XmrPay_Payment_Transaction::repair($order) === false);
$order->paid = true;
$order->gateway = 'other';
check('another gateway cannot enter paid permission repair', XmrPay_Payment_Transaction::repair($order) === false);
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
