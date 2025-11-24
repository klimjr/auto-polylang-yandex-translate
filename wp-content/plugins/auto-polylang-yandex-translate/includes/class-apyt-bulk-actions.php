<?php

class APYT_Bulk_Actions {

    private static $batch_size = 10;

    public function __construct() {
        add_action('admin_init', array($this, 'add_bulk_actions_for_post_types'));
        add_filter('bulk_actions-edit-category', array($this, 'add_bulk_actions'));
        add_filter('bulk_actions-edit-post_tag', array($this, 'add_bulk_actions'));
        add_action('handle_bulk_actions-edit-category', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('handle_bulk_actions-edit-post_tag', array($this, 'handle_bulk_actions'), 10, 3);

        // AJAX обработчики для массового перевода
        add_action('wp_ajax_bulk_translate_posts', array($this, 'bulk_translate_posts'));
        add_action('wp_ajax_bulk_translate_custom_posts', array($this, 'bulk_translate_custom_posts'));
        add_action('wp_ajax_bulk_translate_terms', array($this, 'bulk_translate_terms'));
        add_action('wp_ajax_apyt_bulk_translate_taxonomy', array($this, 'apyt_bulk_translate_taxonomy'));
        add_action('wp_ajax_apyt_bulk_translate_custom_post_type', array($this, 'apyt_bulk_translate_custom_post_type'));

        // Новые AJAX обработчики для пакетного перевода
        add_action('wp_ajax_apyt_bulk_translate_all_posts', array($this, 'bulk_translate_all_posts'));
        add_action('wp_ajax_apyt_get_translation_stats', array($this, 'get_translation_stats'));

        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));

        // Интерфейс массового перевода
        add_action('current_screen', array($this, 'add_bulk_translate_interface'));

