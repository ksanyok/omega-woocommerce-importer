<?php
// Подключаем Action Scheduler, если он не подключен
if ( ! class_exists( 'ActionScheduler' ) && class_exists( 'WC' ) ) {
    include_once WC_ABSPATH . 'includes/libraries/action-scheduler/action-scheduler.php';
}

/**
 * Функция для форматирования времени (в секундах) в вид "дн ч мин сек".
 * Если дни = 0, то дни не показываем; если часы = 0, то часы не показываем и т.д.
 *
 * @param int $seconds
 * @return string
 */
function owi_format_time($seconds) {
    $days    = floor($seconds / 86400);
    $hours   = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs    = $seconds % 60;

    $parts = array();
    if ($days > 0) {
        $parts[] = $days . ' д';
    }
    if ($days > 0 || $hours > 0) {
        $parts[] = $hours . ' ч';
    }
    if ($days > 0 || $hours > 0 || $minutes > 0) {
        $parts[] = $minutes . ' мин';
    }
    $parts[] = $secs . ' сек';

    return implode(' ', $parts);
}

// Функция для отображения страницы обновления категорий и изображений
function owi_update_categories_images_page() {
    ?>
    <div class="wrap">
        <h1>Обновление категорий и изображений товаров</h1>
        <p>Настройки обновления:</p>
        <label for="owi-batch-size">Количество товаров в запросе:</label>
        <input type="number" id="owi-batch-size" name="owi-batch-size" value="10" min="1" max="1000">
        <br>
        <label for="owi-thread-count">Количество потоков:</label>
        <input type="number" id="owi-thread-count" name="owi-thread-count" value="1" min="1" max="10">
        <br><br>
        <button id="owi-start-update" class="button button-primary">Начать обновление</button>
        <button id="owi-stop-update" class="button button-secondary" style="margin-left:10px;" disabled>Остановить обновление</button>
        <div id="owi-update-result">
            <div id="owi-progress-bar" style="display:none; margin-top:20px;">
                <div id="owi-progress-status">
                    <div id="owi-progress" style="width: 0%; height: 20px; background-color: #17a2b8;"></div>
                </div>
                <div id="owi-progress-info" style="margin-top:10px;">
                    <span id="owi-progress-percentage">0%</span> | 
                    <span id="owi-progress-time">Время: 0 сек</span> | 
                    <span id="owi-progress-remaining">Осталось: -</span> | 
                    <span id="owi-progress-speed">Скорость: 0 товаров/мин</span> | 
                    <span id="owi-progress-updated">Обновлено: 0</span> | 
                    <span id="owi-progress-failed">Не удалось: 0</span> | 
                    <span id="owi-progress-remaining-products">Осталось товаров: 0</span>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// AJAX обработчик для запуска обновления
add_action('wp_ajax_owi_start_update', 'owi_start_update_callback');

function owi_start_update_callback() {
    // Проверка безопасности nonce
    check_ajax_referer('owi_update_nonce', 'nonce');

    // Проверка прав пользователя
    if (!current_user_can('manage_options')) {
        wp_send_json_error('У вас нет прав для выполнения этого действия.');
    }

    owi_log('Запрос на начало обновления получен.');

    // Получаем параметры из запроса
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
    $thread_count = isset($_POST['thread_count']) ? intval($_POST['thread_count']) : 1;

    // Ограничения на параметры
    if ($batch_size < 1 || $batch_size > 1000) {
        $batch_size = 10;
    }
    if ($thread_count < 1 || $thread_count > 10) {
        $thread_count = 1;
    }

    // Вызываем основную функцию обновления
    $result = owi_run_update_categories_images($batch_size, $thread_count);

    if ($result['success']) {
        owi_log('Обновление успешно инициализировано.');
        wp_send_json_success('Обновление начато.');
    } else {
        owi_log('Ошибка при инициализации обновления: ' . $result['message']);
        wp_send_json_error($result['message']);
    }
}

/**
 * Основная функция обновления, инициализирует процесс обновления.
 *
 * @param int $batch_size Количество товаров в запросе.
 * @param int $thread_count Количество потоков.
 * @return array Результат инициализации обновления.
 */
function owi_run_update_categories_images($batch_size = 10, $thread_count = 1) {
    owi_log('Инициализация процесса обновления категорий и изображений.');

    // Сбрасываем флаги обновления перед запуском нового обновления
    update_option('owi_update_in_progress', false);
    delete_option('owi_update_stop'); // Удаляем флаг остановки

    // Проверяем, идет ли уже обновление
    if (get_option('owi_update_in_progress')) {
        owi_log('Попытка запуска обновления, но уже идет процесс обновления.');
        return array('success' => false, 'message' => 'Обновление уже запущено.');
    }

    // Устанавливаем флаг обновления
    update_option('owi_update_in_progress', true);
    delete_option('owi_update_stop'); // Убедимся, что флаг остановки сброшен

    // Сохраняем количество потоков
    update_option('owi_thread_count', $thread_count);

    // Инициализируем прогресс
    $start_time = time();

    // Получаем товары в категории "Без категорії"
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'tax_query'      => array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'name',
                'terms'    => 'Без категорії',
                'operator' => 'IN',
            ),
        ),
    );

    owi_log('Запрос на получение товаров в категории "Без категорії".');
    $products = get_posts($args);
    $total_products = count($products);
    owi_log("Найдено $total_products товаров для обновления.");

    if ($total_products == 0) {
        owi_log('Нет товаров в категории "Без категорії" для обновления.');
        update_option('owi_update_in_progress', false);
        return array('success' => false, 'message' => 'Нет товаров в категории "Без категорії" для обновления.');
    }

    owi_log("Начало обновления $total_products товаров.");

    // Разделяем товары на несколько потоков
    $products_chunks = array_chunk($products, ceil($total_products / $thread_count));

    // Инициализируем общий прогресс
    $progress_data = array(
        'total_products' => $total_products,
        'start_time'     => $start_time,
        'last_update_time' => $start_time,
    );

    update_option('owi_update_progress', $progress_data);

    // Запускаем обработку для каждого потока (все сразу — без задержки)
    for ($i = 0; $i < count($products_chunks); $i++) {
        $thread_products = $products_chunks[$i];
        $thread_key = 'thread_' . $i;

        // Сохраняем прогресс для каждого потока
        $thread_progress = array(
            'products'         => $thread_products,
            'total_products'   => count($thread_products),
            'processed'        => 0,
            'updated'          => 0,
            'failed'           => 0,
            'start_time'       => $start_time,
            'last_update_time' => $start_time,
            'batch_size'       => $batch_size,
            'thread_key'       => $thread_key,
        );

        // Сохраняем прогресс потока в опцию
        update_option('owi_update_progress_' . $thread_key, $thread_progress);

        // Запускаем задачу через Action Scheduler (без задержки, чтобы дать шанс на реальную параллельность)
        as_schedule_single_action(time(), 'owi_process_update_batch_action', array($thread_key), 'owi_update');

        owi_log("Поток $thread_key запланирован на запуск (без задержки) через Action Scheduler.");
    }

    return array('success' => true);
}

