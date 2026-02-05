<?php
// Предотвращаем прямой доступ
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Функция для отображения страницы расписания
 */
function owi_schedule_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Недостаточно прав.' );
    }

    // Обработка отправки формы
    if ( isset( $_POST['owi_schedule_nonce'] ) && wp_verify_nonce( $_POST['owi_schedule_nonce'], 'owi_save_schedule' ) ) {
        // Сохранение настроек расписания
        $interval  = sanitize_text_field( $_POST['owi_auto_import_interval'] );
        $time      = sanitize_text_field( $_POST['owi_auto_import_time'] );
        $job       = isset($_POST['owi_auto_job']) ? sanitize_text_field($_POST['owi_auto_job']) : 'update';
        $is_active = isset( $_POST['owi_auto_import_active'] ) ? 1 : 0;

        update_option( 'owi_auto_import_interval', $interval );
        update_option( 'owi_auto_import_time', $time );
        update_option( 'owi_auto_job', $job );
        update_option( 'owi_auto_import_active', $is_active );

        // Очистка существующих событий расписания
        wp_clear_scheduled_hook( 'owi_scheduled_import' );

        if ( $is_active ) {
            // Планирование нового события
            owi_schedule_import_event();
        }

        echo '<div class="updated"><p>Настройки расписания сохранены.</p></div>';
    }

    // Ручной запуск обновления сейчас
    if ( isset( $_POST['owi_run_update_nonce'] ) && wp_verify_nonce( $_POST['owi_run_update_nonce'], 'owi_run_update_now' ) ) {
        $result = function_exists('owi_run_update') ? owi_run_update(true) : array('success' => false, 'message' => 'Функция обновления недоступна.');
        if ( ! empty( $result['success'] ) ) {
            echo '<div class="updated"><p>Обновление запущено.</p></div>';
        } else {
            $msg = isset($result['message']) ? $result['message'] : 'Не удалось запустить обновление.';
            echo '<div class="error"><p>' . esc_html($msg) . '</p></div>';
        }
    }

    // Остановка обновления
    if ( isset( $_POST['owi_stop_update_nonce'] ) && wp_verify_nonce( $_POST['owi_stop_update_nonce'], 'owi_stop_update' ) ) {
        update_option('owi_update_stop', true, false);
        echo '<div class="updated"><p>Запрошена остановка обновления. Процесс завершится на ближайшем батче.</p></div>';
    }

    // Ручной запуск импорта сейчас
    if ( isset( $_POST['owi_run_import_nonce'] ) && wp_verify_nonce( $_POST['owi_run_import_nonce'], 'owi_run_import_now' ) ) {
        if ( get_option( 'owi_import_in_progress' ) ) {
            echo '<div class="error"><p>Импорт уже запущен.</p></div>';
        } else {
            $result = function_exists('owi_run_import') ? owi_run_import(true) : array('success' => false, 'message' => 'Функция импорта недоступна.');
            if ( ! empty( $result['success'] ) ) {
                echo '<div class="updated"><p>Импорт запущен.</p></div>';
            } else {
                $msg = isset($result['message']) ? $result['message'] : 'Не удалось запустить импорт.';
                echo '<div class="error"><p>' . esc_html($msg) . '</p></div>';
            }
        }
    }

    // Обработка сброса флага импорта
    if ( isset( $_POST['owi_reset_import_nonce'] ) && wp_verify_nonce( $_POST['owi_reset_import_nonce'], 'owi_reset_import' ) ) {
        delete_option( 'owi_import_in_progress' );
        delete_option( 'owi_import_progress' );
        delete_option( 'owi_import_stop' );
        echo '<div class="updated"><p>Флаг импорта сброшен.</p></div>';
    }

    // Обработка сброса флага обновления
    if ( isset( $_POST['owi_reset_update_nonce'] ) && wp_verify_nonce( $_POST['owi_reset_update_nonce'], 'owi_reset_update' ) ) {
        delete_option( 'owi_update_in_progress' );
        delete_option( 'owi_update_progress' );
        delete_option( 'owi_update_stop' );
        echo '<div class="updated"><p>Флаг обновления сброшен.</p></div>';
    }

    // Получение текущих настроек
    $interval  = get_option( 'owi_auto_import_interval', 'daily' );
    $time      = get_option( 'owi_auto_import_time', '03:00' );
    $job       = get_option( 'owi_auto_job', 'update' );
    $is_active = get_option( 'owi_auto_import_active', 0 );

    // Текущее время сервера
    $current_time = wp_date( 'd.m.Y H:i:s', time(), wp_timezone() );

    // Время следующего запланированного запуска
    $timestamp = wp_next_scheduled( 'owi_scheduled_import' );
    if ( $timestamp ) {
        $next_import_time = wp_date( 'd.m.Y H:i:s', $timestamp, wp_timezone() );
    } else {
        $next_import_time = 'Не запланирован';
    }

    // Проверка, идет ли импорт/обновление в данный момент
    $import_in_progress = get_option( 'owi_import_in_progress', false );
    $update_in_progress = get_option( 'owi_update_in_progress', false );

    // Прогресс импорта
    $import_progress   = get_option( 'owi_import_progress', array() );
    $import_percentage = 0;
    $import_total      = 0;
    $import_new        = 0;
    $import_updated    = 0;
    $import_skipped    = 0;
    $import_last       = '';

    if ( is_array($import_progress) && ! empty($import_progress) ) {
        $total_pages  = isset( $import_progress['total_pages'] ) ? (int)$import_progress['total_pages'] : 0;
        $current_page = isset( $import_progress['current_page'] ) ? (int)$import_progress['current_page'] : 0;
        if ( $total_pages > 0 ) {
            $import_percentage = (int)round( ( $current_page / $total_pages ) * 100 );
        }
        $import_total   = isset($import_progress['total_processed']) ? (int)$import_progress['total_processed'] : 0;
        $import_new     = isset($import_progress['new_imported']) ? (int)$import_progress['new_imported'] : 0;
        $import_updated = isset($import_progress['updated_imported']) ? (int)$import_progress['updated_imported'] : 0;
        $import_skipped = isset($import_progress['skipped']) ? (int)$import_progress['skipped'] : 0;
        if (isset($import_progress['last_update_time'])) {
            $import_last = wp_date('d.m.Y H:i:s', (int)$import_progress['last_update_time'], wp_timezone());
        }
    }

    // Прогресс обновления
    $update_progress   = get_option( 'owi_update_progress', array() );
    $update_percentage = 0;
    $update_total      = 0;
    $update_updated    = 0;
    $update_unchanged  = 0;
    $update_missing    = 0;
    $update_errors     = 0;
    $update_last       = '';

    if ( is_array($update_progress) && ! empty($update_progress) ) {
        $total_pages  = isset( $update_progress['total_pages'] ) ? (int)$update_progress['total_pages'] : 0;
        $current_page = isset( $update_progress['current_page'] ) ? (int)$update_progress['current_page'] : 0;
        if ( $total_pages > 0 ) {
            $update_percentage = (int)round( ( $current_page / $total_pages ) * 100 );
        }
        $update_total     = isset($update_progress['total_processed']) ? (int)$update_progress['total_processed'] : 0;
        $update_updated   = isset($update_progress['updated']) ? (int)$update_progress['updated'] : 0;
        $update_unchanged = isset($update_progress['unchanged']) ? (int)$update_progress['unchanged'] : 0;
        $update_missing   = isset($update_progress['missing']) ? (int)$update_progress['missing'] : 0;
        $update_errors    = isset($update_progress['errors']) ? (int)$update_progress['errors'] : 0;
        if (isset($update_progress['last_update_time'])) {
            $update_last = wp_date('d.m.Y H:i:s', (int)$update_progress['last_update_time'], wp_timezone());
        }
    }
    ?>
    <div class="wrap">
        <h1>Настройки расписания импорта</h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'owi_save_schedule', 'owi_schedule_nonce' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Тип задачи</th>
                    <td>
                        <select name="owi_auto_job">
                            <option value="update" <?php selected( $job, 'update' ); ?>>Обновление (цена + наличие по партномеру/SKU)</option>
                            <option value="import" <?php selected( $job, 'import' ); ?>>Полный импорт (создание/обновление)</option>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Интервал обновления</th>
                    <td>
                        <select name="owi_auto_import_interval">
                            <option value="daily" <?php selected( $interval, 'daily' ); ?>>Ежедневно</option>
                            <option value="every_other_day" <?php selected( $interval, 'every_other_day' ); ?>>Через день</option>
                            <option value="weekly" <?php selected( $interval, 'weekly' ); ?>>Еженедельно</option>
                            <option value="monthly" <?php selected( $interval, 'monthly' ); ?>>Ежемесячно</option>
                        </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Время запуска</th>
                    <td>
                        <input type="time" name="owi_auto_import_time" value="<?php echo esc_attr( $time ); ?>">
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Автоматический импорт</th>
                    <td>
                        <label>
                            <input type="checkbox" name="owi_auto_import_active" value="1" <?php checked( $is_active, 1 ); ?>> Активировать автоматический импорт по расписанию
                        </label>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Текущее время сервера</th>
                    <td>
                        <span id="current-time"><?php echo $current_time; ?></span>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Следующий запланированный запуск</th>
                    <td>
                        <span id="next-import-time"><?php echo $next_import_time; ?></span>
                        <p class="description">Тип: <strong><?php echo ($job === 'import') ? 'полный импорт' : 'обновление цены/наличия'; ?></strong></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Сохранить настройки' ); ?>
        </form>

        <hr />

        <h2>Ручной запуск</h2>
        <p>Для проверки, что всё работает, можно запустить задачу вручную (всё равно будет батчами через WP-Cron).</p>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin: 10px 0 20px;">
            <form method="post" action="">
                <?php wp_nonce_field( 'owi_run_update_now', 'owi_run_update_nonce' ); ?>
                <input type="submit" class="button button-primary" value="Запустить обновление сейчас">
            </form>
            <form method="post" action="">
                <?php wp_nonce_field( 'owi_stop_update', 'owi_stop_update_nonce' ); ?>
                <input type="submit" class="button button-secondary" value="Остановить обновление">
            </form>
            <form method="post" action="">
                <?php wp_nonce_field( 'owi_run_import_now', 'owi_run_import_nonce' ); ?>
                <input type="submit" class="button" value="Запустить полный импорт сейчас">
            </form>
        </div>

        <?php if ( $import_in_progress ) : ?>
            <h2>Импорт в процессе</h2>
            <div id="owi-import-progress-bar" style="margin-top:20px;">
                <div id="owi-import-progress-status" style="width: 100%; background-color: #e0e0e0;">
                    <div id="owi-import-progress" style="width: <?php echo $import_percentage; ?>%; height: 20px; background-color: #28a745;"></div>
                </div>
                <div id="owi-import-progress-info" style="margin-top:10px;">
                    <span><?php echo $import_percentage; ?>%</span> |
                    <span>Обработано: <?php echo $import_total; ?></span> |
                    <span>Новых: <?php echo $import_new; ?></span> |
                    <span>Обновлено: <?php echo $import_updated; ?></span> |
                    <span>Пропущено: <?php echo $import_skipped; ?></span>
                    <?php if ($import_last) : ?> |
                        <span>Последнее обновление: <?php echo esc_html($import_last); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" action="" style="margin-top: 20px;">
                <?php wp_nonce_field( 'owi_reset_import', 'owi_reset_import_nonce' ); ?>
                <input type="submit" class="button button-secondary" name="reset_import" value="Сбросить флаг импорта">
            </form>
        <?php else : ?>
            <p>Импорт не запущен.</p>
        <?php endif; ?>

        <h2>Обновление (цена/наличие)</h2>
        <?php if ( $update_in_progress ) : ?>
            <p>Обновление идёт батчами через WP-Cron.</p>
        <?php else : ?>
            <p>Обновление сейчас не запущено.</p>
        <?php endif; ?>

        <div id="owi-update-progress-bar" style="margin-top:20px;">
            <div id="owi-update-progress-status" style="width: 100%; background-color: #e0e0e0;">
                <div id="owi-update-progress" style="width: <?php echo $update_percentage; ?>%; height: 20px; background-color: #17a2b8;"></div>
            </div>
            <div id="owi-update-progress-info" style="margin-top:10px;">
                <span><?php echo $update_percentage; ?>%</span> |
                <span>Обработано: <?php echo $update_total; ?></span> |
                <span>Обновлено: <?php echo $update_updated; ?></span> |
                <span>Без изменений: <?php echo $update_unchanged; ?></span> |
                <span>Не найдено по SKU: <?php echo $update_missing; ?></span> |
                <span>Ошибки: <?php echo $update_errors; ?></span>
                <?php if ($update_last) : ?> |
                    <span>Последнее обновление: <?php echo esc_html($update_last); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <form method="post" action="" style="margin-top: 10px;">
            <?php wp_nonce_field( 'owi_reset_update', 'owi_reset_update_nonce' ); ?>
            <input type="submit" class="button button-secondary" name="reset_update" value="Сбросить флаг обновления">
        </form>
    </div>
    <?php
}

