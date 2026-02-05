<?php
// Функция для отображения страницы настроек
function owi_settings_page() {
    ?>
    <div class="wrap">
        <h1>Настройки Omega Importer</h1>
        <form method="post" action="options.php">
            <?php
                settings_fields('owi_settings_group');
                do_settings_sections('omega-importer');
                submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Инициализация настроек
add_action('admin_init', 'owi_settings_init');

function owi_settings_init() {
    register_setting('owi_settings_group', 'owi_api_key');

    add_settings_section(
        'owi_settings_section',
        'API Настройки',
        'owi_settings_section_callback',
        'omega-importer'
    );

    add_settings_field(
        'owi_api_key',
        'API Ключ',
        'owi_api_key_render',
        'omega-importer',
        'owi_settings_section'
    );
}

function owi_settings_section_callback() {
    echo '<p>Введите ваш API ключ для Omega API.</p>';
}

function owi_api_key_render() {
    $api_key = get_option('owi_api_key');
    echo '<input type="text" name="owi_api_key" value="' . esc_attr($api_key) . '" size="50">';
}
