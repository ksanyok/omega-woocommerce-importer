jQuery(document).ready(function($) {
    // Функция для форматирования времени в секундах в "X ч Y мин Z сек"
    function formatTime(seconds) {
        var hrs = Math.floor(seconds / 3600);
        var mins = Math.floor((seconds % 3600) / 60);
        var secs = seconds % 60;
        return (hrs > 0 ? hrs + ' ч ' : '') + (mins > 0 ? mins + ' мин ' : '') + secs + ' сек';
    }

    // Функция для сброса интерфейса прогресса
    function resetProgressUI() {
        $('#owi-progress-bar').hide();
        $('#owi-progress').css('width', '0%');
        $('#owi-progress-percentage').text('0%');
        $('#owi-progress-time').text('Время: 0 сек');
        $('#owi-progress-remaining').text('Осталось: -');
        $('#owi-progress-speed').text('Скорость: 0 товаров/мин');
        $('#owi-progress-total-processed').text('Обработано: 0');
        $('#owi-progress-new-imported').text('Новых: 0');
        $('#owi-progress-updated-imported').text('Обновлено: 0');
        $('#owi-progress-skipped').text('Пропущено: 0');
    }

    // Функция для установки состояния кнопок
    function setButtonsState(isImporting) {
        if (isImporting) {
            $('#owi-start-import').prop('disabled', true);
            $('#owi-resume-import').prop('disabled', true);
            $('#owi-pause-import').prop('disabled', false);
            $('#owi-stop-import').prop('disabled', false);
        } else {
            $('#owi-start-import').prop('disabled', false);
            $('#owi-resume-import').prop('disabled', false);
            $('#owi-pause-import').prop('disabled', true);
            $('#owi-stop-import').prop('disabled', true);
        }
    }

    // Функция для обновления UI на основе текущего состояния импорта
    function initializeImportUI() {
        $.ajax({
            url: owi_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'owi_get_import_progress',
                nonce: owi_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    $('#owi-progress-bar').show();
                    $('#owi-progress').css('width', data.percentage + '%');
                    $('#owi-progress-percentage').text(data.percentage + '%');
                    $('#owi-progress-time').text('Время: ' + formatTime(data.elapsed_time));
                    if (data.estimated_remaining_time !== -1) {
                        $('#owi-progress-remaining').text('Осталось: ' + data.estimated_remaining_time + ' мин');
                    } else {
                        $('#owi-progress-remaining').text('Осталось: -');
                    }
                    $('#owi-progress-speed').text('Скорость: ' + data.speed + ' товаров/мин');
                    $('#owi-progress-total-processed').text('Обработано: ' + data.total_processed);
                    $('#owi-progress-new-imported').text('Новых: ' + data.new_imported);
                    $('#owi-progress-updated-imported').text('Обновлено: ' + data.updated_imported);
                    $('#owi-progress-skipped').text('Пропущено: ' + data.skipped);

                    // Устанавливаем состояние кнопок
                    setButtonsState(true);
                    // Запускаем опрос прогресса
                    startProgressPolling();
                } else {
                    // Если импорт не в процессе, скрываем прогресс-бар
                    resetProgressUI();
                    setButtonsState(false);
                }
            },
            error: function() {
                // В случае ошибки скрываем прогресс-бар
                resetProgressUI();
                setButtonsState(false);
            }
        });
    }

    // Переменная для интервала опроса
    var progressInterval = null;

    // Функция для начала опроса прогресса
    function startProgressPolling() {
        if (progressInterval) {
            clearInterval(progressInterval);
        }
        progressInterval = setInterval(function() {
            $.ajax({
                url: owi_ajax_object.ajax_url,
                method: 'POST',
                data: {
                    action: 'owi_get_import_progress',
                    nonce: owi_ajax_object.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        $('#owi-progress').css('width', data.percentage + '%');
                        $('#owi-progress-percentage').text(data.percentage + '%');
                        $('#owi-progress-time').text('Время: ' + formatTime(data.elapsed_time));
                        if (data.estimated_remaining_time !== -1) {
                            $('#owi-progress-remaining').text('Осталось: ' + data.estimated_remaining_time + ' мин');
                        } else {
                            $('#owi-progress-remaining').text('Осталось: -');
                        }
                        $('#owi-progress-speed').text('Скорость: ' + data.speed + ' товаров/мин');
                        $('#owi-progress-total-processed').text('Обработано: ' + data.total_processed);
                        $('#owi-progress-new-imported').text('Новых: ' + data.new_imported);
                        $('#owi-progress-updated-imported').text('Обновлено: ' + data.updated_imported);
                        $('#owi-progress-skipped').text('Пропущено: ' + data.skipped);

                        // Если импорт завершен, останавливаем опрос
                        if (data.percentage >= 100) {
                            clearInterval(progressInterval);
                            setButtonsState(false);
                        }
                    } else {
                        // Если нет данных о прогрессе, останавливаем опрос
                        clearInterval(progressInterval);
                        setButtonsState(false);
                        resetProgressUI();
                    }
                },
                error: function() {
                    // В случае ошибки останавливаем опрос
                    clearInterval(progressInterval);
                }
            });
        }, 5000); // Обновление каждые 5 секунд
    }

    // Кнопка "Начать импорт заново"
    $('#owi-start-import').on('click', function(e) {
        e.preventDefault();
        var startButton = $(this);
        var resumeButton = $('#owi-resume-import');
        var pauseButton = $('#owi-pause-import');
        var stopButton = $('#owi-stop-import');
        var progressBar = $('#owi-progress-bar');
        var progress = $('#owi-progress');
        var progressPercentage = $('#owi-progress-percentage');
        var progressTime = $('#owi-progress-time');
        var progressRemaining = $('#owi-progress-remaining');
        var progressSpeed = $('#owi-progress-speed');
        var progressTotalProcessed = $('#owi-progress-total-processed');
        var progressNewImported = $('#owi-progress-new-imported');
        var progressUpdatedImported = $('#owi-progress-updated-imported');
        var progressSkipped = $('#owi-progress-skipped');

        startButton.prop('disabled', true).text('Импортируется...');
        resumeButton.prop('disabled', true);
        pauseButton.prop('disabled', false);
        stopButton.prop('disabled', false);
        progressBar.show();
        progress.css('width', '0%');
        progressPercentage.text('0%');
        progressTime.text('Время: 0 сек');
        progressRemaining.text('Осталось: -');
        progressSpeed.text('Скорость: 0 товаров/мин');
        progressTotalProcessed.text('Обработано: 0');
        progressNewImported.text('Новых: 0');
        progressUpdatedImported.text('Обновлено: 0');
        progressSkipped.text('Пропущено: 0');

        // Очистка уведомлений
        $('#owi-import-result .notice').remove();

        // Старт импорта (fresh)
        $.ajax({
            url: owi_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'owi_start_import',
                nonce: owi_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#owi-import-result').append('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    setButtonsState(true);
                    startProgressPolling();
                } else {
                    $('#owi-import-result').append('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    startButton.prop('disabled', false).text('Начать импорт заново');
                    resumeButton.prop('disabled', false);
                }
            },
            error: function() {
                $('#owi-import-result').append('<div class="notice notice-error"><p>Произошла ошибка при выполнении импорта.</p></div>');
                startButton.prop('disabled', false).text('Начать импорт заново');
                resumeButton.prop('disabled', false);
                pauseButton.prop('disabled', true);
                stopButton.prop('disabled', true);
            }
        });
    });

    // Кнопка "Продолжить незавершённый импорт"
    $('#owi-resume-import').on('click', function(e) {
        e.preventDefault();
        var resumeButton = $(this);
        var startButton = $('#owi-start-import');
        var pauseButton = $('#owi-pause-import');
        var stopButton = $('#owi-stop-import');
        var progressBar = $('#owi-progress-bar');

        resumeButton.prop('disabled', true);
        startButton.prop('disabled', true);
        pauseButton.prop('disabled', false);
        stopButton.prop('disabled', false);
        progressBar.show();

        // Очистка уведомлений
        $('#owi-import-result .notice').remove();

        // Возобновление
        $.ajax({
            url: owi_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'owi_resume_import',
                nonce: owi_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#owi-import-result').append('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    setButtonsState(true);
                    startProgressPolling();
                } else {
                    $('#owi-import-result').append('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    resumeButton.prop('disabled', false);
                    startButton.prop('disabled', false);
                }
            },
            error: function() {
                $('#owi-import-result').append('<div class="notice notice-error"><p>Произошла ошибка при попытке возобновить импорт.</p></div>');
                resumeButton.prop('disabled', false);
                startButton.prop('disabled', false);
                pauseButton.prop('disabled', true);
                stopButton.prop('disabled', true);
            }
        });
    });

    // Кнопка "Пауза"
    $('#owi-pause-import').on('click', function(e) {
        e.preventDefault();
        var pauseButton = $(this);

        pauseButton.prop('disabled', true).text('Пауза...');
        $('#owi-import-result').append('<div class="notice notice-warning"><p>Запрос на паузу импорта отправлен.</p></div>');

        $.ajax({
            url: owi_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'owi_pause_import',
                nonce: owi_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#owi-import-result').append('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    setButtonsState(false);
                    // Останавливаем опрос прогресса
                    if (progressInterval) {
                        clearInterval(progressInterval);
                    }
                    pauseButton.prop('disabled', true).text('Пауза');
                } else {
                    $('#owi-import-result').append('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    pauseButton.prop('disabled', false).text('Пауза');
                }
            },
            error: function() {
                $('#owi-import-result').append('<div class="notice notice-error"><p>Произошла ошибка при отправке запроса на паузу импорта.</p></div>');
                pauseButton.prop('disabled', false).text('Пауза');
            }
        });
    });

    // Кнопка "Остановить импорт"
    $('#owi-stop-import').on('click', function(e) {
        e.preventDefault();
        var stopButton = $(this);

        stopButton.prop('disabled', true).text('Останавливается...');
        $('#owi-import-result').append('<div class="notice notice-warning"><p>Запрос на остановку импорта отправлен.</p></div>');

        $.ajax({
            url: owi_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'owi_stop_import',
                nonce: owi_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#owi-import-result').append('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    setButtonsState(false);
                    // Останавливаем опрос
                    if (progressInterval) {
                        clearInterval(progressInterval);
                    }
                } else {
                    $('#owi-import-result').append('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    stopButton.prop('disabled', false).text('Остановить импорт');
                }
            },
            error: function() {
                $('#owi-import-result').append('<div class="notice notice-error"><p>Произошла ошибка при отправке запроса на остановку импорта.</p></div>');
                stopButton.prop('disabled', false).text('Остановить импорт');
            }
        });
    });

    // Кнопка "Сбросить флаги"
    $('#owi-reset-import').on('click', function(e) {
        e.preventDefault();
        var resetButton = $(this);

        if (!confirm('Вы уверены, что хотите сбросить прогресс импорта? Это остановит текущий процесс и удалит все данные прогресса.')) {
            return;
        }

        resetButton.prop('disabled', true).text('Сброс...');
        $('#owi-import-result .notice').remove();
        $('#owi-import-result').append('<div class="notice notice-warning"><p>Запрос на сброс прогресса импорта отправлен.</p></div>');

        $.ajax({
            url: owi_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'owi_reset_import',
                nonce: owi_ajax_object.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#owi-import-result').append('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    resetButton.prop('disabled', false).text('Сбросить флаги');
                    setButtonsState(false);
                    resetProgressUI();
                } else {
                    $('#owi-import-result').append('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    resetButton.prop('disabled', false).text('Сбросить флаги');
                }
            },
            error: function() {
                $('#owi-import-result').append('<div class="notice notice-error"><p>Произошла ошибка при отправке запроса на сброс прогресса импорта.</p></div>');
                resetButton.prop('disabled', false).text('Сбросить флаги');
            }
        });
    });

    // Инициализация UI при загрузке страницы
    initializeImportUI();
});