/**
 * Функция для планирования события импорта
 */
function owi_schedule_import_event() {
    // Получение настроек расписания
    $interval = get_option( 'owi_auto_import_interval', 'daily' );
    $time     = get_option( 'owi_auto_import_time', '03:00' );

    // Разделение времени на часы и минуты
    $hours_minutes = explode( ':', $time );
    $hours   = isset( $hours_minutes[0] ) ? intval( $hours_minutes[0] ) : 0;
    $minutes = isset( $hours_minutes[1] ) ? intval( $hours_minutes[1] ) : 0;

    // Создание объекта времени с учетом часового пояса WordPress
    $scheduled_time = new DateTime( 'now', wp_timezone() );
    $scheduled_time->setTime( $hours, $minutes, 0 );

    $current_time = new DateTime( 'now', wp_timezone() );

    if ( $scheduled_time <= $current_time ) {
        // Если время уже прошло, планируем на следующий день
        $scheduled_time->modify( '+1 day' );
    }

    // Преобразование времени в метку времени UTC
    $timestamp = $scheduled_time->getTimestamp();

    // Планирование события, если оно еще не запланировано
    if ( ! wp_next_scheduled( 'owi_scheduled_import' ) ) {
        wp_schedule_event( $timestamp, $interval, 'owi_scheduled_import' );
    }
}

