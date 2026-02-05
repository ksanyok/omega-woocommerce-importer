<?php
// Файл: includes/ui-frontend.php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Выход если доступ напрямую
}

/**
 * Выводит поля 'Card' и '_code_twhed_id' на странице товара с кнопками копирования.
 */
// add_action('woocommerce_single_product_summary', 'owi_display_custom_fields_frontend', 25); // Убрано принудительное размещение

function owi_display_custom_fields_frontend() {
    global $product;

    // Получение значений полей
    $card = get_post_meta( $product->get_id(), '_card', true );
    $barcode = get_post_meta( $product->get_id(), '_code_twhed_id', true );

    // Проверка наличия хотя бы одного из полей
    if ( ! empty( $card ) || ! empty( $barcode ) ) {
        echo '<div class="owi-custom-fields" style="margin-top:20px;">';

        // Поле 'Card'
        if ( ! empty( $card ) ) {
            echo '<p class="owi-card"><strong>' . __('Card:', 'woocommerce') . '</strong> <span class="owi-field-text" id="owi-card-text">' . esc_html( $card ) . '</span> ';
            echo '<button type="button" class="owi-copy-button" data-copy-target="#owi-card-text" title="' . esc_attr__('Скопировать Card', 'woocommerce') . '">';
            // Используем вашу SVG-иконку
            echo '<svg height="15px" viewBox="0 0 24 24" width="15px" fill="#666666" xmlns="http://www.w3.org/2000/svg">';
            echo '<path d="M0 0h24v24H0V0z" fill="none"></path>';
            echo '<path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1 .9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1 -.9-2-2-2zm0 16H8V7h11v14z"></path>';
            echo '</svg>';
            echo '</button></p>';
        }

        // Поле 'Barcode'
        if ( ! empty( $barcode ) ) {
            echo '<p class="owi-barcode"><strong>' . __('Barcode:', 'woocommerce') . '</strong> <span class="owi-field-text" id="owi-barcode-text">' . esc_html( $barcode ) . '</span> ';
            echo '<button type="button" class="owi-copy-button" data-copy-target="#owi-barcode-text" title="' . esc_attr__('Скопировать Barcode', 'woocommerce') . '">';
            // Используем вашу SVG-иконку
            echo '<svg height="15px" viewBox="0 0 24 24" width="15px" fill="#666666" xmlns="http://www.w3.org/2000/svg">';
            echo '<path d="M0 0h24v24H0V0z" fill="none"></path>';
            echo '<path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1 .9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1 -.9-2-2-2zm0 16H8V7h11v14z"></path>';
            echo '</svg>';
            echo '</button></p>';
        }

        echo '</div>';

        // Добавление скриптов и стилей для копирования и уведомлений
        add_action('wp_footer', 'owi_add_copy_script_frontend');
        add_action('wp_head', 'owi_add_copy_styles_frontend');
    }
}

/**
 * Добавляет JavaScript для копирования текста и отображения уведомлений.
 */
function owi_add_copy_script_frontend() {
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const copyButtons = document.querySelectorAll('.owi-copy-button');
            copyButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const targetSelector = this.getAttribute('data-copy-target');
                    const targetElement = document.querySelector(targetSelector);
                    if (targetElement) {
                        const text = targetElement.textContent.trim();
                        const tempInput = document.createElement('textarea');
                        tempInput.value = text;
                        document.body.appendChild(tempInput);
                        tempInput.select();
                        try {
                            const successful = document.execCommand('copy');
                            if (successful) {
                                owi_showCopyNotification('Скопировано: ' + text);
                            } else {
                                owi_showCopyNotification('Не удалось скопировать.', true);
                            }
                        } catch (err) {
                            owi_showCopyNotification('Ошибка копирования.', true);
                        }
                        document.body.removeChild(tempInput);
                    }
                });
            });

            // Функция показа уведомления
            window.owi_showCopyNotification = function(message, isError = false) {
                const notification = document.createElement('div');
                notification.className = 'owi-copy-notification';
                notification.textContent = message;
                if (isError) {
                    notification.classList.add('error');
                }
                document.body.appendChild(notification);

                // Удаление уведомления через 3 секунды
                setTimeout(() => {
                    notification.classList.add('fade-out');
                    setTimeout(() => {
                        document.body.removeChild(notification);
                    }, 500);
                }, 3000);
            };
        });
    </script>
    <?php
}

/**
 * Добавляет стили для кнопок копирования и уведомлений.
 */
