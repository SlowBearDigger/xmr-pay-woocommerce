<?php
/** Keep native order completion and download permissions in one database transaction. */
if (!defined('ABSPATH')) { exit; }

class XmrPay_Payment_Transaction {
    public static function lock_name($id) {
        global $wpdb;
        return 'xmrpay_payment_' . substr(hash('sha256', $wpdb->prefix . ':' . $id), 0, 40);
    }
    public static function fresh_order($id) {
        clean_post_cache($id);
        wp_cache_delete($id, 'post_meta');
        if (class_exists('Automattic\\WooCommerce\\Caches\\OrderCache')) {
            wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($id);
        }
        $store = WC_Data_Store::load('order');
        if (is_callable(array($store, 'clear_cached_data'))) { $store->clear_cached_data(array($id)); }
        return wc_get_order($id);
    }

    public static function complete($id, $callback) {
        return self::with_order_transaction($id, static function ($order) use ($id, $callback) {
            if ($order->is_paid()) { return self::repair_missing_downloads($order); }
            // WooCommerce does not propagate a failed permission INSERT. Check before
            // subsequent payment-complete listeners observe a successful completion.
            $guard = static function ($completed_id) use ($id) {
                if ((int) $completed_id === (int) $id) { self::assert_downloads_persisted(self::fresh_order($id)); }
            };
            add_action('woocommerce_payment_complete', $guard, PHP_INT_MIN, 1);
            try {
                $completed = $callback($order);
            } finally {
                remove_action('woocommerce_payment_complete', $guard, PHP_INT_MIN);
            }
            $persisted = self::fresh_order($id);
            if (!$completed || !$persisted || !$persisted->is_paid()) {
                throw new RuntimeException('WooCommerce payment completion failed');
            }
            self::assert_downloads_persisted($persisted);
            return true;
        });
    }

    public static function repair($order) {
        if (!$order || !$order->is_paid() || $order->get_payment_method() !== 'xmrpay') { return false; }
        try {
            self::assert_downloads_persisted($order);
            return true;
        } catch (Throwable $error) {
            return self::with_order_transaction($order->get_id(), static function ($fresh) {
                if (!$fresh->is_paid()) { return false; }
                return self::repair_missing_downloads($fresh);
            });
        }
    }