/**
 * Добавление пользовательских интервалов для Cron
 */
add_filter( 'cron_schedules', 'owi_custom_cron_schedules' );
function owi_custom_cron_schedules( $schedules ) {
    $schedules['every_other_day'] = array(
        'interval' => 172800, // 2 дня в секундах
        'display'  => __( 'Через день' ),
    );
    $schedules['weekly'] = array(
        'interval' => 604800, // 7 дней в секундах
        'display'  => __( 'Еженедельно' ),
    );
    $schedules['monthly'] = array(
        'interval' => 2592000, // 30 дней в секундах
        'display'  => __( 'Ежемесячно' ),
    );
    return $schedules;
}

/**
 * Хук для запуска импорта по расписанию
 */
add_action( 'owi_scheduled_import', 'owi_scheduled_import_callback' );
function owi_scheduled_import_callback() {
    $job = get_option('owi_auto_job', 'update');

    if ($job === 'import') {
        if ( get_option( 'owi_import_in_progress' ) ) {
            owi_log( 'Запланированный импорт не запущен, так как импорт уже в процессе.' );
            return;
        }
        owi_run_import();
        return;
    }

    // Default: lightweight update
    if ( get_option( 'owi_update_in_progress' ) ) {
        owi_log( 'Запланированное обновление не запущено, так как обновление уже в процессе.' );
        return;
    }

    owi_run_update(true);
}

/**
 * Self-heal scheduling: if auto mode is active but cron isn't scheduled, schedule it.
 */
add_action('init', function () {
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return;
    }
    if (defined('WP_INSTALLING') && WP_INSTALLING) {
        return;
    }
    if (!get_option('owi_auto_import_active', 0)) {
        return;
    }
    if (!wp_next_scheduled('owi_scheduled_import')) {
        owi_schedule_import_event();
    }
});