// Регистрация задачи для Action Scheduler
add_action('owi_process_update_batch_action', 'owi_process_update_batch_cron', 10, 1);

// Функция для обработки батча обновлений
function owi_process_update_batch_cron($thread_key) {
    owi_log("Начало обработки очередного батча обновления для потока $thread_key.");

    // Проверяем флаг остановки
    if (get_option('owi_update_stop')) {
        owi_log('Обновление остановлено пользователем.');
        update_option('owi_update_in_progress', false);
        delete_option('owi_update_stop');
        return;
    }

    // Получаем текущий прогресс потока
    $progress = get_option('owi_update_progress_' . $thread_key);
    if (!$progress) {
        owi_log("Нет данных о прогрессе обновления для потока $thread_key.");
        return;
    }

    $batch_size       = $progress['batch_size'];
    $products         = $progress['products'];
    $total_products   = $progress['total_products'];
    $processed        = $progress['processed'];
    $updated          = $progress['updated'];
    $failed           = $progress['failed'];
    $start_time       = $progress['start_time'];
    $last_update_time = $progress['last_update_time'];

    owi_log("Обрабатываем батч для потока $thread_key: текущий прогресс - Обработано: $processed, Обновлено: $updated, Не удалось: $failed.");

    // Получаем следующий батч товаров
    $batch = array_slice($products, $processed, $batch_size);
    owi_log("Поток $thread_key: текущий батч содержит " . count($batch) . " товаров.");

    foreach ($batch as $product_id) {
        owi_log("Начинаем обработку товара ID: $product_id.");
        $card = get_post_meta($product_id, '_card', true);
        owi_log("CARD товара ID $product_id: $card.");

        if (empty($card)) {
            owi_log("Товар ID $product_id не имеет CARD. Пропуск.");
            $failed++;
            $processed++;
            continue;
        }

        // Нормализация CARD: убираем дефисы и приводим к верхнему регистру
        $normalized_card = strtoupper(str_replace('-', '', $card));
        owi_log("Нормализованный CARD для поиска: $normalized_card.");

        // Формируем URL для поиска
        $search_url = 'https://autodoc.ua/ua/search-result?searchPhrase=' . urlencode($normalized_card);
        owi_log("Сформированный URL поиска: $search_url.");

        // Получаем HTML страницы поиска
        $response = wp_remote_get($search_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0',
                'Accept'     => 'text/html',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Referer'    => 'https://autodoc.ua/ua/',
            ),
        ));

        if (is_wp_error($response)) {
            owi_log("Ошибка при запросе к $search_url: " . $response->get_error_message());
            $failed++;
            $processed++;
            continue;
        }

        $body = wp_remote_retrieve_body($response);
        owi_log("Получен ответ от $search_url.");

        // Парсим поисковые результаты, чтобы найти ссылку на конкретный товар
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $body)) {
            owi_log("Ошибка при загрузке HTML для CARD $card.");
            $failed++;
            $processed++;
            continue;
        }

        $xpath = new DOMXPath($dom);

        // Ищем все элементы с классом 'product-box__description'
        $nodes = $xpath->query("//div[contains(@class, 'product-box__description')]");
        owi_log("Найдено " . $nodes->length . " элементов с классом 'product-box__description'.");

        $product_found = false;
        $product_link = '';
        $image_url = '';

        if ($nodes->length == 1) {
            // Только один результат, берём его без сравнения
            $node = $nodes->item(0);
            $link_node = $xpath->query(".//a[contains(@class, 'product-box__name')]", $node);
            if ($link_node->length > 0) {
                $relative_link = $link_node->item(0)->getAttribute('href');
                $product_link = 'https://autodoc.ua' . $relative_link;
                owi_log("Найдена ссылка на товар: $product_link.");
                $product_found = true;
            } else {
                owi_log("Не удалось найти ссылку на товар для CARD $card.");
            }
        } elseif ($nodes->length > 1) {
            // Несколько результатов, сравниваем SKU
            $sku = get_post_meta($product_id, '_sku', true);
            $normalized_sku = strtoupper(str_replace(array('.', '-', ' '), '', $sku));
            owi_log("SKU товара ID $product_id: $sku. Нормализованный SKU: $normalized_sku.");

            foreach ($nodes as $node) {
                // Ищем SKU в выдаче
                $sku_nodes = $xpath->query(".//span[contains(@class, 'product-box__code-number')]", $node);
                foreach ($sku_nodes as $sku_node) {
                    $sku_text = trim($sku_node->nodeValue);
                    owi_log("Найденный SKU в поисковой выдаче: $sku_text.");

                    // Нормализуем SKU из выдачи
                    $normalized_sku_text = strtoupper(str_replace(array('.', '-', ' '), '', $sku_text));

                    if ($normalized_sku === $normalized_sku_text) {
                        owi_log("SKU совпадает: $normalized_sku === $normalized_sku_text.");
                        // Находим ссылку на товар
                        $link_node = $xpath->query(".//a[contains(@class, 'product-box__name')]", $node);
                        if ($link_node->length > 0) {
                            $relative_link = $link_node->item(0)->getAttribute('href');
                            $product_link = 'https://autodoc.ua' . $relative_link;
                            owi_log("Найдена ссылка на товар: $product_link.");
                            $product_found = true;
                            break 2; // Выходим из обоих циклов
                        } else {
                            owi_log("Не удалось найти ссылку на товар для SKU $sku.");
                        }
                    } else {
                        owi_log("SKU не совпадает: $normalized_sku !== $normalized_sku_text.");
                    }
                }
            }
        } else {
            owi_log("Товар с CARD $card не найден в результатах поиска.");
            $failed++;
            $processed++;
            continue;
        }

        if (!$product_found || empty($product_link)) {
            owi_log("Товар с CARD $card не найден или не удалось получить ссылку на товар.");
            $failed++;
            $processed++;
            continue;
        }

        // Теперь получаем страницу товара
        $product_response = wp_remote_get($product_link, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0',
                'Accept'     => 'text/html',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Referer'    => $search_url,
            ),
        ));

        if (is_wp_error($product_response)) {
            owi_log("Ошибка при запросе к странице товара $product_link: " . $product_response->get_error_message());
            $failed++;
            $processed++;
            continue;
        }

        $product_body = wp_remote_retrieve_body($product_response);
        owi_log("Получена страница товара по ссылке: $product_link.");

        // Парсим изображение из meta тега og:image
        if (preg_match('/<meta property="og:image" content="([^"]+)"/i', $product_body, $matches)) {
            $image_url = esc_url_raw($matches[1]);
            owi_log("Найден URL изображения: $image_url.");
        } else {
            owi_log("Не удалось найти изображение для CARD $card на странице товара.");
            $image_url = '';
        }