    private static function repair_missing_downloads($order) {
        global $wpdb;
        if ($order->has_status('processing') && get_option('woocommerce_downloads_grant_access_after_payment') === 'no') { return true; }
        $table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product || !$product->is_downloadable()) { continue; }
            foreach (array_keys($product->get_downloads()) as $download_id) {
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table
                    WHERE order_id=%d AND product_id=%d AND download_id=%s",
                    $order->get_id(), $product->get_id(), $download_id));
                if ($wpdb->last_error !== '') { throw new RuntimeException('WooCommerce download permission lookup unavailable'); }
                // Preserve existing limits, access dates and download counts; never regenerate them.
                if ((int) $count > 0) { continue; }
                if (!wc_downloadable_file_permission($download_id, $product, $order, $item->get_quantity(), $item)) {
                    throw new RuntimeException('WooCommerce download permission repair failed');
                }
            }
        }
        self::assert_downloads_persisted($order);
        $order->get_data_store()->set_download_permissions_granted($order, true);
        return true;
    }

    /** Execute order changes under the shared lock and one non-reconnecting transaction. */
    public static function with_order_transaction($id, $callback) {
        global $wpdb;
        $lock = self::lock_name($id);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') { return false; }
        $transaction = false;
        $reconnect_retries = $wpdb->reconnect_retries;
        $release_sql = $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock);
        $cache_engine = class_exists('Automattic\\WooCommerce\\Caching\\WPCacheEngine')
            ? wc_get_container()->get(\Automattic\WooCommerce\Caching\WPCacheEngine::class) : null;
        $product_ids = array();
        $cleaned = false;
        $cleanup = static function () use ($wpdb, $id, $release_sql, $cache_engine, $reconnect_retries, &$product_ids, &$transaction, &$cleaned) {
            if ($cleaned) { return; }
            $cleaned = true;
            if ($transaction && $wpdb->dbh instanceof mysqli) { mysqli_query($wpdb->dbh, 'ROLLBACK'); }
            $transaction = false;
            // Cache invalidation must also work while MariaDB is unavailable.
            self::clear_caches_without_queries($id, $product_ids, $cache_engine);
            $wpdb->reconnect_retries = $reconnect_retries;
            if ($wpdb->dbh instanceof mysqli && mysqli_query($wpdb->dbh, 'DO 1') !== false) {
                mysqli_query($wpdb->dbh, $release_sql);
            }
        };
        // WordPress database failures and third-party hooks can terminate PHP without a finally block.
        register_shutdown_function($cleanup);
        try {
            $hpos = class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            self::assert_transactional_storage($hpos);
            // A reconnect would lose both the transaction and lock, then replay writes in autocommit.
            $wpdb->reconnect_retries = 0;
            if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Payment transaction unavailable'); }
            $transaction = true;
            $orders = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
            $column = $hpos ? 'id' : 'ID';
            if (!$wpdb->get_var($wpdb->prepare("SELECT $column FROM $orders WHERE $column=%d FOR UPDATE", $id))) {
                throw new RuntimeException('Payment order unavailable');
            }
            $order = self::fresh_order($id);
            if (!$order || $order->get_payment_method() !== 'xmrpay') { throw new RuntimeException('Payment order mismatch'); }
            foreach ($order->get_items() as $item) {
                $product_ids[] = $item->get_product_id();
                if ($item->get_variation_id()) { $product_ids[] = $item->get_variation_id(); }
                $product = $item->get_product();
                if ($product) { $product_ids[] = $product->get_stock_managed_by_id(); }
            }
            $result = $callback($order, $hpos);
            if ($result === false) { throw new RuntimeException('Order transaction callback failed'); }
            if ((string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock)) !== '1') {
                throw new RuntimeException('Payment database connection changed');
            }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Payment commit failed'); }
            $transaction = false;
            return $result;
        } catch (Throwable $error) {
            // Use the original connection: cleanup must never reconnect and execute a new transaction.
            if ($transaction && $wpdb->dbh instanceof mysqli) { mysqli_query($wpdb->dbh, 'ROLLBACK'); }
            if ($wpdb->dbh instanceof mysqli && mysqli_query($wpdb->dbh, 'DO 1') !== false) {
                wc_get_logger()->warning('XMRPay settlement deferred for order #' . $id . ': ' . $error->getMessage(), array('source' => 'xmr-pay'));
            }
            return false;
        } finally {
            $cleanup();
        }
    }

    private static function clear_caches_without_queries($id, $product_ids, $cache_engine) {
        // A rolled-back option mutex must not survive in a persistent object cache.
        wp_cache_delete('xmrpay_lock_' . get_current_blog_id() . '_pay_' . $id, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete($id, 'posts');
        wp_cache_delete($id, 'post_meta');
        WC_Cache_Helper::invalidate_cache_group('object_' . $id);
        if ($cache_engine) {
            foreach (array('orders', 'order_objects', 'orders_data', 'orders_meta') as $group) {
                $cache_engine->delete_cached_object((string) $id, $group);
            }
        }
        foreach (array_unique($product_ids) as $product_id) {
            wp_cache_delete($product_id, 'posts');
            wp_cache_delete($product_id, 'post_meta');
            WC_Cache_Helper::invalidate_cache_group('object_' . $product_id);
            WC_Cache_Helper::invalidate_cache_group('product_' . $product_id);
        }
    }

    private static function assert_transactional_storage($hpos) {
        global $wpdb;
        $names = array('posts', 'postmeta', 'comments', 'commentmeta', 'options',
            'woocommerce_order_items', 'woocommerce_order_itemmeta',
            'woocommerce_downloadable_product_permissions', 'wc_product_meta_lookup', 'wc_reserved_stock');
        if ($hpos) { $names = array_merge($names, array('wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data')); }
        $tables = array();
        foreach ($names as $name) { $tables[] = $wpdb->prefix . $name; }
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($placeholders)", $tables), ARRAY_A);
        if ($wpdb->last_error !== '' || count($rows) !== count($tables)) { throw new RuntimeException('Payment storage unavailable'); }
        foreach ($rows as $row) {
            if (strtolower($row['ENGINE']) !== 'innodb') { throw new RuntimeException('Payment storage must support transactions'); }
        }
    }

    private static function assert_downloads_persisted($order) {
        global $wpdb;
        if ($order->has_status('processing') && get_option('woocommerce_downloads_grant_access_after_payment') === 'no') { return; }
        $table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product || !$product->is_downloadable()) { continue; }
            foreach (array_keys($product->get_downloads()) as $download_id) {
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table
                    WHERE order_id=%d AND product_id=%d AND download_id=%s",
                    $order->get_id(), $product->get_id(), $download_id));
                if ($wpdb->last_error !== '') { throw new RuntimeException('WooCommerce download permission lookup unavailable'); }
                if ((int) $count < 1) { throw new RuntimeException('WooCommerce download permission was not persisted'); }
            }
        }
    }
}
