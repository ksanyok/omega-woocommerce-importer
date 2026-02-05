<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Weekly/light update: updates price + stock for existing products by SKU (Omega `Number`).
 * Runs in small batches via WP-Cron single events to avoid timeouts.
 */

function owi_update_get_api_url() {
    return 'https://public.omega.page/public/api/v1.0/product/pricelist/paged';
}

function owi_update_lock_key($name) {
    return 'owi_lock_' . sanitize_key($name);
}

/**
 * Acquire a simple lock using options table (atomic add_option).
 */
function owi_update_acquire_lock($name, $ttl_seconds = 300) {
    $key = owi_update_lock_key($name);
    $now = time();

    $existing = get_option($key);
    if (is_array($existing) && isset($existing['expires']) && (int)$existing['expires'] > $now) {
        return false;
    }

    $value = array(
        'expires' => $now + (int)$ttl_seconds,
        'set_at' => $now,
        'pid' => function_exists('getmypid') ? getmypid() : null,
    );

    if ($existing === false) {
        if (add_option($key, $value, '', 'no')) {
            return true;
        }
    }

    // Lock existed but expired or malformed, try to take over.
    update_option($key, $value, false);
    return true;
}

function owi_update_release_lock($name) {
    delete_option(owi_update_lock_key($name));
}

function owi_update_is_stuck($max_idle_seconds = 6 * 3600) {
    if (!get_option('owi_update_in_progress')) {
        return false;
    }
    $progress = get_option('owi_update_progress');
    if (!is_array($progress)) {
        return true;
    }
    $last = isset($progress['last_update_time']) ? (int)$progress['last_update_time'] : 0;
    if ($last <= 0) {
        return true;
    }
    return (time() - $last) > (int)$max_idle_seconds;
}

function owi_update_reset_flags() {
    delete_option('owi_update_in_progress');
    delete_option('owi_update_stop');
    delete_option('owi_update_progress');
}

/**
 * Start/resume update.
 *
 * @param bool $fresh_start If true, resets progress and starts from page 0.
 */
function owi_run_update($fresh_start = false) {
    if ($fresh_start) {
        owi_update_reset_flags();
    }

    // Self-heal if stuck
    if (owi_update_is_stuck()) {
        owi_log('Update appears stuck; resetting flags and restarting.');
        owi_update_reset_flags();
        $fresh_start = true;
    }

    if (get_option('owi_update_in_progress') && !$fresh_start) {
        return array('success' => false, 'message' => 'Обновление уже запущено.');
    }

    $api_key = get_option('owi_api_key');
    if (empty($api_key)) {
        return array('success' => false, 'message' => 'API ключ не установлен.');
    }

    update_option('owi_update_in_progress', true, false);

    $per_page = (int)apply_filters('owi_update_per_page', 200);
    if ($per_page < 10) {
        $per_page = 10;
    }

    $start_time = time();
    $progress = array(
        'per_page' => $per_page,
        'total_pages' => 0,
        'current_page' => 0,
        'total_processed' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'missing' => 0,
        'errors' => 0,
        'start_time' => $start_time,
        'last_update_time' => $start_time,
    );
    update_option('owi_update_progress', $progress, false);

    $api_url = owi_update_get_api_url();

    // Initial request to fetch Total
    $initial_data = array(
        'IsPrepay' => false,
        'From' => 0,
        'Count' => 1,
        'AddSupplierRests' => false,
        'Key' => $api_key,
    );

    $initial_response = wp_remote_post($api_url, array(
        'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
        'body' => wp_json_encode($initial_data),
        'timeout' => 60,
    ));

    if (is_wp_error($initial_response)) {
        $msg = 'Ошибка при подключении к API (update): ' . $initial_response->get_error_message();
        owi_log($msg);
        update_option('owi_update_in_progress', false, false);
        return array('success' => false, 'message' => $msg);
    }

    $initial_body = wp_remote_retrieve_body($initial_response);
    $initial_result = json_decode($initial_body, true);

    if (!isset($initial_result['Status']) || (int)$initial_result['Status'] !== 0) {
        $status = isset($initial_result['Status']) ? $initial_result['Status'] : 'Неизвестно';
        $msg = "Ошибка API (update): Статус $status";
        owi_log($msg);
        update_option('owi_update_in_progress', false, false);
        return array('success' => false, 'message' => $msg);
    }

    $total = isset($initial_result['Total']) ? (int)$initial_result['Total'] : 0;
    if ($total <= 0) {
        $msg = 'API вернул Total=0, обновлять нечего.';
        owi_log($msg);
        update_option('owi_update_in_progress', false, false);
        return array('success' => false, 'message' => $msg);
    }

    $total_pages = (int)ceil($total / $per_page);
    $progress['total_pages'] = $total_pages;
    update_option('owi_update_progress', $progress, false);

    // Kick off first page
    wp_schedule_single_event(time(), 'owi_process_next_update_page', array(0, $api_url, $api_key));

    owi_log("Update started. Total: $total, pages: $total_pages, per_page: $per_page");

    return array('success' => true);
}

