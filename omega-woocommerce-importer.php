<?php
/*
Plugin Name: Omega WooCommerce Importer
Description: Плагин для импорта автотоваров из Omega API в WooCommerce.
Version: 1.4
Author: buyreadysite.com
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Выход если доступ напрямую
}

// Определение констант
define( 'OWI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OWI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OWI_LOG_FILE', OWI_PLUGIN_DIR . 'logs/import.log' );

// Создание папки logs при активации плагина
register_activation_hook(__FILE__, 'owi_create_log_folder');
function owi_create_log_folder() {
    if ( ! file_exists(OWI_PLUGIN_DIR . 'logs') ) {
        mkdir(OWI_PLUGIN_DIR . 'logs', 0755, true);
    }
}

// Подключение файлов настроек и импорта
require_once OWI_PLUGIN_DIR . 'includes/settings.php';
require_once OWI_PLUGIN_DIR . 'includes/import.php';
require_once OWI_PLUGIN_DIR . 'includes/update-categories-images.php'; // Подключение нового файла
require_once OWI_PLUGIN_DIR . 'includes/ui-frontend.php';
require_once OWI_PLUGIN_DIR . 'includes/schedule.php'; // Подключение файла расписания

// Добавление пунктов меню
add_action('admin_menu', 'owi_add_admin_menu');

function owi_add_admin_menu() {
    add_menu_page(
        'Omega Importer', // Название страницы
        'Omega Importer', // Название меню
        'manage_options', // Возможность
        'omega-importer', // Слаг меню
        'owi_settings_page', // Функция для отображения страницы настроек
        'dashicons-upload', // Иконка меню
        56 // Позиция
    );

    add_submenu_page(
        'omega-importer',
        'Настройки',
        'Настройки',
        'manage_options',
        'omega-importer',
        'owi_settings_page'
    );

    add_submenu_page(
        'omega-importer',
        'Импорт',
        'Импорт',
        'manage_options',
        'omega-importer-import',
        'owi_import_page'
    );

    add_submenu_page(
        'omega-importer',
        'Обновление категорий и изображений', // Название страницы
        'Обновление категорий и изображений', // Название меню
        'manage_options', // Возможность
        'omega-importer-update-categories-images', // Слаг меню
        'owi_update_categories_images_page' // Функция для отображения страницы
    );

    add_submenu_page(
        'omega-importer',
        'Расписание', // Название страницы
        'Расписание', // Название меню
        'manage_options', // Возможность
        'omega-importer-schedule', // Слаг меню
        'owi_schedule_page' // Функция для отображения страницы расписания
    );
}

// Подключение стилей и скриптов
add_action('admin_enqueue_scripts', 'owi_enqueue_admin_scripts');

function owi_enqueue_admin_scripts($hook) {
    if (strpos($hook, 'omega-importer') === false) {
        return;
    }

    // Общие скрипты и стили для всех страниц плагина
    wp_enqueue_style('owi_admin_css', OWI_PLUGIN_URL . 'assets/css/admin.css');
    wp_enqueue_script('owi_admin_js', OWI_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), null, true);

    // Локализация скрипта для передачи AJAX URL и nonce
    wp_localize_script('owi_admin_js', 'owi_ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('owi_import_nonce')
    ));

    // Подключение скриптов для страницы обновления категорий и изображений
    if ($hook === 'omega-importer_page_omega-importer-update-categories-images') {
        wp_enqueue_script('owi_update_js', OWI_PLUGIN_URL . 'assets/js/update-categories-images.js', array('jquery'), null, true);
        wp_localize_script('owi_update_js', 'owi_update_ajax_object', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('owi_update_nonce')
        ));
    }
}

// Функция логирования
function owi_log($message) {
    $log_dir = OWI_PLUGIN_DIR . 'logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $log_file = OWI_LOG_FILE;
    $date = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$date] $message" . PHP_EOL, FILE_APPEND);
}
