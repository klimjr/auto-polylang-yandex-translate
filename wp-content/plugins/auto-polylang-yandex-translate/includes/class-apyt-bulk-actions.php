<?php

class APYT_Bulk_Actions {

    public function __construct() {
        // Bulk actions для постов
        add_filter('bulk_actions-edit-post', array($this, 'add_bulk_actions'));
        add_filter('bulk_actions-edit-page', array($this, 'add_bulk_actions'));
        add_action('handle_bulk_actions-edit-post', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('handle_bulk_actions-edit-page', array($this, 'handle_bulk_actions'), 10, 3);

        // Bulk actions для таксономий
        add_filter('bulk_actions-edit-category', array($this, 'add_bulk_actions'));
        add_filter('bulk_actions-edit-post_tag', array($this, 'add_bulk_actions'));
        add_action('handle_bulk_actions-edit-category', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('handle_bulk_actions-edit-post_tag', array($this, 'handle_bulk_actions'), 10, 3);

        // AJAX обработчики для массового перевода
        add_action('wp_ajax_bulk_translate_posts', array($this, 'bulk_translate_posts'));
        add_action('wp_ajax_bulk_translate_terms', array($this, 'bulk_translate_terms'));
        add_action('wp_ajax_apyt_bulk_translate_taxonomy', array($this, 'apyt_bulk_translate_taxonomy'));

        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));

        // Интерфейс массового перевода для таксономий
        add_action('current_screen', array($this, 'add_bulk_translate_interface'));

        // Увеличиваем лимиты для AJAX запросов
        add_action('admin_init', array($this, 'increase_limits'));
    }

