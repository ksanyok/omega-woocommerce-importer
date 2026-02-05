<?php
// Ensure this file is not accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Функция для отображения страницы импорта.
 */
function owi_import_page() {
    // Enqueue the script
    owi_enqueue_scripts();

    // Автоматически возобновить импорт, если он был в процессе (и не на паузе)
    owi_maybe_resume_import();

    ?>
    <div class="wrap">
        <h1>Импорт товаров из Omega</h1>
        <button id="owi-start-import" class="button button-primary">Начать импорт заново</button>
        <button id="owi-resume-import" class="button button-primary" style="margin-left:10px;">Продолжить незавершённый импорт</button>
        <button id="owi-pause-import" class="button button-secondary" style="margin-left:10px;" disabled>Пауза</button>
        <button id="owi-stop-import" class="button button-secondary" style="margin-left:10px;" disabled>Остановить импорт</button>
        <button id="owi-reset-import" class="button button-secondary" style="margin-left:10px;">Сбросить флаги</button>
        <div id="owi-import-result">
            <div id="owi-progress-bar" style="display:none; margin-top:20px;">
                <div id="owi-progress-status">
                    <div id="owi-progress" style="width: 0%; height: 20px; background-color: #28a745;"></div>
                </div>
                <div id="owi-progress-info" style="margin-top:10px;">
                    <span id="owi-progress-percentage">0%</span> | 
                    <span id="owi-progress-time">Время: 0 сек</span> | 
                    <span id="owi-progress-remaining">Осталось: -</span> | 
                    <span id="owi-progress-speed">Скорость: 0 товаров/мин</span> | 
                    <span id="owi-progress-total-processed">Обработано: 0</span> | 
                    <span id="owi-progress-new-imported">Новых: 0</span> | 
                    <span id="owi-progress-updated-imported">Обновлено: 0</span> | 
                    <span id="owi-progress-skipped">Пропущено: 0</span>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Функция для подключения и локализации скриптов.
 */
function owi_enqueue_scripts() {
    // Enqueue jQuery if not already
    wp_enqueue_script('jquery');

    // Enqueue the custom script (пример, если JS лежит в той же папке; адаптируйте путь при необходимости)
    wp_enqueue_script(
        'owi-import-script',
        plugin_dir_url(__FILE__) . 'js/owi-import.js', // Убедитесь, что путь к файлу корректный
        array('jquery'),
        '1.0',
        true
    );

    // Localize the script with AJAX URL and nonce
    wp_localize_script('owi-import-script', 'owi_ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('owi_import_nonce')
    ));
}

/**
 * Функция для автоматического возобновления импорта после сбоя, если НЕ стоит флаг паузы.
 */
function owi_maybe_resume_import() {
    // Проверяем, что импорт был в процессе
    if (get_option('owi_import_in_progress')) {

        // Если стоит флаг паузы, не возобновляем
        if (get_option('owi_import_pause')) {
            owi_custom_log("Импорт в процессе, но стоит флаг паузы. Автоматический рестарт не выполняем.");
            return;
        }

        $progress = get_option('owi_import_progress');
        if ($progress && isset($progress['current_page']) && $progress['current_page'] < $progress['total_pages']) {
            $api_key = get_option('owi_api_key');
            $api_url = 'https://public.omega.page/public/api/v1.0/product/pricelist/paged';
            $current_page = $progress['current_page'];

            // Планируем следующий пакет только если нет запланированных событий
            if (!owip_is_event_scheduled('owi_process_next_import_page', array($current_page, $api_url, $api_key))) {
                wp_schedule_single_event(time(), 'owi_process_next_import_page', array($current_page, $api_url, $api_key));
                owi_log("Автоматически планируется продолжение импорта с страницы $current_page.");
                owi_custom_log("Автоматически планируется продолжение импорта с страницы $current_page.");
            }
        } else {
            // Если прогресс отсутствует или импорт завершен, сбрасываем флаг
            update_option('owi_import_in_progress', false);
            delete_option('owi_import_progress');
            owi_log("Автоматическое возобновление импорта не требуется.");
            owi_custom_log("Автоматическое возобновление импорта не требуется.");
        }
    }
}