// --- Парсим хлебные крошки ---
$categories = array();

if (preg_match('/<ul[^>]*class="breadcrumbs[^"]*"[^>]*>(.*?)<\/ul>/si', $product_body, $matches)) {
    $breadcrumbs_html = $matches[0];
    owi_log("Найдено HTML для хлебных крошек.");

    $breadcrumbs_dom = new DOMDocument('1.0', 'UTF-8');
    if ($breadcrumbs_dom->loadHTML('<?xml encoding="UTF-8">' . $breadcrumbs_html)) {
        $breadcrumbs_xpath = new DOMXPath($breadcrumbs_dom);

        // Ищем все li[itemprop='itemListElement']
        $li_nodes = $breadcrumbs_xpath->query("//li[@itemprop='itemListElement']");

        foreach ($li_nodes as $li_node) {
            // Ищем meta itemprop='position'
            $pos_node = $breadcrumbs_xpath->query(".//meta[@itemprop='position']", $li_node);
            $position = '';
            if ($pos_node->length > 0) {
                $position = trim($pos_node->item(0)->getAttribute('content'));
            }

            // Ищем ссылку + span
            $a_nodes = $breadcrumbs_xpath->query(".//a[@itemprop='item']", $li_node);
            if ($a_nodes->length > 0) {
                $a_node = $a_nodes->item(0);
                $span_nodes = $breadcrumbs_xpath->query(".//span[@itemprop='name']", $a_node);
                if ($span_nodes->length > 0) {
                    $crumb_text = trim($span_nodes->item(0)->nodeValue);

                    // -----------------------------
                    //   ЛОГИКА ПРОПУСКА ВЕРХНЕГО УРОВНЯ
                    // -----------------------------

                    // Условие 1: Если position = 1 -> пропускаем
                    if ($position === '1') {
                        owi_log("Пропускаем хлебную крошку (position=1): $crumb_text");
                        continue;
                    }

                    // Условие 2: Если в названии есть «интернет-магазин» или «autodoc» (в любом регистре), — тоже пропускаем
                    // (можете расширить условие, если у вас ещё какие-то общие слова)
                    $crumb_text_lower = mb_strtolower($crumb_text);
                    if (
                        mb_strpos($crumb_text_lower, 'интернет-магазин') !== false
                        || mb_strpos($crumb_text_lower, 'autodoc') !== false
                    ) {
                        owi_log("Пропускаем хлебную крошку (подозрительно похоже на верхний уровень): $crumb_text");
                        continue;
                    }

                    // Если не попали под условия пропуска — добавляем в массив
                    owi_log("Берём хлебную крошку (pos=$position): $crumb_text");
                    $categories[] = $crumb_text;
                }
            }
        }

    } else {
        owi_log("Ошибка при загрузке HTML для хлебных крошек.");
    }
} else {
    owi_log("Не удалось найти хлебные крошки для CARD $card на странице товара.");
}




        // Обработка категорий
        if (!empty($categories)) {
            owi_log("Найдено " . count($categories) . " категорий для CARD $card.");
            // Удаляем категорию "Без категорії" из товара
            wp_remove_object_terms($product_id, 'Без категорії', 'product_cat');
            owi_log("Категория 'Без категорії' удалена из товара ID $product_id.");

            // Инициализируем родительский ID
            $parent_id = 0;
            foreach ($categories as $category_name) {
                // Проверяем, существует ли категория с данным именем и нужным родителем
                $term = get_term_by('name', $category_name, 'product_cat');
                if ($term && $term->parent == $parent_id) {
                    $term_id = $term->term_id;
                    owi_log("Категория '$category_name' найдена с ID: $term_id и родителем: $parent_id.");
                } else {
                    // Создаём категорию
                    owi_log("Категория '$category_name' не найдена. Создание новой категории с родителем ID: $parent_id.");
                    $new_term = wp_insert_term($category_name, 'product_cat', array('parent' => $parent_id));
                    if (is_wp_error($new_term)) {
                        owi_log("Ошибка при создании категории '$category_name': " . $new_term->get_error_message());
                        continue;
                    }
                    $term_id = $new_term['term_id'];
                    owi_log("Категория '$category_name' создана с ID: $term_id.");
                }

                $parent_id = $term_id; // Устанавливаем родительскую категорию для следующего уровня
            }

            // Назначаем последнюю категорию товару
            if (!empty($term_id)) {
                wp_set_object_terms($product_id, (int)$term_id, 'product_cat', false); // false для замены существующих категорий
                owi_log("Категория ID $term_id назначена товару ID $product_id.");
            }
        } else {
            owi_log("Для CARD $card не были найдены категории (кроме пропущенных верхних).");
        }

        // Обработка изображения
        if (!empty($image_url)) {
            owi_log("Обработка изображения для CARD $card.");
            // Проверяем, есть ли уже изображение
            $thumbnail_id = get_post_thumbnail_id($product_id);
            if (empty($thumbnail_id)) {
                owi_log("Изображение для товара ID $product_id отсутствует. Начинаем загрузку.");
                // Загружаем изображение
                $image_id = owi_download_image($image_url, $product_id);
                if ($image_id) {
                    set_post_thumbnail($product_id, $image_id);
                    owi_log("Изображение успешно загружено и назначено товару ID $product_id.");
                } else {
                    owi_log("Не удалось загрузить изображение для CARD $card.");
                }
            } else {
                owi_log("У товара ID $product_id уже установлено изображение (ID: $thumbnail_id).");
            }
        }

        $updated++;
        $processed++;
        owi_log("Товар ID $product_id успешно обработан. Обновлено: $updated, Не удалось: $failed.");
    }

    // Обновляем прогресс потока
    $progress['processed']        = $processed;
    $progress['updated']          = $updated;
    $progress['failed']           = $failed;
    $progress['last_update_time'] = time();

    update_option('owi_update_progress_' . $thread_key, $progress);
    owi_log("Поток $thread_key: обновление прогресса: Обработано $processed из $total_products товаров. Обновлено: $updated, Не удалось: $failed.");

    // Проверяем, есть ли ещё товары для обработки в этом потоке
    if ($processed < $total_products) {
        // Планируем следующий батч через Action Scheduler (без задержки)
        as_schedule_single_action(time(), 'owi_process_update_batch_action', array($thread_key), 'owi_update');
        owi_log("Поток $thread_key: запланирована обработка следующего батча (без задержки).");
    } else {
        owi_log("Поток $thread_key: обновление завершено. Всего обработано: $processed, Обновлено: $updated, Не удалось: $failed.");
        // Удаляем прогресс этого потока
        delete_option('owi_update_progress_' . $thread_key);

        // Проверяем, все ли потоки завершены
        $all_threads_completed = true;
        $thread_count = get_option('owi_thread_count');
        for ($i = 0; $i < $thread_count; $i++) {
            $other_thread_key = 'thread_' . $i;
            if (get_option('owi_update_progress_' . $other_thread_key)) {
                $all_threads_completed = false;
                break;
            }
        }
        if ($all_threads_completed) {
            owi_log("Все потоки завершены. Обновление полностью завершено.");
            update_option('owi_update_in_progress', false);
            delete_option('owi_update_progress');
            delete_option('owi_thread_count');
        }
    }
}