    public function increase_limits() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            @set_time_limit(300); // 5 минут
            if (function_exists('wp_raise_memory_limit')) {
                wp_raise_memory_limit('admin');
            }
        }
    }

    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['translate_with_apyt'] = 'Перевести с Yandex Translate';
        return $bulk_actions;
    }

    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'translate_with_apyt') {
            return $redirect_to;
        }

        if (empty($post_ids)) {
            return $redirect_to;
        }

        $translated_count = 0;
        $core = APYT_Core::get_instance();

        foreach ($post_ids as $post_id) {
            if ($this->create_translations_for_post($post_id)) {
                $translated_count++;
            }
        }

        $redirect_to = add_query_arg('apyt_translated', $translated_count, $redirect_to);
        return $redirect_to;
    }

    private function create_translations_for_post($post_id) {
        $post = get_post($post_id);
        $source_language = pll_get_post_language($post_id);

        if (!$post || !$source_language) {
            return false;
        }

        $target_languages = get_option('apyt_target_languages', array());
        $target_languages = $this->validate_languages($target_languages);
        $target_languages = array_diff($target_languages, array($source_language));

        $success = true;
        $core = APYT_Core::get_instance();

        foreach ($target_languages as $target_lang) {
            $translation_id = pll_get_post($post_id, $target_lang);
            if (!$translation_id) {
                $result = $core->posts->create_post_translation($post_id, $source_language, $target_lang, $post);
                if (!$result) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    public function bulk_translate_posts() {
        // Увеличиваем лимиты
        @set_time_limit(300);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        if (!wp_verify_nonce($_POST['nonce'], 'apyt_bulk_translate')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_types = get_option('apyt_post_types', array('post', 'page'));
        $target_languages = get_option('apyt_target_languages', array());

        if (empty($target_languages)) {
            wp_send_json_error('Целевые языки не настроены');
        }

        $translated_count = 0;
        $error_count = 0;
        $core = APYT_Core::get_instance();

        // Ограничиваем количество постов для избежания таймаута
        $limit = 10;
        $processed = 0;

        foreach ($post_types as $post_type) {
            $posts = get_posts(array(
                    'post_type' => $post_type,
                    'post_status' => 'publish',
                    'numberposts' => $limit,
                    'orderby' => 'ID',
                    'order' => 'ASC'
            ));

            foreach ($posts as $post) {
                if ($processed >= $limit) break;

                $source_language = pll_get_post_language($post->ID);
                if (!$source_language) continue;

                foreach ($target_languages as $target_lang) {
                    if ($target_lang === $source_language) continue;

                    $translation_id = pll_get_post($post->ID, $target_lang);
                    if (!$translation_id) {
                        $result = $core->posts->create_post_translation($post->ID, $source_language, $target_lang, $post);
                        if ($result) {
                            $translated_count++;
                        } else {
                            $error_count++;
                        }
                    }
                }
                $processed++;
            }
        }

        wp_send_json_success(array(
                'message' => sprintf('Перевод завершен. Успешно: %d, Ошибок: %d', $translated_count, $error_count),
                'translated' => $translated_count,
                'errors' => $error_count
        ));
    }

    public function bulk_translate_terms() {
        // Увеличиваем лимиты
        @set_time_limit(300);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        if (!wp_verify_nonce($_POST['nonce'], 'apyt_bulk_translate')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $taxonomies = get_option('apyt_taxonomies', array('category', 'post_tag'));
        $target_languages = get_option('apyt_target_languages', array());

        if (empty($target_languages)) {
            wp_send_json_error('Целевые языки не настроены');
        }

        $translated_count = 0;
        $error_count = 0;
        $core = APYT_Core::get_instance();

        // Добавляем пользовательские таксономии
        $custom_taxonomies = get_taxonomies(array(
                'public'   => true,
                '_builtin' => false
        ));

        $taxonomies = array_merge($taxonomies, $custom_taxonomies);

        // Ограничиваем количество терминов
        $limit = 20;
        $processed = 0;

        foreach ($taxonomies as $taxonomy) {
            if ($processed >= $limit) break;

            $terms = get_terms(array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'number' => 5, // По 5 терминов на таксономию
                    'orderby' => 'term_id',
                    'order' => 'ASC'
            ));

            if (is_wp_error($terms)) {
                error_log("APYT: Error getting terms for taxonomy {$taxonomy}: " . $terms->get_error_message());
                continue;
            }

            foreach ($terms as $term) {
                if ($processed >= $limit) break;

                $source_language = pll_get_term_language($term->term_id);
                if (!$source_language) continue;

                foreach ($target_languages as $target_lang) {
                    if ($target_lang === $source_language) continue;

                    $translation_id = pll_get_term($term->term_id, $target_lang);
                    if (!$translation_id) {
                        $result = $core->terms->create_term_translation($term->term_id, $source_language, $target_lang, $taxonomy);
                        if ($result) {
                            $translated_count++;
                        } else {
                            $error_count++;
                        }
                    }
                }
                $processed++;
            }
        }

        wp_send_json_success(array(
                'message' => sprintf('Перевод терминов завершен. Успешно: %d, Ошибок: %d', $translated_count, $error_count),
                'translated' => $translated_count,
                'errors' => $error_count
        ));
    }

    public function apyt_bulk_translate_taxonomy() {
        // Увеличиваем лимиты для AJAX
        @set_time_limit(300);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        if (!wp_verify_nonce($_POST['nonce'], 'apyt_bulk_translate')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_categories')) {
            wp_send_json_error('Insufficient permissions');
        }

        $taxonomy = sanitize_text_field($_POST['taxonomy']);
        $target_languages = get_option('apyt_target_languages', array());

        if (empty($target_languages)) {
            wp_send_json_error('Целевые языки не настроены');
        }

        // Получаем термины с ограничением
        $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'number' => 10, // Ограничиваем для первого запуска
                'orderby' => 'term_id',
                'order' => 'ASC'
        ));

        if (is_wp_error($terms)) {
            error_log("APYT: Error getting terms for taxonomy {$taxonomy}: " . $terms->get_error_message());
            wp_send_json_error('Ошибка получения терминов: ' . $terms->get_error_message());
        }

        if (empty($terms)) {
            wp_send_json_error('В этой таксономии нет терминов для перевода');
        }

        $translated_count = 0;
        $error_count = 0;
        $core = APYT_Core::get_instance();

        foreach ($terms as $term) {
            $source_language = pll_get_term_language($term->term_id);
            if (!$source_language) {
                error_log("APYT: No source language for term {$term->term_id}");
                continue;
            }

            foreach ($target_languages as $target_lang) {
                if ($target_lang === $source_language) continue;

                $translation_id = pll_get_term($term->term_id, $target_lang);
                if (!$translation_id) {
                    error_log("APYT: Creating translation for term {$term->term_id} to {$target_lang}");
                    $result = $core->terms->create_term_translation($term->term_id, $source_language, $target_lang, $taxonomy);
                    if ($result) {
                        $translated_count++;
                        error_log("APYT: Successfully translated term {$term->term_id} to {$target_lang}");
                    } else {
                        $error_count++;
                        error_log("APYT: Failed to translate term {$term->term_id} to {$target_lang}");
                    }
                } else {
                    error_log("APYT: Translation already exists for term {$term->term_id} to {$target_lang}");
                }
            }
        }

        $taxonomy_name = get_taxonomy($taxonomy) ? get_taxonomy($taxonomy)->label : $taxonomy;

        wp_send_json_success(array(
                'message' => sprintf('Перевод таксономии "%s" завершен. Успешно: %d, Ошибок: %d', $taxonomy_name, $translated_count, $error_count),
                'translated' => $translated_count,
                'errors' => $error_count,
                'total_terms' => count($terms)
        ));
    }

    public function admin_notices() {
        if (!empty($_GET['apyt_translated'])) {
            $count = intval($_GET['apyt_translated']);
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Успешно переведено записей: <?php echo $count; ?></p>
            </div>
            <?php
        }
    }

    public function add_bulk_translate_interface() {
        $screen = get_current_screen();

        if ($screen && in_array($screen->base, array('edit-tags', 'term'))) {
            add_action('admin_notices', array($this, 'bulk_translate_notice'));
        }
    }

    public function bulk_translate_notice() {
        if (!function_exists('pll_languages_list')) return;

        $taxonomy = isset($_GET['taxonomy']) ? $_GET['taxonomy'] : '';
        $enabled_taxonomies = get_option('apyt_taxonomies', array('category', 'post_tag'));

        // Добавляем пользовательские таксономии
        $custom_taxonomies = get_taxonomies(array(
                'public'   => true,
                '_builtin' => false
        ));

        $enabled_taxonomies = array_merge($enabled_taxonomies, $custom_taxonomies);

        if (!in_array($taxonomy, $enabled_taxonomies)) return;

        $target_languages = get_option('apyt_target_languages', array());
        if (empty($target_languages)) return;

        ?>
        <div class="notice notice-info">
            <h3>Массовый перевод таксономий</h3>
            <p>Перевести термины без переводов на выбранные языки:</p>
            <button id="apyt-bulk-translate-current-taxonomy" class="button button-primary" data-taxonomy="<?php echo esc_attr($taxonomy); ?>">
                Перевести термины в "<?php echo get_taxonomy($taxonomy)->label; ?>"
            </button>
            <span id="apyt-bulk-progress" style="margin-left: 10px; display: none;"></span>
            <div id="apyt-bulk-result" style="margin-top: 10px;"></div>

            <div style="margin-top: 10px; font-size: 12px; color: #666;">
                <p><strong>Примечание:</strong> Для избежания таймаута переводятся первые 10 терминов. Повторите для перевода остальных.</p>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#apyt-bulk-translate-current-taxonomy').on('click', function() {
                    var button = $(this);
                    var taxonomy = button.data('taxonomy');

                    button.prop('disabled', true).text('Перевод...');
                    $('#apyt-bulk-progress').show().text('Подготовка...');
                    $('#apyt-bulk-result').html('');

                    $.post(ajaxurl, {
                        action: 'apyt_bulk_translate_taxonomy',
                        taxonomy: taxonomy,
                        nonce: '<?php echo wp_create_nonce("apyt_bulk_translate"); ?>'
                    }, function(response) {
                        if (response.success) {
                            $('#apyt-bulk-result').html(
                                '<div class="notice notice-success">' +
                                '<p>' + response.data.message + '</p>' +
                                '<p>Обработано терминов: ' + response.data.total_terms + '</p>' +
                                '</div>'
                            );
                        } else {
                            $('#apyt-bulk-result').html(
                                '<div class="notice notice-error">' +
                                '<p>Ошибка: ' + response.data + '</p>' +
                                '</div>'
                            );
                        }
                        button.prop('disabled', false).text('Перевести термины в "' + taxonomy + '"');
                        $('#apyt-bulk-progress').hide();
                    }).fail(function(xhr, status, error) {
                        var errorMessage = 'Ошибка сети: ' + error;
                        if (xhr.responseText) {
                            try {
                                var jsonResponse = JSON.parse(xhr.responseText);
                                if (jsonResponse.data) {
                                    errorMessage = jsonResponse.data;
                                }
                            } catch(e) {
                                // Не JSON ответ
                            }
                        }

                        $('#apyt-bulk-result').html(
                            '<div class="notice notice-error">' +
                            '<p>' + errorMessage + '</p>' +
                            '<p>Проверьте логи ошибок WordPress для подробной информации.</p>' +
                            '</div>'
                        );
                        button.prop('disabled', false).text('Перевести термины в "' + taxonomy + '"');
                        $('#apyt-bulk-progress').hide();

                        console.error('APYT AJAX Error:', xhr, status, error);
                    });
                });
            });
        </script>
        <?php
    }

    private function validate_languages($languages) {
        if (!function_exists('pll_languages_list')) {
            return array();
        }

        $valid_languages = pll_languages_list(array('fields' => 'slug'));
        $filtered_languages = array();

        foreach ($languages as $language) {
            if (in_array($language, $valid_languages)) {
                $filtered_languages[] = $language;
            }
        }

        return $filtered_languages;
    }
}