        // Увеличиваем лимиты для AJAX запросов
        add_action('admin_init', array($this, 'increase_limits'));
    }

    public function add_bulk_actions_for_post_types() {
        $enabled_post_types = get_option('apyt_post_types', array('post', 'page'));

        foreach ($enabled_post_types as $post_type) {
            add_filter("bulk_actions-edit-{$post_type}", array($this, 'add_bulk_actions'));
            add_action("handle_bulk_actions-edit-{$post_type}", array($this, 'handle_bulk_actions'), 10, 3);
        }
    }

    public function increase_limits() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            @set_time_limit(300);
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

    // Новый метод для получения статистики перевода
    public function get_translation_stats() {
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

        $stats = array(
                'total_posts' => 0,
                'posts_to_translate' => 0,
                'post_types' => array()
        );

        foreach ($post_types as $post_type) {
            $total_posts = wp_count_posts($post_type);
            $published_posts = $total_posts->publish;

            $untranslated_posts = $this->get_untranslated_posts($post_type, $target_languages, 1);
            $untranslated_count = count($untranslated_posts);

            $stats['total_posts'] += $published_posts;
            $stats['posts_to_translate'] += $untranslated_count;
            $stats['post_types'][$post_type] = array(
                    'total' => $published_posts,
                    'untranslated' => $untranslated_count,
                    'label' => get_post_type_object($post_type)->label
            );
        }

        wp_send_json_success($stats);
    }

    // Новый метод для массового перевода всех записей
    public function bulk_translate_all_posts() {
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
        $batch = isset($_POST['batch']) ? intval($_POST['batch']) : 0;
        $total_processed = isset($_POST['total_processed']) ? intval($_POST['total_processed']) : 0;

        if (empty($target_languages)) {
            wp_send_json_error('Целевые языки не настроены');
        }

        $translated_in_batch = 0;
        $error_count = 0;
        $core = APYT_Core::get_instance();

        // Получаем непереведенные записи для текущего пакета
        $untranslated_posts = $this->get_untranslated_posts($post_types, $target_languages, self::$batch_size, $batch);

        if (empty($untranslated_posts)) {
            wp_send_json_success(array(
                    'completed' => true,
                    'message' => sprintf('Перевод всех записей завершен! Всего переведено: %d записей', $total_processed),
                    'total_processed' => $total_processed,
                    'translated_in_batch' => 0,
                    'errors' => 0
            ));
        }

        foreach ($untranslated_posts as $post) {
            $source_language = pll_get_post_language($post->ID);
            if (!$source_language) {
                error_log("APYT: No source language for post {$post->ID}");
                continue;
            }

            $post_translated = false;
            $post_errors = 0;

            foreach ($target_languages as $target_lang) {
                if ($target_lang === $source_language) continue;

                $translation_id = pll_get_post($post->ID, $target_lang);
                if (!$translation_id) {
                    try {
                        error_log("APYT: Creating translation for post {$post->ID} to {$target_lang}");
                        $result = $core->posts->create_post_translation($post->ID, $source_language, $target_lang, $post);
                        if ($result) {
                            $translated_in_batch++;
                            $post_translated = true;
                            error_log("APYT: Successfully translated post {$post->ID} to {$target_lang}");
                        } else {
                            $error_count++;
                            $post_errors++;
                            error_log("APYT: Failed to translate post {$post->ID} to {$target_lang}");
                        }
                    } catch (Exception $e) {
                        $error_count++;
                        $post_errors++;
                        error_log("APYT: Exception translating post {$post->ID}: " . $e->getMessage());
                    }
                }
            }

            if ($post_translated) {
                $total_processed++;
            }
        }

        $next_batch = $batch + 1;
        $progress_message = sprintf(
                'Пакет %d обработан. Переведено в этом пакете: %d, Ошибок: %d. Всего переведено: %d',
                $batch + 1,
                $translated_in_batch,
                $error_count,
                $total_processed
        );

        wp_send_json_success(array(
                'completed' => false,
                'message' => $progress_message,
                'next_batch' => $next_batch,
                'total_processed' => $total_processed,
                'translated_in_batch' => $translated_in_batch,
                'errors' => $error_count,
                'posts_in_batch' => count($untranslated_posts)
        ));
    }

    // Вспомогательный метод для получения непереведенных записей
    private function get_untranslated_posts($post_types, $target_languages, $limit = 10, $offset = 0) {
        global $wpdb;

        if (!is_array($post_types)) {
            $post_types = array($post_types);
        }

        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $post_types_sql = $wpdb->prepare($placeholders, $post_types);

        $posts_query = "
            SELECT ID, post_type, post_title 
            FROM {$wpdb->posts} 
            WHERE post_type IN ({$post_types_sql}) 
            AND post_status = 'publish'
            ORDER BY ID ASC 
            LIMIT %d OFFSET %d
        ";

        $posts = $wpdb->get_results($wpdb->prepare(
                $posts_query,
                array_merge($post_types, array($limit, $offset * $limit))
        ));

        if (empty($posts)) {
            return array();
        }

        // Фильтруем записи, у которых нет переводов для всех целевых языков
        $untranslated_posts = array();
        foreach ($posts as $post) {
            $source_language = pll_get_post_language($post->ID);
            if (!$source_language) continue;

            $needs_translation = false;
            foreach ($target_languages as $target_lang) {
                if ($target_lang === $source_language) continue;

                $translation_id = pll_get_post($post->ID, $target_lang);
                if (!$translation_id) {
                    $needs_translation = true;
                    break;
                }
            }

            if ($needs_translation) {
                $untranslated_posts[] = $post;
            }
        }

        return $untranslated_posts;
    }

    public function bulk_translate_posts() {
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

    public function bulk_translate_custom_posts() {
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

        $custom_post_types = array_filter($post_types, function($post_type) {
            return !in_array($post_type, array('post', 'page'));
        });

        if (empty($custom_post_types)) {
            wp_send_json_error('Нет настроенных кастомных типов записей');
        }

        $limit = 15;
        $processed = 0;

        foreach ($custom_post_types as $post_type) {
            if ($processed >= $limit) break;

            $posts = get_posts(array(
                    'post_type' => $post_type,
                    'post_status' => 'publish',
                    'numberposts' => 5,
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
                'message' => sprintf('Перевод кастомных записей завершен. Успешно: %d, Ошибок: %d', $translated_count, $error_count),
                'translated' => $translated_count,
                'errors' => $error_count,
                'post_types' => $custom_post_types
        ));
    }

    public function apyt_bulk_translate_custom_post_type() {
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

        $post_type = sanitize_text_field($_POST['post_type']);
        $target_languages = get_option('apyt_target_languages', array());

        if (empty($target_languages)) {
            wp_send_json_error('Целевые языки не настроены');
        }

        $enabled_post_types = get_option('apyt_post_types', array('post', 'page'));
        if (!in_array($post_type, $enabled_post_types)) {
            wp_send_json_error('Тип записи не включен в настройках перевода');
        }

        $posts = get_posts(array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'numberposts' => 10,
                'orderby' => 'ID',
                'order' => 'ASC'
        ));

        if (empty($posts)) {
            wp_send_json_error('В этом типе записей нет материалов для перевода');
        }

        $translated_count = 0;
        $error_count = 0;
        $core = APYT_Core::get_instance();

        foreach ($posts as $post) {
            $source_language = pll_get_post_language($post->ID);
            if (!$source_language) {
                error_log("APYT: No source language for post {$post->ID}");
                continue;
            }

            foreach ($target_languages as $target_lang) {
                if ($target_lang === $source_language) continue;

                $translation_id = pll_get_post($post->ID, $target_lang);
                if (!$translation_id) {
                    error_log("APYT: Creating translation for post {$post->ID} to {$target_lang}");
                    $result = $core->posts->create_post_translation($post->ID, $source_language, $target_lang, $post);
                    if ($result) {
                        $translated_count++;
                        error_log("APYT: Successfully translated post {$post->ID} to {$target_lang}");
                    } else {
                        $error_count++;
                        error_log("APYT: Failed to translate post {$post->ID} to {$target_lang}");
                    }
                } else {
                    error_log("APYT: Translation already exists for post {$post->ID} to {$target_lang}");
                }
            }
        }

        $post_type_name = get_post_type_object($post_type) ? get_post_type_object($post_type)->label : $post_type;

        wp_send_json_success(array(
                'message' => sprintf('Перевод типа записей "%s" завершен. Успешно: %d, Ошибок: %d', $post_type_name, $translated_count, $error_count),
                'translated' => $translated_count,
                'errors' => $error_count,
                'total_posts' => count($posts)
        ));
    }

    public function bulk_translate_terms() {
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

        $custom_taxonomies = get_taxonomies(array(
                'public'   => true,
                '_builtin' => false
        ));

        $taxonomies = array_merge($taxonomies, $custom_taxonomies);

        $limit = 20;
        $processed = 0;

        foreach ($taxonomies as $taxonomy) {
            if ($processed >= $limit) break;

            $terms = get_terms(array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'number' => 5,
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

        $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'number' => 10,
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

        if ($screen && $screen->base === 'settings_page_auto-polylang-yandex-translate') {
            add_action('admin_notices', array($this, 'bulk_translate_settings_notice'));
        }
    }

    public function bulk_translate_settings_notice() {
        if (!function_exists('pll_languages_list')) return;

        $target_languages = get_option('apyt_target_languages', array());
        if (empty($target_languages)) return;

        ?>
        <div class="notice notice-info">
            <h3>📦 Массовый перевод всех записей</h3>
            <div id="bulk-translate-all-section">
                <p>Перевести <strong>все непереведенные записи</strong> на выбранные языки:</p>
                <button id="apyt-bulk-translate-all" class="button button-primary">🚀 Запустить полный перевод всех записей</button>
                <button id="apyt-get-stats" class="button button-secondary">📊 Показать статистику</button>

                <div id="bulk-translate-progress" style="margin-top: 15px; display: none;">
                    <div class="progress-bar" style="background: #f0f0f0; border-radius: 5px; height: 20px; margin: 10px 0;">
                        <div id="progress-bar-inner" style="background: #0073aa; height: 100%; border-radius: 5px; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <div id="progress-text" style="text-align: center; font-weight: bold;"></div>
                    <div id="progress-details" style="margin-top: 10px; font-size: 12px;"></div>
                </div>

                <div id="bulk-stats" style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-radius: 4px; display: none;"></div>
                <div id="bulk-all-result" style="margin-top: 15px;"></div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Получение статистики
                $('#apyt-get-stats').on('click', function() {
                    var button = $(this);
                    button.prop('disabled', true).text('Загрузка...');

                    $.post(ajaxurl, {
                        action: 'apyt_get_translation_stats',
                        nonce: '<?php echo wp_create_nonce("apyt_bulk_translate"); ?>'
                    }, function(response) {
                        button.prop('disabled', false).text('📊 Показать статистику');

                        if (response.success) {
                            var stats = response.data;
                            var html = '<h4>📈 Статистика перевода:</h4>';
                            html += '<p><strong>Всего записей:</strong> ' + stats.total_posts + '</p>';
                            html += '<p><strong>Требуют перевода:</strong> ' + stats.posts_to_translate + '</p>';
                            html += '<h4>📂 По типам записей:</h4>';

                            for (var post_type in stats.post_types) {
                                if (stats.post_types.hasOwnProperty(post_type)) {
                                    var type = stats.post_types[post_type];
                                    html += '<p><strong>' + type.label + ':</strong> ' + type.untranslated + ' из ' + type.total + ' требуют перевода</p>';
                                }
                            }

                            $('#bulk-stats').html(html).show();
                        } else {
                            $('#bulk-stats').html('<div class="notice notice-error">❌ Ошибка: ' + response.data + '</div>').show();
                        }
                    }).fail(function(xhr, status, error) {
                        button.prop('disabled', false).text('📊 Показать статистику');
                        $('#bulk-stats').html('<div class="notice notice-error">❌ Ошибка сети: ' + error + '</div>').show();
                    });
                });

                // Полный перевод всех записей
                $('#apyt-bulk-translate-all').on('click', function() {
                    var button = $(this);
                    button.prop('disabled', true).text('Подготовка...');
                    $('#bulk-translate-progress').show();
                    $('#bulk-all-result').html('');
                    $('#bulk-stats').hide();

                    var batch = 0;
                    var totalProcessed = 0;
                    var totalTranslated = 0;
                    var totalErrors = 0;

                    function processBatch() {
                        $('#progress-text').text('🔄 Обработка пакета ' + (batch + 1) + '...');
                        $('#progress-details').html('Всего переведено: ' + totalProcessed + ' | Ошибок: ' + totalErrors);

                        $.post(ajaxurl, {
                            action: 'apyt_bulk_translate_all_posts',
                            batch: batch,
                            total_processed: totalProcessed,
                            nonce: '<?php echo wp_create_nonce("apyt_bulk_translate"); ?>'
                        }, function(response) {
                            if (response.success) {
                                if (response.data.completed) {
                                    // Перевод завершен
                                    $('#progress-bar-inner').css('width', '100%');
                                    $('#progress-text').text('✅ Перевод завершен!');
                                    $('#progress-details').html('Всего переведено записей: ' + response.data.total_processed);
                                    $('#bulk-all-result').html(
                                        '<div class="notice notice-success">' +
                                        '<h4>✅ Перевод завершен!</h4>' +
                                        '<p>' + response.data.message + '</p>' +
                                        '</div>'
                                    );
                                    button.prop('disabled', false).text('🚀 Запустить полный перевод всех записей');
                                } else {
                                    // Продолжаем обработку
                                    totalProcessed = response.data.total_processed;
                                    totalTranslated += response.data.translated_in_batch;
                                    totalErrors += response.data.errors;
                                    batch = response.data.next_batch;

                                    // Обновляем прогресс-бар
                                    var progress = Math.min((batch * 10), 95);
                                    $('#progress-bar-inner').css('width', progress + '%');

                                    $('#progress-text').text(response.data.message);
                                    $('#progress-details').html(
                                        'Обработано пакетов: ' + batch + ' | ' +
                                        'Всего переведено: ' + totalProcessed + ' | ' +
                                        'Ошибок: ' + totalErrors
                                    );

                                    // Следующий пакет с небольшой задержкой
                                    setTimeout(processBatch, 1000);
                                }
                            } else {
                                // Ошибка
                                $('#bulk-all-result').html(
                                    '<div class="notice notice-error">' +
                                    '<h4>❌ Ошибка</h4>' +
                                    '<p>' + response.data + '</p>' +
                                    '</div>'
                                );
                                button.prop('disabled', false).text('🚀 Запустить полный перевод всех записей');
                                $('#progress-text').text('❌ Ошибка');
                            }
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

                            $('#bulk-all-result').html(
                                '<div class="notice notice-error">' +
                                '<h4>❌ Ошибка сети</h4>' +
                                '<p>' + errorMessage + '</p>' +
                                '</div>'
                            );
                            button.prop('disabled', false).text('🚀 Запустить полный перевод всех записей');
                            $('#progress-text').text('❌ Ошибка сети');
                        });
                    }

                    // Запускаем первый пакет
                    processBatch();
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