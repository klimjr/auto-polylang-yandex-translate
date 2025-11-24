jQuery(document).ready(function($) {
    // Обработка ручного перевода постов
    $(document).on('click', '.manual-translate-post', function(e) {
        e.preventDefault();

        var button = $(this);
        var postId = button.data('post-id');
        var language = button.data('language');

        console.log('Manual translate clicked:', {postId: postId, language: language});

        button.prop('disabled', true).text(apyt_ajax.translating);

        // Используем правильный nonce из localized script
        $.post(apyt_ajax.ajax_url, {
            action: 'manual_translate_post',
            post_id: postId,
            language: language,
            nonce: apyt_ajax.nonce
        }, function(response) {
            console.log('Manual translate response:', response);

            if (response.success) {
                var editLink = response.data.edit_link;
                var row = button.closest('.apyt-language-row');
                var languageName = row.find('strong').text();

                row.html(
                    '<strong>' + languageName + '</strong> ✅ ' + apyt_ajax.success +
                    ' <a href="' + editLink + '" class="button button-small" target="_blank">Редактировать</a>' +
                    ' <button type="button" class="button button-small update-translation-post" data-post-id="' + postId + '" data-language="' + language + '">Обновить</button>'
                );

                // Показываем уведомление
                showNotification('✅ Перевод успешно создан!', 'success');
            } else {
                button.text(apyt_ajax.error).prop('disabled', false);
                showNotification('❌ Ошибка перевода: ' + response.data, 'error');
                console.error('Translation error:', response.data);
            }
        }).fail(function(xhr, status, error) {
            console.error('AJAX error:', xhr, status, error);

            button.text(apyt_ajax.error).prop('disabled', false);
            var errorMsg = 'Ошибка сети: ' + error;
            if (xhr.responseText) {
                try {
                    var jsonResponse = JSON.parse(xhr.responseText);
                    if (jsonResponse.data) {
                        errorMsg = jsonResponse.data;
                    }
                } catch(e) {
                    // Не JSON ответ
                }
            }
            showNotification('❌ ' + errorMsg, 'error');
        });
    });

    // Обновление существующего перевода
    $(document).on('click', '.update-translation-post', function(e) {
        e.preventDefault();

        var button = $(this);
        var postId = button.data('post-id');
        var language = button.data('language');

        console.log('Update translation clicked:', {postId: postId, language: language});

        button.prop('disabled', true).text('Обновление...');

        $.post(apyt_ajax.ajax_url, {
            action: 'update_translation_post',
            post_id: postId,
            language: language,
            nonce: apyt_ajax.nonce
        }, function(response) {
            console.log('Update translation response:', response);

            if (response.success) {
                button.text('✅ Обновлено').prop('disabled', true);
                showNotification('✅ Перевод успешно обновлен!', 'success');
            } else {
                button.text('Ошибка').prop('disabled', false);
                showNotification('❌ Ошибка обновления: ' + response.data, 'error');
                console.error('Update error:', response.data);
            }
        }).fail(function(xhr, status, error) {
            console.error('AJAX error:', xhr, status, error);

            button.text('Ошибка сети').prop('disabled', false);
            showNotification('❌ Ошибка сети при обновлении: ' + error, 'error');
        });
    });

    // Функция для показа уведомлений
    function showNotification(message, type) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var notice = $('<div class="notice ' + noticeClass + ' is-dismissible" style="margin: 10px 0; padding: 10px;"><p>' + message + '</p></div>');

        // Добавляем уведомление в начало страницы
        $('.wrap h1').after(notice);

        // Автоматическое скрытие через 5 секунд
        setTimeout(function() {
            notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);

        // Закрытие по клику
        notice.on('click', '.notice-dismiss', function() {
            notice.remove();
        });
    }

    // Тестирование API
    $('#test-translate').on('click', function() {
        var button = $(this);
        button.prop('disabled', true).text('Тестирование...');

        $.post(apyt_ajax.ajax_url, {
            action: 'test_yandex_translate',
            nonce: apyt_ajax.nonce
        }, function(response) {
            button.prop('disabled', false).text('Протестировать Yandex Translate API');
            if (response.success) {
                $('#test-result').html('<div style="color: green; background: #f0fff0; padding: 10px; border: 1px solid green;">' + response.data + '</div>');
            } else {
                $('#test-result').html('<div style="color: red; background: #fff0f0; padding: 10px; border: 1px solid red;">' + response.data + '</div>');
            }
        }).fail(function(xhr, status, error) {
            button.prop('disabled', false).text('Протестировать Yandex Translate API');
            $('#test-result').html('<div style="color: red; background: #fff0f0; padding: 10px; border: 1px solid red;">Ошибка сети: ' + error + '</div>');
        });
    });

    // Массовый перевод записей (10 шт)
    $('#bulk-translate-posts').on('click', function() {
        var button = $(this);
        button.prop('disabled', true).text('Начинаем перевод...');
        $('#bulk-result').html('<div class="notice notice-info">Подготовка к переводу...</div>');

        $.post(apyt_ajax.ajax_url, {
            action: 'bulk_translate_posts',
            nonce: apyt_ajax.nonce
        }, function(response) {
            if (response.success) {
                $('#bulk-result').html('<div class="notice notice-success">' + response.data.message + '</div>');
            } else {
                $('#bulk-result').html('<div class="notice notice-error">' + response.data + '</div>');
            }
            button.prop('disabled', false).text('Перевести записи (10 шт)');
        }).fail(function(xhr, status, error) {
            var errorMsg = 'Ошибка сети: ' + error;
            if (xhr.responseText) {
                try {
                    var jsonResponse = JSON.parse(xhr.responseText);
                    if (jsonResponse.data) {
                        errorMsg = jsonResponse.data;
                    }
                } catch(e) {
                    // Не JSON ответ
                }
            }
            $('#bulk-result').html('<div class="notice notice-error">' + errorMsg + '</div>');
            button.prop('disabled', false).text('Перевести записи (10 шт)');
        });
    });

    // Массовый перевод терминов
    $('#bulk-translate-terms').on('click', function() {
        var button = $(this);
        button.prop('disabled', true).text('Начинаем перевод...');
        $('#bulk-result').html('<div class="notice notice-info">Подготовка к переводу...</div>');

        $.post(apyt_ajax.ajax_url, {
            action: 'bulk_translate_terms',
            nonce: apyt_ajax.nonce
        }, function(response) {
            if (response.success) {
                $('#bulk-result').html('<div class="notice notice-success">' + response.data.message + '</div>');
            } else {
                $('#bulk-result').html('<div class="notice notice-error">' + response.data + '</div>');
            }
            button.prop('disabled', false).text('Перевести термины');
        }).fail(function(xhr, status, error) {
            var errorMsg = 'Ошибка сети: ' + error;
            if (xhr.responseText) {
                try {
                    var jsonResponse = JSON.parse(xhr.responseText);
                    if (jsonResponse.data) {
                        errorMsg = jsonResponse.data;
                    }
                } catch(e) {
                    // Не JSON ответ
                }
            }
            $('#bulk-result').html('<div class="notice notice-error">' + errorMsg + '</div>');
            button.prop('disabled', false).text('Перевести термины');
        });
    });

    // Массовый перевод кастомных типов записей
    $('#bulk-translate-custom-posts').on('click', function() {
        var button = $(this);
        button.prop('disabled', true).text('Начинаем перевод...');
        $('#bulk-custom-result').html('<div class="notice notice-info">Подготовка к переводу кастомных записей...</div>');

        $.post(apyt_ajax.ajax_url, {
            action: 'bulk_translate_custom_posts',
            nonce: apyt_ajax.nonce
        }, function(response) {
            if (response.success) {
                var postTypes = response.data.post_types ? response.data.post_types.join(', ') : 'кастомных типов';
                $('#bulk-custom-result').html(
                    '<div class="notice notice-success">' +
                    '<p>' + response.data.message + '</p>' +
                    '<p>Типы записей: ' + postTypes + '</p>' +
                    '</div>'
                );
            } else {
                $('#bulk-custom-result').html('<div class="notice notice-error">' + response.data + '</div>');
            }
            button.prop('disabled', false).text('Перевести кастомные записи');
        }).fail(function(xhr, status, error) {
            var errorMsg = 'Ошибка сети: ' + error;
            if (xhr.responseText) {
                try {
                    var jsonResponse = JSON.parse(xhr.responseText);
                    if (jsonResponse.data) {
                        errorMsg = jsonResponse.data;
                    }
                } catch(e) {
                    // Не JSON ответ
                }
            }
            $('#bulk-custom-result').html('<div class="notice notice-error">' + errorMsg + '</div>');
            button.prop('disabled', false).text('Перевести кастомные записи');
        });
    });
});