function owi_update_set_product_price_and_stock($product, $new_price, $new_stock_qty) {
    $changed = false;

    $old_price_raw = $product->get_regular_price();
    $old_price = is_numeric($old_price_raw) ? (float)$old_price_raw : 0.0;
    $new_price = (float)$new_price;

    if (abs($old_price - $new_price) > 0.0001) {
        $product->set_regular_price($new_price);
        $changed = true;
    }

    $old_stock_raw = $product->get_stock_quantity();
    $old_stock = is_numeric($old_stock_raw) ? (int)$old_stock_raw : 0;
    $new_stock_qty = (int)$new_stock_qty;

    // Always manage stock for imported items
    if (!$product->get_manage_stock()) {
        $product->set_manage_stock(true);
        $changed = true;
    }

    if ($old_stock !== $new_stock_qty) {
        $product->set_stock_quantity($new_stock_qty);
        $changed = true;
    }

    $new_status = $new_stock_qty > 0 ? 'instock' : 'outofstock';
    if ($product->get_stock_status() !== $new_status) {
        $product->set_stock_status($new_status);
        $changed = true;
    }

    return $changed;
}

function owi_process_update_page_cron($page, $api_url, $api_key) {
    // Prevent overlapping runs
    if (!owi_update_acquire_lock('update', 300)) {
        owi_log('Update page skipped due to active lock; will retry in 60s.');
        wp_schedule_single_event(time() + 60, 'owi_process_next_update_page', array($page, $api_url, $api_key));
        return;
    }

    try {
        if (get_option('owi_update_stop')) {
            owi_log('Update stopped by flag.');
            owi_update_reset_flags();
            return;
        }

        $progress = get_option('owi_update_progress');
        if (!is_array($progress)) {
            owi_log('Update progress missing; aborting.');
            owi_update_reset_flags();
            return;
        }

        $per_page = isset($progress['per_page']) ? (int)$progress['per_page'] : 200;
        $total_pages = isset($progress['total_pages']) ? (int)$progress['total_pages'] : 0;
        $current_page = isset($progress['current_page']) ? (int)$progress['current_page'] : 0;

        $data = array(
            'IsPrepay' => false,
            'From' => (int)$page,
            'Count' => (int)$per_page,
            'AddSupplierRests' => false,
            'Key' => $api_key,
        );

        $response = wp_remote_post($api_url, array(
            'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body' => wp_json_encode($data),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            $msg = 'Ошибка при подключении к API (update page): ' . $response->get_error_message();
            owi_log($msg);
            $progress['errors'] = isset($progress['errors']) ? ((int)$progress['errors'] + 1) : 1;
            $progress['last_update_time'] = time();
            update_option('owi_update_progress', $progress, false);

            // Retry later
            wp_schedule_single_event(time() + 120, 'owi_process_next_update_page', array($page, $api_url, $api_key));
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (!isset($result['Status']) || (int)$result['Status'] !== 0) {
            $status = isset($result['Status']) ? $result['Status'] : 'Неизвестно';
            $msg = "Ошибка API (update) при запросе страницы $current_page: Статус $status";
            owi_log($msg);
            $progress['errors'] = isset($progress['errors']) ? ((int)$progress['errors'] + 1) : 1;
            $progress['last_update_time'] = time();
            update_option('owi_update_progress', $progress, false);

            wp_schedule_single_event(time() + 300, 'owi_process_next_update_page', array($page, $api_url, $api_key));
            return;
        }

        if (empty($result['Result']) || !is_array($result['Result'])) {
            owi_log("Update: empty result on page $current_page; finishing.");
            update_option('owi_update_in_progress', false, false);
            return;
        }

        $total_processed = isset($progress['total_processed']) ? (int)$progress['total_processed'] : 0;
        $updated = isset($progress['updated']) ? (int)$progress['updated'] : 0;
        $unchanged = isset($progress['unchanged']) ? (int)$progress['unchanged'] : 0;
        $missing = isset($progress['missing']) ? (int)$progress['missing'] : 0;

        foreach ($result['Result'] as $product_data) {
            $total_processed++;

            $sku = isset($product_data['Number']) ? sanitize_text_field($product_data['Number']) : '';
            if ($sku === '') {
                $missing++;
                continue;
            }

            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                $missing++;
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                $missing++;
                continue;
            }

            $new_price = isset($product_data['Price']) ? (float)$product_data['Price'] : 0.0;

            $stock_quantity = 0;
            if (!empty($product_data['Rests']) && is_array($product_data['Rests'])) {
                foreach ($product_data['Rests'] as $rest) {
                    if (isset($rest['Value'])) {
                        $stock_quantity += (int)$rest['Value'];
                    }
                }
            }

            $changed = owi_update_set_product_price_and_stock($product, $new_price, $stock_quantity);
            if ($changed) {
                $saved_id = $product->save();
                if ($saved_id) {
                    $updated++;
                } else {
                    $progress['errors'] = isset($progress['errors']) ? ((int)$progress['errors'] + 1) : 1;
                }
            } else {
                $unchanged++;
            }
        }

        $current_page++;
        $progress['current_page'] = $current_page;
        $progress['total_processed'] = $total_processed;
        $progress['updated'] = $updated;
        $progress['unchanged'] = $unchanged;
        $progress['missing'] = $missing;
        $progress['last_update_time'] = time();
        update_option('owi_update_progress', $progress, false);

        owi_log("Update page done: $current_page/$total_pages processed=$total_processed updated=$updated unchanged=$unchanged missing=$missing");

        if ($total_pages > 0 && $current_page >= $total_pages) {
            owi_log('Update finished.');
            update_option('owi_update_in_progress', false, false);
            return;
        }

        $delay = (int)apply_filters('owi_update_delay', 10);
        if ($delay < 0) {
            $delay = 0;
        }

        wp_schedule_single_event(time() + $delay, 'owi_process_next_update_page', array($current_page, $api_url, $api_key));

    } finally {
        owi_update_release_lock('update');
    }
}

add_action('owi_process_next_update_page', 'owi_process_update_page_cron', 10, 3);

/**
 * Optional manual trigger (admin only).
 */
add_action('wp_ajax_owi_run_update_now', function () {
    check_ajax_referer('owi_import_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('У вас нет прав для выполнения этого действия.');
    }

    $result = owi_run_update(true);
    if (!empty($result['success'])) {
        wp_send_json_success('Обновление запущено.');
    }

    wp_send_json_error(isset($result['message']) ? $result['message'] : 'Не удалось запустить обновление.');
});