/**
 * Проверяет, запланировано ли событие с определёнными параметрами.
 *
 * @param string $hook Название хука.
 * @param array $args Аргументы события.
 * @return bool Возвращает true, если событие запланировано, иначе false.
 */
function owip_is_event_scheduled($hook, $args = array()) {
    if (!function_exists('wp_next_scheduled')) {
        return false;
    }
    $timestamp = wp_next_scheduled($hook, $args);
    return ($timestamp !== false);
}

/**
 * AJAX обработчик для запуска импорта заново.
 */
add_action('wp_ajax_owi_start_import', 'owi_start_import_callback');

function owi_start_import_callback() {
    // Security nonce check
    check_ajax_referer('owi_import_nonce', 'nonce');

    // User capability check
    if (!current_user_can('manage_options')) {
        wp_send_json_error('У вас нет прав для выполнения этого действия.');
    }

    $result = owi_run_import(true);

    if ($result['success']) {
        wp_send_json_success('Импорт начат.');
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * AJAX обработчик для возобновления импорта.
 */
add_action('wp_ajax_owi_resume_import', 'owi_resume_import_callback');

function owi_resume_import_callback() {
    check_ajax_referer('owi_import_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('У вас нет прав для выполнения этого действия.');
    }

    $result = owi_run_import(false);

    if ($result['success']) {
        wp_send_json_success('Импорт возобновлён.');
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * Основная функция импорта, инициализирует или возобновляет процесс.
 *
 * @param bool $fresh_start Если true, начать импорт заново, иначе попытаться продолжить.
 * @return array Результат выполнения.
 */
function owi_run_import($fresh_start = false) {
    if ($fresh_start) {
        // Сбросить прогресс и флаги
        update_option('owi_import_in_progress', false);
        delete_option('owi_import_stop');
        delete_option('owi_import_pause');
        delete_option('owi_import_progress');

        owi_log("Запуск нового импорта (fresh_start).");
        owi_custom_log("Запуск нового импорта (fresh_start).");
    }

    // Проверяем, если импорт уже в процессе
    if (get_option('owi_import_in_progress') && !$fresh_start) {
        // Проверяем, не на паузе ли импорт
        if (get_option('owi_import_pause')) {
            // Снимаем флаг паузы и продолжаем
            delete_option('owi_import_pause');

            $progress = get_option('owi_import_progress');
            if (!$progress) {
                return array('success' => false, 'message' => 'Невозможно возобновить: нет сохраненного прогресса.');
            }
            $page_to_resume = $progress['current_page'];
            $api_key = get_option('owi_api_key');
            $api_url = 'https://public.omega.page/public/api/v1.0/product/pricelist/paged';

            update_option('owi_import_in_progress', true);
            wp_schedule_single_event(time(), 'owi_process_next_import_page', array($page_to_resume, $api_url, $api_key));

            owi_custom_log("Импорт возобновлён с паузы. Текущая страница: $page_to_resume.");

            return array('success' => true, 'message' => 'Импорт возобновлён с паузы.');
        }

        // Если импорт уже идёт и не на паузе
        return array('success' => false, 'message' => 'Импорт уже запущен.');
    }

    // Если попытка возобновить, но прогресс отсутствует или импорт не в процессе
    if (!$fresh_start) {
        $progress = get_option('owi_import_progress');
        if (empty($progress) || !get_option('owi_import_in_progress')) {
            return array('success' => false, 'message' => 'Нет незавершенного импорта для продолжения.');
        }

        // Проверяем, не завершен ли импорт
        if ($progress['current_page'] >= $progress['total_pages']) {
            return array('success' => false, 'message' => 'Импорт уже завершен, нечего продолжать.');
        }

        // Снимаем флаги стопа и паузы
        delete_option('owi_import_stop');
        delete_option('owi_import_pause');

        update_option('owi_import_in_progress', true);
        $api_key = get_option('owi_api_key');
        $api_url = 'https://public.omega.page/public/api/v1.0/product/pricelist/paged';
        $current_page = $progress['current_page'];

        wp_schedule_single_event(time(), 'owi_process_next_import_page', array($current_page, $api_url, $api_key));
        owi_custom_log("Возобновляем импорт с текущей страницы: $current_page.");
        return array('success' => true, 'message' => 'Импорт возобновлён.');
    }

    // Начинаем импорт заново
    update_option('owi_import_in_progress', true);

    // Инициализируем прогресс
    $per_page = 500; // Количество товаров за запрос
    $start_time = time();

    $progress_data = array(
        'per_page'           => $per_page,
        'total_pages'        => 0,
        'current_page'       => 0,
        'total_processed'    => 0,
        'new_imported'       => 0,
        'updated_imported'   => 0,
        'skipped'            => 0,
        'start_time'         => $start_time,
        'last_update_time'   => $start_time,
        'requests_made'      => 0
    );

    update_option('owi_import_progress', $progress_data);

    // Получаем API ключ
    $api_key = get_option('owi_api_key');
    if (empty($api_key)) {
        update_option('owi_import_in_progress', false);
        return array('success' => false, 'message' => 'API ключ не установлен.');
    }

    // Настройки API
    $api_url = 'https://public.omega.page/public/api/v1.0/product/pricelist/paged';
    $page = 0;

    owi_log('Импорт начат.');
    owi_custom_log('Импорт начат (fresh start).');

    // Получаем общее количество товаров
    $initial_data = array(
        'IsPrepay' => false,
        'From' => 0,
        'Count' => 1,
        'AddSupplierRests' => false,
        'Key' => $api_key
    );

    $initial_response = wp_remote_post($api_url, array(
        'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
        'body' => json_encode($initial_data),
        'timeout' => 60
    ));

    if (is_wp_error($initial_response)) {
        $msg = 'Ошибка при подключении к API: ' . $initial_response->get_error_message();
        owi_log($msg);
        owi_custom_log($msg);
        update_option('owi_import_in_progress', false);
        return array('success' => false, 'message' => $msg);
    }

    $initial_body = wp_remote_retrieve_body($initial_response);
    $initial_result = json_decode($initial_body, true);

    if (!isset($initial_result['Status']) || $initial_result['Status'] != 0) {
        $status = isset($initial_result['Status']) ? $initial_result['Status'] : 'Неизвестно';
        $msg = "Ошибка API: Статус $status";
        owi_log($msg);
        owi_custom_log($msg);
        update_option('owi_import_in_progress', false);
        return array('success' => false, 'message' => $msg);
    }

    $total_products = isset($initial_result['Total']) ? intval($initial_result['Total']) : 0;
    owi_log("Общее количество товаров: $total_products");
    owi_custom_log("Общее количество товаров: $total_products");

    if ($total_products == 0) {
        owi_log('Нет товаров для импорта.');
        owi_custom_log('Нет товаров для импорта.');
        update_option('owi_import_in_progress', false);
        return array('success' => false, 'message' => 'Нет товаров для импорта.');
    }

    $total_pages = ceil($total_products / $per_page);
    owi_log("Общее количество страниц: $total_pages");
    owi_custom_log("Общее количество страниц: $total_pages");

    $progress_data['total_pages'] = $total_pages;
    update_option('owi_import_progress', $progress_data);

    // Планируем первый пакет
    wp_schedule_single_event(time(), 'owi_process_next_import_page', array($page, $api_url, $api_key));

    return array('success' => true);
}

/**
 * Функция для обработки каждого пакета импорта (Cron callback).
 *
 * @param int $page Номер текущей страницы.
 * @param string $api_url URL API.
 * @param string $api_key API ключ.
 */
function owi_process_import_page_cron($page, $api_url, $api_key) {
    // Проверяем флаг остановки импорта
    if (get_option('owi_import_stop')) {
        owi_log('Импорт остановлен пользователем (stop flag).');
        owi_custom_log('Импорт остановлен пользователем (stop flag).');
        update_option('owi_import_in_progress', false);
        delete_option('owi_import_stop');
        return;
    }

    // Проверяем флаг паузы
    if (get_option('owi_import_pause')) {
        owi_log('Импорт поставлен на паузу (pause flag).');
        owi_custom_log('Импорт поставлен на паузу (pause flag).');
        update_option('owi_import_in_progress', false);
        return;
    }

    // Получаем текущий прогресс
    $progress = get_option('owi_import_progress');
    if (!$progress) {
        owi_log('Нет данных о прогрессе импорта. Прерываем.');
        owi_custom_log('Нет данных о прогрессе импорта. Прерываем.');
        update_option('owi_import_in_progress', false);
        return;
    }

    $per_page = isset($progress['per_page']) ? intval($progress['per_page']) : 500;
    $total_pages = isset($progress['total_pages']) ? intval($progress['total_pages']) : 0;
    $current_page = isset($progress['current_page']) ? intval($progress['current_page']) : 0;
    $total_processed = isset($progress['total_processed']) ? intval($progress['total_processed']) : 0;
    $new_imported = isset($progress['new_imported']) ? intval($progress['new_imported']) : 0;
    $updated_imported = isset($progress['updated_imported']) ? intval($progress['updated_imported']) : 0;
    $skipped = isset($progress['skipped']) ? intval($progress['skipped']) : 0;
    $start_time = isset($progress['start_time']) ? intval($progress['start_time']) : time();
    $last_update_time = isset($progress['last_update_time']) ? intval($progress['last_update_time']) : time();
    $requests_made = isset($progress['requests_made']) ? intval($progress['requests_made']) : 0;

    owi_custom_log("Начало обработки страницы $current_page из $total_pages.");

    // Подготавливаем данные для запроса
    $data = array(
        'IsPrepay' => false,
        'From' => $page,
        'Count' => $per_page,
        'AddSupplierRests' => false,
        'Key' => $api_key
    );

    // Выполняем запрос к API
    $response = wp_remote_post($api_url, array(
        'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
        'body'    => json_encode($data),
        'timeout' => 60
    ));

    $requests_made++;
    $progress['requests_made'] = $requests_made;

    if (is_wp_error($response)) {
        $msg = 'Ошибка при подключении к API: ' . $response->get_error_message();
        owi_log($msg);
        owi_custom_log($msg);
        update_option('owi_import_in_progress', false);
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if (!isset($result['Status']) || $result['Status'] != 0) {
        $status = isset($result['Status']) ? $result['Status'] : 'Неизвестно';
        $msg = "Ошибка API при запросе страницы $current_page: Статус $status";
        owi_log($msg);
        owi_custom_log($msg);
        update_option('owi_import_in_progress', false);
        return;
    }

    if (empty($result['Result'])) {
        owi_log("Нет больше товаров для импорта на странице $current_page.");
        owi_custom_log("Нет больше товаров для импорта на странице $current_page.");
        update_option('owi_import_in_progress', false);
        return;
    }

    // Обрабатываем товары
    foreach ($result['Result'] as $product_data) {
        $total_processed++;

        // Фильтруем по наличию
        $in_stock = false;
        if (!empty($product_data['Rests'])) {
            foreach ($product_data['Rests'] as $rest) {
                if (isset($rest['Value']) && intval($rest['Value']) > 0) {
                    $in_stock = true;
                    break;
                }
            }
        }

        if (!$in_stock) {
            $skipped++;
            continue; // Пропускаем товар, если нет в наличии
        }

        // Сохраняем или обновляем товар
        $saved = owi_save_product($product_data);

        if ($saved === 'new') {
            $new_imported++;
        } elseif ($saved === 'updated') {
            $updated_imported++;
        } else {
            $skipped++; // Если сохранение не удалось или товар пропущен
        }
    }

    // Обновляем прогресс
    $current_page++;
    $progress['current_page'] = $current_page;
    $progress['total_processed'] = $total_processed;
    $progress['new_imported'] = $new_imported;
    $progress['updated_imported'] = $updated_imported;
    $progress['skipped'] = $skipped;
    $progress['last_update_time'] = time();
    $progress['requests_made'] = 0; // Сбрасываем счётчик запросов

    update_option('owi_import_progress', $progress);

    $msg_done = "Страница $current_page обработана. Обработано товаров: $total_processed, Новых: $new_imported, Обновлено: $updated_imported, Пропущено: $skipped";
    owi_log($msg_done);
    owi_custom_log($msg_done);

    // Проверяем, достигли ли конца
    if ($current_page >= $total_pages) {
        $final_msg = "Импорт завершен. Всего обработано товаров: $total_processed, Новых: $new_imported, Обновлено: $updated_imported, Пропущено: $skipped";
        owi_log($final_msg);
        owi_custom_log($final_msg);
        update_option('owi_import_in_progress', false);
        return;
    }

    // Планируем следующий пакет с задержкой
    $delay = 5; // секунды
    wp_schedule_single_event(time() + $delay, 'owi_process_next_import_page', array($current_page, $api_url, $api_key));
    owi_custom_log("Планируем следующую страницу: $current_page (из $total_pages) через $delay секунд.");
}

/**
 * Хук для обработки следующего пакета импорта.
 */
add_action('owi_process_next_import_page', 'owi_process_import_page_cron', 10, 3);

/**
 * AJAX обработчик для остановки импорта.
 */
add_action('wp_ajax_owi_stop_import', 'owi_stop_import_callback');

function owi_stop_import_callback() {
    check_ajax_referer('owi_import_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('У вас нет прав для выполнения этого действия.');
    }
    update_option('owi_import_stop', true);
    wp_send_json_success('Флаг остановки установлен. Импорт будет остановлен в ближайший момент.');
}

/**
 * AJAX обработчик для приостановки импорта.
 */
add_action('wp_ajax_owi_pause_import', 'owi_pause_import_callback');

function owi_pause_import_callback() {
    check_ajax_referer('owi_import_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('У вас нет прав для выполнения этого действия.');
    }
    update_option('owi_import_pause', true);
    wp_send_json_success('Импорт будет поставлен на паузу в ближайший момент.');
}

/**
 * AJAX обработчик для сброса флагов импорта.
 */
add_action('wp_ajax_owi_reset_import', 'owi_reset_import_callback');

function owi_reset_import_callback() {
    check_ajax_referer('owi_import_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('У вас нет прав для выполнения этого действия.');
    }

    // Сброс всех флагов и прогресса
    delete_option('owi_import_in_progress');
    delete_option('owi_import_stop');
    delete_option('owi_import_pause');
    delete_option('owi_import_progress');

    // Сбрасываем логи (если хотите при сбросе удалять логи)
    //delete_option('owi_import_debug_log');

    wp_send_json_success('Все флаги и прогресс сброшены.');
}

/**
 * AJAX обработчик для получения прогресса импорта.
 */
add_action('wp_ajax_owi_get_import_progress', 'owi_get_import_progress_callback');

function owi_get_import_progress_callback() {
    check_ajax_referer('owi_import_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('У вас нет прав для выполнения этого действия.');
    }

    $progress = get_option('owi_import_progress');

    if (!$progress) {
        wp_send_json_error('Нет данных о прогрессе импорта.');
    }

    $per_page = isset($progress['per_page']) ? intval($progress['per_page']) : 500;
    $total_pages = isset($progress['total_pages']) ? intval($progress['total_pages']) : 0;
    $current_page = isset($progress['current_page']) ? intval($progress['current_page']) : 0;
    $total_processed = isset($progress['total_processed']) ? intval($progress['total_processed']) : 0;
    $new_imported = isset($progress['new_imported']) ? intval($progress['new_imported']) : 0;
    $updated_imported = isset($progress['updated_imported']) ? intval($progress['updated_imported']) : 0;
    $skipped = isset($progress['skipped']) ? intval($progress['skipped']) : 0;
    $start_time = isset($progress['start_time']) ? intval($progress['start_time']) : time();
    $last_update_time = isset($progress['last_update_time']) ? intval($progress['last_update_time']) : time();

    $elapsed_time = $last_update_time - $start_time; // В секундах
    $elapsed_minutes = $elapsed_time / 60;
    $speed = ($elapsed_minutes > 0) ? round($total_processed / $elapsed_minutes) : 0; // товаров в минуту

    $remaining_pages = $total_pages - $current_page;
    $estimated_remaining_time = ($speed > 0) 
        ? round((($remaining_pages * $per_page) / $speed) / 60) 
        : -1; // В минутах

    $percentage = ($total_pages > 0)
        ? round(($current_page / $total_pages) * 100)
        : 0;

    wp_send_json_success(array(
        'percentage' => $percentage,
        'total_processed' => $total_processed,
        'new_imported' => $new_imported,
        'updated_imported' => $updated_imported,
        'skipped' => $skipped,
        'elapsed_time' => $elapsed_time,
        'estimated_remaining_time' => $estimated_remaining_time,
        'speed' => $speed
    ));
}

/**
 * Функция для сохранения или обновления товара в WooCommerce.
 *
 * @param array $product_data Данные товара из API.
 * @return string|bool 'new' если создан новый товар, 'updated' если обновлен существующий, false если ошибка.
 */
function owi_save_product($product_data) {
    // Проверяем наличие SKU (Number)
    $sku = isset($product_data['Number']) ? sanitize_text_field($product_data['Number']) : '';
    if (empty($sku)) {
        $msg = 'Отсутствует SKU для продукта: ' . print_r($product_data, true);
        owi_log($msg);
        owi_custom_log($msg);
        return false;
    }

    $existing_product_id = wc_get_product_id_by_sku($sku);

    if ($existing_product_id) {
        // Обновляем существующий товар
        $product = wc_get_product($existing_product_id);
        if (!$product) {
            $msg = "Товар с SKU $sku не найден при update. Создаём заново.";
            owi_log($msg);
            owi_custom_log($msg);
            $product = new WC_Product();
            $product->set_sku($sku);
        }

        // Обновляем цену
        $price = isset($product_data['Price']) ? floatval($product_data['Price']) : 0;
        $product->set_regular_price($price);

        // Обновляем количество на складе
        $stock_quantity = 0;
        if (!empty($product_data['Rests'])) {
            foreach ($product_data['Rests'] as $rest) {
                if (isset($rest['Value'])) {
                    $stock_quantity += intval($rest['Value']);
                }
            }
        }
        $product->set_stock_quantity($stock_quantity);
        $product->set_manage_stock(true);
        $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');

        // Обновляем метаданные
        $code_twhed_id = isset($product_data['CodeTWHED']) ? sanitize_text_field($product_data['CodeTWHED']) : '';
        $card = isset($product_data['Card']) ? sanitize_text_field($product_data['Card']) : '';
        $product->update_meta_data('_code_twhed_id', $code_twhed_id);
        $product->update_meta_data('_card', $card);

        // Обновляем атрибут 'Brand'
        owi_set_product_brand_attribute($product, isset($product_data['BrandDescription']) ? $product_data['BrandDescription'] : '');

        // Сохраняем товар
        $product_id = $product->save();

        return $product_id ? 'updated' : false;

    } else {
        // Создаём новый товар
        $product = new WC_Product();

        // Устанавливаем данные товара
        $product->set_name(trim(isset($product_data['DescriptionUkr']) ? $product_data['DescriptionUkr'] : 'Без названия'));
        $product->set_regular_price(isset($product_data['Price']) ? floatval($product_data['Price']) : 0);
        $product->set_description(trim(isset($product_data['DescriptionUkr']) ? $product_data['DescriptionUkr'] : ''));
        $product->set_short_description(trim(isset($product_data['DescriptionUkr']) ? $product_data['DescriptionUkr'] : ''));
        $product->set_weight(isset($product_data['Weight']) ? floatval($product_data['Weight']) : 0);

        // Устанавливаем SKU
        $product->set_sku($sku);

        // Устанавливаем количество на складе
        $stock_quantity = 0;
        if (!empty($product_data['Rests'])) {
            foreach ($product_data['Rests'] as $rest) {
                if (isset($rest['Value'])) {
                    $stock_quantity += intval($rest['Value']);
                }
            }
        }
        $product->set_stock_quantity($stock_quantity);
        $product->set_manage_stock(true);
        $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');

        // Сохраняем товар для получения ID
        $product_id = $product->save();
        if (!$product_id) {
            $msg = 'Не удалось создать новый продукт с SKU: ' . $sku;
            owi_log($msg);
            owi_custom_log($msg);
            return false;
        }

        // Обновляем метаданные
        $code_twhed_id = isset($product_data['CodeTWHED']) ? sanitize_text_field($product_data['CodeTWHED']) : '';
        $card = isset($product_data['Card']) ? sanitize_text_field($product_data['Card']) : '';
        update_post_meta($product_id, '_code_twhed_id', $code_twhed_id);
        update_post_meta($product_id, '_card', $card);

        // Устанавливаем атрибут 'Brand'
        owi_set_product_brand_attribute($product, isset($product_data['BrandDescription']) ? $product_data['BrandDescription'] : '');

        // Сохраняем товар снова после обновления атрибутов
        $product->save();

        return 'new';
    }
}

/**
 * Функция для установки атрибута 'Brand' товара.
 *
 * @param WC_Product $product Объект товара.
 * @param string $brand_description Описание бренда из API.
 */
function owi_set_product_brand_attribute($product, $brand_description) {
    if (empty($brand_description)) {
        return;
    }

    $brand_name = preg_replace('/^1\./', '', trim($brand_description));
    $brand_name = trim($brand_name);

    // Санитизация имени бренда
    $brand_name = wp_strip_all_tags($brand_name);
    $brand_name = sanitize_text_field($brand_name);

    if (empty($brand_name)) {
        $msg = 'Бренд имеет пустое или некорректное имя после санитизации: ' . $brand_description;
        owi_log($msg);
        owi_custom_log($msg);
        return;
    }

    $attribute_name = 'Brand';
    $attribute_slug = 'pa_brand';

    // Проверяем, существует ли атрибут
    $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_name);

    if (!$attribute_id) {
        $attribute_id = wc_create_attribute(array(
            'name' => $attribute_name,
            'slug' => sanitize_title($attribute_name),
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => true,
        ));
        if (is_wp_error($attribute_id)) {
            $err = 'Ошибка при создании атрибута "Brand": ' . $attribute_id->get_error_message();
            owi_log($err);
            owi_custom_log($err);
            return;
        }
        // Регистрируем таксономию
        register_taxonomy($attribute_slug, array('product'), array(
            'hierarchical' => false,
            'labels' => array(
                'name' => $attribute_name,
                'singular_name' => $attribute_name,
            ),
            'show_ui' => false,
            'query_var' => true,
            'rewrite' => array('slug' => sanitize_title($attribute_name)),
            'has_archives' => true,
        ));
    }

    // Добавляем термин бренда
    $brand_slug = sanitize_title($brand_name);
    $term = get_term_by('slug', $brand_slug, $attribute_slug);

    if (!$term) {
        $term = wp_insert_term($brand_name, $attribute_slug, array('slug' => $brand_slug));
        if (is_wp_error($term)) {
            $err = 'Ошибка при создании термина бренда: ' . $term->get_error_message();
            owi_log($err);
            owi_custom_log($err);
            return;
        }
        $term_id = $term['term_id'];
    } else {
        $term_id = $term->term_id;
    }

    $attributes = $product->get_attributes();
    $found = false;
    foreach ($attributes as $attr_key => $attr) {
        if ($attr->get_name() === $attribute_slug) {
            $found = true;
            $attr->set_options(array($term_id));
            $attributes[$attr_key] = $attr;
            break;
        }
    }

    if (!$found) {
        $product_attribute = new WC_Product_Attribute();
        $product_attribute->set_id($attribute_id);
        $product_attribute->set_name($attribute_slug);
        $product_attribute->set_options(array($term_id));
        $product_attribute->set_position(0);
        $product_attribute->set_visible(true);
        $product_attribute->set_variation(false);
        $attributes[$attribute_slug] = $product_attribute;
    }

    $product->set_attributes($attributes);
}

/**
 * Добавляет поле 'Card' на страницу редактирования товара.
 */
add_action('woocommerce_product_options_sku', 'owi_add_card_field');
function owi_add_card_field() {
    woocommerce_wp_text_input(array(
        'id' => '_card',
        'label' => __('Card', 'woocommerce'),
        'description' => __('Значение Card из Omega API.', 'woocommerce'),
        'desc_tip' => true,
    ));
}

/**
 * Сохраняет значение поля 'Card' при сохранении товара.
 *
 * @param int $post_id ID товара.
 */
add_action('woocommerce_process_product_meta', 'owi_save_card_field');
function owi_save_card_field($post_id) {
    $card = isset($_POST['_card']) ? sanitize_text_field($_POST['_card']) : '';
    update_post_meta($post_id, '_card', $card);
}

/**
 * Добавляет поле '_code_twhed_id' на страницу редактирования товара.
 */
add_action('woocommerce_product_options_sku', 'owi_add_code_twhed_id_field');
function owi_add_code_twhed_id_field() {
    woocommerce_wp_text_input(array(
        'id' => '_code_twhed_id',
        'label' => __('CodeTWHED', 'woocommerce'),
        'description' => __('Введите штрихкод или любой другой уникальный идентификатор товара.', 'woocommerce'),
        'desc_tip' => true,
    ));
}

/**
 * Сохраняет значение поля '_code_twhed_id' при сохранении товара.
 *
 * @param int $post_id ID товара.
 */
add_action('woocommerce_process_product_meta', 'owi_save_code_twhed_id_field');
function owi_save_code_twhed_id_field($post_id) {
    $global_unique_id = isset($_POST['_code_twhed_id']) ? sanitize_text_field($_POST['_code_twhed_id']) : '';
    update_post_meta($post_id, '_code_twhed_id', $global_unique_id);
}

/**
 * Вспомогательная функция для логирования в error_log (при WP_DEBUG = true).
 *
 * @param string $message Сообщение для логирования.
 */
if (!function_exists('owi_log')) {
    function owi_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log('[OWI Import] ' . $message);
        }
    }
}

/**
 * Вспомогательная функция для собственного логирования в БД (опция "owi_import_debug_log").
 *
 * @param string $message Сообщение для логирования.
 */
function owi_custom_log($message) {
    $logs = get_option('owi_import_debug_log', array());
    $time = date('Y-m-d H:i:s');
    $logs[] = "[$time] $message";

    // Можно ограничить размер лога, чтобы не разрастался (например, до 1000 записей)
    if (count($logs) > 1000) {
        array_shift($logs); // Удаляем первую запись, чтобы освободить место
    }

    update_option('owi_import_debug_log', $logs);
}