function owi_add_copy_styles_frontend() {
    ?>
    <style type="text/css">
        .owi-custom-fields {
            font-size: 16px;
            line-height: 1.6;
        }
        .owi-custom-fields p {
            margin: 5px 0;
        }
        .owi-field-text {
            display: inline;
        }
        .owi-copy-button {
            margin-left: 8px;
            padding: 4px 8px;
            font-size: 14px;
            cursor: pointer;
            background-color: transparent;
            border: none;
            color: inherit;
            vertical-align: middle;
        }
        .owi-copy-button:hover svg {
            fill: #555555;
        }
        .owi-copy-button svg {
            fill: #666666;
            transition: fill 0.3s ease;
        }
        .owi-copy-notification {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background-color: #323232;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 5px;
            opacity: 0.9;
            z-index: 9999;
            transition: opacity 0.5s ease, transform 0.5s ease;
        }
        .owi-copy-notification.error {
            background-color: #e74c3c;
        }
        .owi-copy-notification.fade-out {
            opacity: 0;
            transform: translateX(-50%) translateY(20px);
        }
        /* Стили для иконки копирования рядом с SKU */
        .sku-copy-icon {
            margin-left: 8px;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
        }
        .sku-copy-icon svg {
            fill: #666666;
            transition: fill 0.3s ease;
        }
        .sku-copy-icon:hover svg {
            fill: #555555;
        }
    </style>
    <?php
}

/**
 * Добавляет иконку копирования рядом с артикулом (SKU) на странице товара.
 */
add_action('wp_footer', 'owi_add_copy_icon_to_sku');

function owi_add_copy_icon_to_sku() {
    if (!is_product()) {
        return;
    }

    // SVG-иконка для копирования (ваша иконка)
    $copy_icon_svg = '<svg height="15px" viewBox="0 0 24 24" width="15px" fill="#666666" xmlns="http://www.w3.org/2000/svg"><path d="M0 0h24v24H0V0z" fill="none"></path><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1 .9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1 -.9-2-2-2zm0 16H8V7h11v14z"></path></svg>';

    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Ищем элемент SKU
            const skuElement = document.querySelector(".product_meta .sku") || document.querySelector(".wc-block-components-product-sku .sku");
            if (skuElement) {
                const copyIcon = document.createElement("span");
                copyIcon.className = "copy-icon sku-copy-icon";
                copyIcon.innerHTML = `<?php echo $copy_icon_svg; ?>`;
                copyIcon.title = "Скопировать артикул";
                copyIcon.onclick = function() {
                    const textToCopy = skuElement.textContent.trim();
                    const tempInput = document.createElement("textarea");
                    tempInput.value = textToCopy;
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    document.execCommand("copy");
                    document.body.removeChild(tempInput);
                    // Используем функцию показа уведомления
                    owi_showCopyNotification('Скопировано: ' + textToCopy);
                };
                // Вставляем иконку после SKU
                skuElement.parentNode.insertBefore(copyIcon, skuElement.nextSibling);
            }
        });
    </script>
    <?php
}

/**
 * Шорткод для отображения поля 'Card' с кнопкой копирования.
 */
function owi_card_shortcode() {
    global $product;
    if (!$product) return '';

    $card = get_post_meta($product->get_id(), '_card', true);
    if (empty($card)) return '';

    ob_start();
    echo '<p class="owi-card"><strong>' . __('Card:', 'woocommerce') . '</strong> <span class="owi-field-text" id="owi-card-text-' . $product->get_id() . '">' . esc_html($card) . '</span> ';
    echo '<button type="button" class="owi-copy-button" data-copy-target="#owi-card-text-' . $product->get_id() . '" title="' . esc_attr__('Скопировать Card', 'woocommerce') . '">';
    echo '<svg height="15px" viewBox="0 0 24 24" width="15px" fill="#666666" xmlns="http://www.w3.org/2000/svg">';
    echo '<path d="M0 0h24v24H0V0z" fill="none"></path>';
    echo '<path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1 .9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1 -.9-2-2-2zm0 16H8V7h11v14z"></path>';
    echo '</svg>';
    echo '</button></p>';

    // Добавление скриптов и стилей
    add_action('wp_footer', 'owi_add_copy_script_frontend');
    add_action('wp_head', 'owi_add_copy_styles_frontend');

    return ob_get_clean();
}
add_shortcode('owi_card', 'owi_card_shortcode');

/**
 * Шорткод для отображения поля 'Barcode' с кнопкой копирования.
 */
function owi_barcode_shortcode() {
    global $product;
    if (!$product) return '';

    $barcode = get_post_meta($product->get_id(), '_code_twhed_id', true);
    if (empty($barcode)) return '';

    ob_start();
    echo '<p class="owi-barcode"><strong>' . __('Barcode:', 'woocommerce') . '</strong> <span class="owi-field-text" id="owi-barcode-text-' . $product->get_id() . '">' . esc_html($barcode) . '</span> ';
    echo '<button type="button" class="owi-copy-button" data-copy-target="#owi-barcode-text-' . $product->get_id() . '" title="' . esc_attr__('Скопировать Barcode', 'woocommerce') . '">';
    echo '<svg height="15px" viewBox="0 0 24 24" width="15px" fill="#666666" xmlns="http://www.w3.org/2000/svg">';
    echo '<path d="M0 0h24v24H0V0z" fill="none"></path>';
    echo '<path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1 .9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1 -.9-2-2-2zm0 16H8V7h11v14z"></path>';
    echo '</svg>';
    echo '</button></p>';

    // Добавление скриптов и стилей
    add_action('wp_footer', 'owi_add_copy_script_frontend');
    add_action('wp_head', 'owi_add_copy_styles_frontend');

    return ob_get_clean();
}
add_shortcode('owi_barcode', 'owi_barcode_shortcode');
