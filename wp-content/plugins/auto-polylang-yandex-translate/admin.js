jQuery(document).ready(function($) {
    // Обработка ручного перевода постов
    $(document).on('click', '.manual-translate-post', function() {
        var button = $(this);
        var postId = button.data('post-id');
        var language = button.data('language');

        button.prop('disabled', true).text(apyt_ajax.translating);

        $.post(apyt_ajax.ajax_url, {
            action: 'manual_translate_post',
            post_id: postId,
            language: language,
            nonce: apyt_ajax.nonce
        }, function(response) {
            if (response.success) {
                var editLink = response.data.edit_link;
                button.closest('.apyt-language-row').html(
                    '<strong>' + button.closest('.apyt-language-row').find('strong').text() + '</strong> ✅ ' + apyt_ajax.success +
                    ' <a href="' + editLink + '" class="button button-small">Редактировать</a>'
                );
            } else {
                button.text(apyt_ajax.error).prop('disabled', false);
                alert(apyt_ajax.error + ': ' + response.data);
            }
        }).fail(function(xhr, status, error) {
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
            alert(errorMsg);
        });
    });

    // Обновление существующего перевода
    $(document).on('click', '.update-translation-post', function() {
        var button = $(this);
        var postId = button.data('post-id');
        var language = button.data('language');

        button.prop('disabled', true).text('Обновление...');

        $.post(apyt_ajax.ajax_url, {
            action: 'update_translation_post',
            post_id: postId,
            language: language,
            nonce: apyt_ajax.nonce
        }, function(response) {
            if (response.success) {
                button.text('✅ Обновлено').prop('disabled', true);
                alert('Перевод успешно обновлен');
            } else {
                button.text('Ошибка').prop('disabled', false);
                alert('Ошибка обновления: ' + response.data);
            }
        }).fail(function(xhr, status, error) {
            button.text('Ошибка сети').prop('disabled', false);
            alert('Ошибка сети при обновлении: ' + error);
        });
    });

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

    // Массовый перевод записей
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
            button.prop('disabled', false).text('Перевести записи');
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
            button.prop('disabled', false).text('Перевести записи');
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
});