// AJAX обработчик для остановки обновления
add_action('wp_ajax_owi_stop_update', 'owi_stop_update_callback');

function owi_stop_update_callback() {
    // Проверка безопасности nonce
    check_ajax_referer('owi_update_nonce', 'nonce');

    // Проверка прав пользователя
    if (!current_user_can('manage_options')) {
        wp_send_json_error('У вас нет прав для выполнения этого действия.');
    }

    owi_log('Запрос на остановку обновления получен.');

    // Устанавливаем флаг остановки
    update_option('owi_update_stop', true);
    wp_send_json_success('Флаг остановки установлен. Обновление будет остановлено в ближайший момент.');
}

// AJAX обработчик для получения прогресса обновления
add_action('wp_ajax_owi_get_update_progress', 'owi_get_update_progress_callback');

function owi_get_update_progress_callback() {
    // Проверка безопасности nonce
    check_ajax_referer('owi_update_nonce', 'nonce');

    // Проверка прав пользователя
    if (!current_user_can('manage_options')) {
        wp_send_json_error('У вас нет прав для выполнения этого действия.');
    }

    // Получаем общий прогресс
    $total_progress = get_option('owi_update_progress');

    if (!$total_progress) {
        owi_log('Запрос прогресса, но данных о прогрессе обновления нет.');
        wp_send_json_error('Нет данных о прогрессе обновления.');
    }

    $total_products = $total_progress['total_products'];
    $start_time     = $total_progress['start_time'];

    // Инициализируем суммарные значения
    $processed        = 0;
    $updated          = 0;
    $failed           = 0;
    $last_update_time = $total_progress['last_update_time'];

    // Получаем количество потоков
    $thread_count = get_option('owi_thread_count');
    if (!$thread_count) {
        $thread_count = 1;
    }

    // Проходим по каждому потоку и суммируем данные
    for ($i = 0; $i < $thread_count; $i++) {
        $thread_key = 'thread_' . $i;
        $progress = get_option('owi_update_progress_' . $thread_key);
        if ($progress) {
            $processed += $progress['processed'];
            $updated   += $progress['updated'];
            $failed    += $progress['failed'];
            if ($progress['last_update_time'] > $last_update_time) {
                $last_update_time = $progress['last_update_time'];
            }
        }
    }

    // Вычисляем прошедшее время (секунды с начала)
    $elapsed_time = $last_update_time - $start_time;
    $elapsed_time_formatted = owi_format_time($elapsed_time);

    // Скорость (товаров в минуту)
    $elapsed_minutes = $elapsed_time / 60;
    $speed = ($elapsed_minutes > 0) ? round($processed / $elapsed_minutes) : 0;

    // Сколько товаров осталось
    $remaining = $total_products - $processed;

    // Оценка оставшегося времени (в минутах -> в дн ч мин сек)
    $estimated_remaining_time = ($speed > 0) ? round($remaining / $speed) : -1;
    $estimated_remaining_time_formatted = ($estimated_remaining_time >= 0)
        ? owi_format_time($estimated_remaining_time * 60)
        : '-';

    $percentage = $total_products > 0 ? round(($processed / $total_products) * 100) : 0;

    wp_send_json_success(array(
        'percentage'                        => $percentage,
        'processed'                         => $processed,
        'updated'                           => $updated,
        'failed'                            => $failed,
        'elapsed_time'                      => $elapsed_time_formatted,
        'estimated_remaining_time'          => $estimated_remaining_time_formatted,
        'speed'                             => $speed,
        'remaining_products'                => $remaining,
        'total_products'                    => $total_products,
    ));
}

