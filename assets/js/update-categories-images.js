jQuery(document).ready(function($) {
    var updateInProgress = false;
    var updateInterval;

    $('#owi-start-update').on('click', function(e) {
        e.preventDefault();
        if (updateInProgress) {
            alert('Обновление уже запущено.');
            return;
        }

        if (!confirm('Вы уверены, что хотите начать обновление категорий и изображений?')) {
            return;
        }

        updateInProgress = true;
        $('#owi-start-update').prop('disabled', true);
        $('#owi-stop-update').prop('disabled', false);
        $('#owi-progress-bar').show();

        // Инициализация прогресса (обнуляем все индикаторы)
        $('#owi-progress').css('width', '0%');
        $('#owi-progress-percentage').text('0%');
        $('#owi-progress-time').text('Время: 0 сек');
        $('#owi-progress-remaining').text('Осталось: -');
        $('#owi-progress-speed').text('Скорость: 0 товаров/мин');
        $('#owi-progress-updated').text('Обновлено: 0');
        $('#owi-progress-failed').text('Не удалось: 0');
        $('#owi-progress-remaining-products').text('Осталось товаров: 0');

        // Получаем значения параметров
        var batchSize = $('#owi-batch-size').val();
        var threadCount = $('#owi-thread-count').val();

        // Отправляем AJAX-запрос для запуска обновления
        $.ajax({
            url: owi_update_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'owi_start_update',
                nonce: owi_update_ajax_object.nonce,
                batch_size: batchSize,
                thread_count: threadCount
            },
            success: function(response) {
                if (response.success) {
                    console.log(response.data);
                    // Начинаем отслеживать прогресс
                    updateInterval = setInterval(getUpdateProgress, 2000);
                } else {
                    alert(response.data);
                    updateInProgress = false;
                    $('#owi-start-update').prop('disabled', false);
                    $('#owi-stop-update').prop('disabled', true);
                }
            },
            error: function() {
                alert('Произошла ошибка при запуске обновления.');
                updateInProgress = false;
                $('#owi-start-update').prop('disabled', false);
                $('#owi-stop-update').prop('disabled', true);
            }
        });
    });

    $('#owi-stop-update').on('click', function(e) {
        e.preventDefault();
        if (!updateInProgress) {
            alert('Обновление не запущено.');
            return;
        }

        if (!confirm('Вы уверены, что хотите остановить обновление?')) {
            return;
        }

        // Отправляем AJAX-запрос для остановки обновления
        $.ajax({
            url: owi_update_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'owi_stop_update',
                nonce: owi_update_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                    clearInterval(updateInterval);
                    updateInProgress = false;
                    $('#owi-start-update').prop('disabled', false);
                    $('#owi-stop-update').prop('disabled', true);
                } else {
                    alert(response.data);
                }
            },
            error: function() {
                alert('Произошла ошибка при остановке обновления.');
            }
        });
    });

    function getUpdateProgress() {
        $.ajax({
            url: owi_update_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'owi_get_update_progress',
                nonce: owi_update_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;

                    // Обновляем прогресс-бар
                    $('#owi-progress').css('width', data.percentage + '%');
                    $('#owi-progress-percentage').text(data.percentage + '%');

                    // Время в формате "дн ч мин сек"
                    $('#owi-progress-time').text('Время: ' + data.elapsed_time);

                    // Оставшееся время тоже в формате "дн ч мин сек" или "-"
                    if (data.estimated_remaining_time !== '-') {
                        $('#owi-progress-remaining').text('Осталось: ' + data.estimated_remaining_time);
                    } else {
                        $('#owi-progress-remaining').text('Осталось: -');
                    }

                    // Скорость — товаров/мин
                    $('#owi-progress-speed').text('Скорость: ' + data.speed + ' товаров/мин');
                    $('#owi-progress-updated').text('Обновлено: ' + data.updated);
                    $('#owi-progress-failed').text('Не удалось: ' + data.failed);

                    // Доп. статистика: сколько осталось и всего
                    $('#owi-progress-remaining-products').text(
                        'Осталось товаров: ' + data.remaining_products + ' из ' + data.total_products
                    );

                    if (data.percentage >= 100) {
                        clearInterval(updateInterval);
                        updateInProgress = false;
                        $('#owi-start-update').prop('disabled', false);
                        $('#owi-stop-update').prop('disabled', true);
                        alert('Обновление завершено.');
                    }
                } else {
                    clearInterval(updateInterval);
                    updateInProgress = false;
                    $('#owi-start-update').prop('disabled', false);
                    $('#owi-stop-update').prop('disabled', true);
                    alert(response.data);
                }
            },
            error: function() {
                clearInterval(updateInterval);
                updateInProgress = false;
                $('#owi-start-update').prop('disabled', false);
                $('#owi-stop-update').prop('disabled', true);
                alert('Произошла ошибка при получении прогресса обновления.');
            }
        });
    }
});
