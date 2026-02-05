<?php
// Предотвращаем прямой доступ
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Функция для отображения страницы расписания
 */
function owi_schedule_page() {
    // Обработка отправки формы
    if ( isset( $_POST['owi_schedule_nonce'] ) && wp_verify_nonce( $_POST['owi_schedule_nonce'], 'owi_save_schedule' ) ) {
        // Сохранение настроек расписания
        $interval  = sanitize_text_field( $_POST['owi_auto_import_interval'] );
        $time      = sanitize_text_field( $_POST['owi_auto_import_time'] );
        $is_active = isset( $_POST['owi_auto_import_active'] ) ? 1 : 0;

        update_option( 'owi_auto_import_interval', $interval );
        update_option( 'owi_auto_import_time', $time );
        update_option( 'owi_auto_import_active', $is_active );

        // Очистка существующих событий расписания
        wp_clear_scheduled_hook( 'owi_scheduled_import' );

        if ( $is_active ) {
            // Планирование нового события
            owi_schedule_import_event();
        }

        echo '<div class="updated"><p>Настройки расписания сохранены.</p></div>';
    }

    // Обработка сброса флага импорта
    if ( isset( $_POST['owi_reset_import_nonce'] ) && wp_verify_nonce( $_POST['owi_reset_import_nonce'], 'owi_reset_import' ) ) {
        delete_option( 'owi_import_in_progress' );
        delete_option( 'owi_import_progress' );
        delete_option( 'owi_import_stop' );
        echo '<div class="updated"><p>Флаг импорта сброшен.</p></div>';
    }

    // Получение текущих настроек
    $interval  = get_option( 'owi_auto_import_interval', 'daily' );
    $time      = get_option( 'owi_auto_import_time', '03:00' );
    $is_active = get_option( 'owi_auto_import_active', 0 );

    // Текущее время сервера
    $current_time = wp_date( 'd.m.Y H:i:s', time(), wp_timezone() );

    // Время следующего запланированного импорта
    $timestamp = wp_next_scheduled( 'owi_scheduled_import' );
    if ( $timestamp ) {
        $next_import_time = wp_date( 'd.m.Y H:i:s', $timestamp, wp_timezone() );
    } else {
        $next_import_time = 'Не запланирован';
    }

    // Проверка, идет ли импорт в данный момент
    $import_in_progress = get_option( 'owi_import_in_progress', false );

    // Получение данных прогресса импорта
    $progress        = get_option( 'owi_import_progress', array() );
    $percentage      = 0;
    $imported_count  = 0;
    $skipped_count   = 0;

    if ( $progress ) {
        $total_pages   = isset( $progress['total_pages'] ) ? $progress['total_pages'] : 0;
        $current_page  = isset( $progress['current_page'] ) ? $progress['current_page'] : 0;
        $imported_count = isset( $progress['imported_count'] ) ? $progress['imported_count'] : 0;
        $skipped_count  = isset( $progress['skipped_count'] ) ? $progress['skipped_count'] : 0;
        if ( $total_pages > 0 ) {
            $percentage = round( ( $current_page / $total_pages ) * 100 );
        }
    }
    ?>
    <div class="wrap">
        <h1>Настройки расписания импорта</h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'owi_save_schedule', 'owi_schedule_nonce' ); ?>
            <table class="form-table">
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
                    <th scope="row">Следующий запланированный импорт</th>
                    <td>
                        <span id="next-import-time"><?php echo $next_import_time; ?></span>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Сохранить настройки' ); ?>
        </form>

        <?php if ( $import_in_progress ) : ?>
            <h2>Импорт в процессе</h2>
            <div id="owi-progress-bar" style="margin-top:20px;">
                <div id="owi-progress-status" style="width: 100%; background-color: #e0e0e0;">
                    <div id="owi-progress" style="width: <?php echo $percentage; ?>%; height: 20px; background-color: #28a745;"></div>
                </div>
                <div id="owi-progress-info" style="margin-top:10px;">
                    <span id="owi-progress-percentage"><?php echo $percentage; ?>%</span> | 
                    <span id="owi-progress-imported">Импортировано: <?php echo $imported_count; ?></span> | 
                    <span id="owi-progress-skipped">Пропущено: <?php echo $skipped_count; ?></span>
                </div>
            </div>

            <form method="post" action="" style="margin-top: 20px;">
                <?php wp_nonce_field( 'owi_reset_import', 'owi_reset_import_nonce' ); ?>
                <input type="submit" class="button button-secondary" name="reset_import" value="Сбросить флаг импорта">
            </form>
        <?php else : ?>
            <p>Импорт не запущен.</p>
        <?php endif; ?>
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
    // Проверяем, не идет ли уже импорт
    if ( get_option( 'owi_import_in_progress' ) ) {
        owi_log( 'Запланированный импорт не запущен, так как импорт уже в процессе.' );
        return;
    }

    // Запуск импорта
    owi_run_import();
}