/**
 * Функция для загрузки изображения по URL и привязки его к товару.
 *
 * @param string $image_url URL изображения.
 * @param int    $product_id ID товара.
 * @return int|false ID вложения или false в случае ошибки.
 */
function owi_download_image($image_url, $product_id) {
    owi_log("Начинаем загрузку изображения с URL: $image_url для товара ID: $product_id.");

    // Получаем данные изображения
    $response = wp_remote_get($image_url, array(
        'timeout' => 30,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0',
            'Accept'     => 'image/*',
            'Referer'    => 'https://autodoc.ua/',
        ),
    ));

    if (is_wp_error($response)) {
        owi_log("Ошибка при загрузке изображения $image_url: " . $response->get_error_message());
        return false;
    }

    $image_body = wp_remote_retrieve_body($response);
    $image_type = wp_remote_retrieve_header($response, 'content-type');

    // Проверяем тип изображения
    if (!in_array($image_type, array('image/jpeg', 'image/png', 'image/gif'))) {
        owi_log("Некорректный тип изображения $image_url: $image_type");
        return false;
    }

    // Определяем расширение файла
    $ext = '';
    if ($image_type == 'image/jpeg') {
        $ext = '.jpg';
    } elseif ($image_type == 'image/png') {
        $ext = '.png';
    } elseif ($image_type == 'image/gif') {
        $ext = '.gif';
    }

    // Генерируем уникальное имя файла
    $filename = 'owi_' . uniqid() . $ext;
    owi_log("Сгенерировано имя файла для изображения: $filename.");

    // Сохраняем изображение во временный файл
    $upload_dir = wp_upload_dir();
    $image_path = $upload_dir['path'] . '/' . $filename;

    $saved = file_put_contents($image_path, $image_body);
    if (!$saved) {
        owi_log("Не удалось сохранить изображение $image_url в $image_path.");
        return false;
    }
    owi_log("Изображение успешно сохранено в $image_path.");

    // Подготавливаем данные для вложения
    $filetype = wp_check_filetype($filename, null);
    $attachment = array(
        'guid'           => $upload_dir['url'] . '/' . $filename,
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name($filename),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );

    // Вставляем вложение в медиатеку
    $attach_id = wp_insert_attachment($attachment, $image_path, $product_id);
    if (!is_wp_error($attach_id)) {
        // Генерируем метаданные для вложения
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $image_path);
        wp_update_attachment_metadata($attach_id, $attach_data);
        owi_log("Вложение успешно добавлено в медиатеку с ID: $attach_id.");
        return $attach_id;
    } else {
        owi_log("Ошибка при вставке вложения для изображения $image_url: " . $attach_id->get_error_message());
        return false;
    }
}
