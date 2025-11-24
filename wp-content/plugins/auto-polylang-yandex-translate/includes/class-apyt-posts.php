<?php

class APYT_Posts {

    private static $is_processing = false;
    private static $processed_posts = array();

    public function __construct() {
        // Используем хук с более высоким приоритетом и проверяем флаги
        add_action('save_post', array($this, 'handle_post_save'), 5, 3);
        add_action('add_meta_boxes', array($this, 'add_translate_meta_box'));
        add_action('wp_ajax_manual_translate_post', array($this, 'manual_translate_post'));
        add_action('wp_ajax_update_translation_post', array($this, 'update_translation_post'));
    }

    public function add_translate_meta_box() {
        $post_types = get_option('apyt_post_types', array('post', 'page'));
        foreach ($post_types as $post_type) {
            add_meta_box(
                'apyt_translate_meta_box',
                'Переводы Polylang',
                array($this, 'render_translate_meta_box'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    public function render_translate_meta_box($post) {
        if (!function_exists('pll_languages_list')) {
            echo '<p>Polylang не активирован</p>';
            return;
        }

        $languages = pll_languages_list(array('fields' => ''));
        $source_language = pll_get_post_language($post->ID);
        $target_languages = get_option('apyt_target_languages', array());

        if (empty($target_languages)) {
            echo '<p>Целевые языки не настроены. <a href="' . admin_url('options-general.php?page=auto-polylang-yandex-translate') . '">Настроить</a></p>';
            return;
        }

        if (!$source_language) {
            echo '<p>Исходный язык не установлен для этой записи</p>';
            return;
        }

        echo '<div class="apyt-translations">';
        echo '<p><strong>Исходный язык:</strong> ' . esc_html($source_language) . '</p>';

        foreach ($languages as $language) {
            if ($language->slug === $source_language) continue;
            if (!in_array($language->slug, $target_languages)) continue;

            $translation_id = pll_get_post($post->ID, $language->slug);
            $status = $translation_id ? '✅ Переведено' : '❌ Нет перевода';

            echo '<div class="apyt-language-row" style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee;">';
            echo '<strong>' . esc_html($language->name) . ':</strong> ' . $status;

            if (!$translation_id) {
                echo '<br><button type="button" class="button button-small manual-translate-post" data-post-id="' . esc_attr($post->ID) . '" data-language="' . esc_attr($language->slug) . '" style="margin-top: 5px;">Перевести сейчас</button>';
            } else {
                $edit_link = get_edit_post_link($translation_id);
                echo ' <a href="' . esc_url($edit_link) . '" class="button button-small" style="margin-top: 5px;">Редактировать</a>';
                if (get_option('apyt_update_translations') === 'yes') {
                    echo ' <button type="button" class="button button-small update-translation-post" data-post-id="' . esc_attr($post->ID) . '" data-language="' . esc_attr($language->slug) . '" style="margin-top: 5px;">Обновить</button>';
                }
            }

            echo '</div>';
        }

        echo '</div>';
    }

    public function handle_post_save($post_id, $post, $update) {
        // Глобальная защита от рекурсии
        if (self::$is_processing) {
            error_log("APYT: Global processing lock active, skipping post {$post_id}");
            return;
        }

        // Проверяем, не обрабатывали ли мы уже этот пост в этом запросе
        if (in_array($post_id, self::$processed_posts)) {
            error_log("APYT: Post {$post_id} already processed in this request, skipping");
            return;
        }

        // Проверяем транзиент для защиты от параллельных запросов
        $lock_key = 'apyt_processing_' . $post_id;
        if (get_transient($lock_key)) {
            error_log("APYT: Post {$post_id} is being processed by another request, skipping");
            return;
        }

        // Устанавливаем блокировку на 30 секунд
        set_transient($lock_key, true, 30);

        // Добавляем в обработанные
        self::$processed_posts[] = $post_id;

        // Проверяем условия для перевода
        if (!$this->should_translate_post($post_id, $post, $update)) {
            delete_transient($lock_key);
            return;
        }

        $source_language = pll_get_post_language($post_id);
        if (!$source_language) {
            error_log("APYT: Source language not found for post {$post_id}");
            delete_transient($lock_key);
            return;
        }

        $target_languages = get_option('apyt_target_languages', array());
        $target_languages = $this->validate_languages($target_languages);
        $target_languages = array_diff($target_languages, array($source_language));

        if (empty($target_languages)) {
            error_log("APYT: No valid target languages for post {$post_id}");
            delete_transient($lock_key);
            return;
        }

        error_log("APYT: Starting translation for post {$post_id} to languages: " . implode(', ', $target_languages));

        // Устанавливаем глобальный флаг обработки
        self::$is_processing = true;

        foreach ($target_languages as $target_lang) {
            $translation_id = pll_get_post($post_id, $target_lang);

            if (!$translation_id) {
                // Создаем новый перевод
                error_log("APYT: Creating translation for post {$post_id} to {$target_lang}");
                $result = $this->create_post_translation($post_id, $source_language, $target_lang, $post);
                if ($result) {
                    error_log("APYT: Successfully created translation for post {$post_id} to {$target_lang}");
                } else {
                    error_log("APYT: Failed to create translation for post {$post_id} to {$target_lang}");
                }
            } elseif (get_option('apyt_update_translations') === 'yes') {
                // Обновляем существующий перевод
                error_log("APYT: Updating translation for post {$post_id} to {$target_lang}");
                $result = $this->update_post_translation($post_id, $translation_id, $source_language, $target_lang, $post);
                if ($result) {
                    error_log("APYT: Successfully updated translation for post {$post_id} to {$target_lang}");
                } else {
                    error_log("APYT: Failed to update translation for post {$post_id} to {$target_lang}");
                }
            }
        }

        // Снимаем блокировки
        self::$is_processing = false;
        delete_transient($lock_key);

        error_log("APYT: Finished processing post {$post_id}");
    }

    private function should_translate_post($post_id, $post, $update) {
        // Пропускаем ревизии
        if (wp_is_post_revision($post_id)) {
            error_log("APYT: Revision, skipping post {$post_id}");
            return false;
        }

        // Пропускаем автосохранения
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            error_log("APYT: Autosave, skipping post {$post_id}");
            return false;
        }

        // Пропускаем черновики и авто-черновики
        if ($post->post_status === 'auto-draft' || $post->post_status === 'draft') {
            error_log("APYT: Draft or auto-draft, skipping post {$post_id}");
            return false;
        }

        // Проверяем тип записи
        $enabled_post_types = get_option('apyt_post_types', array('post', 'page'));
        if (!in_array($post->post_type, $enabled_post_types)) {
            error_log("APYT: Post type {$post->post_type} not enabled for translation");
            return false;
        }

        // Проверяем настройки автоперевода
        if (get_option('apyt_auto_translate') !== 'yes') {
            error_log("APYT: Auto translate disabled");
            return false;
        }

        // Проверяем API ключ
        if (empty(get_option('apyt_yandex_api_key'))) {
            error_log("APYT: No API key configured");
            return false;
        }

        // Проверяем, не является ли это переводом, созданным нашим плагином
        if (get_post_meta($post_id, '_apyt_created', true)) {
            error_log("APYT: Post {$post_id} was created by APYT, skipping");
            return false;
        }

        error_log("APYT: Post {$post_id} passed all checks, proceeding with translation");
        return true;
    }

    public function create_post_translation($post_id, $source_lang, $target_lang, $post) {
        $core = APYT_Core::get_instance();

        try {
            // Переводим основные поля
            $translated_title = $core->api->translate_text($post->post_title, $target_lang, $source_lang);
            $translated_content = $core->api->translate_text($post->post_content, $target_lang, $source_lang);
            $translated_excerpt = $post->post_excerpt ?
                $core->api->translate_text($post->post_excerpt, $target_lang, $source_lang) : '';

            // Создаем переведенный пост
            $translated_post = array(
                'post_title'    => $translated_title,
                'post_content'  => $translated_content,
                'post_excerpt'  => $translated_excerpt,
                'post_status'   => $post->post_status,
                'post_type'     => $post->post_type,
                'post_author'   => $post->post_author,
                'post_parent'   => $post->post_parent,
                'menu_order'    => $post->menu_order,
                'comment_status'=> $post->comment_status,
                'ping_status'   => $post->ping_status,
            );

            // Полностью отключаем наши хуки на время создания поста
            $this->disable_hooks();

            $new_post_id = wp_insert_post($translated_post, true);

            // Восстанавливаем хуки
            $this->enable_hooks();

            if (is_wp_error($new_post_id)) {
                error_log("APYT: Error creating post: " . $new_post_id->get_error_message());
                return false;
            }

            if ($new_post_id) {
                // Помечаем пост как созданный нашим плагином
                update_post_meta($new_post_id, '_apyt_created', true);
                update_post_meta($new_post_id, '_apyt_source_post', $post_id);
                update_post_meta($new_post_id, '_apyt_source_lang', $source_lang);

                // Устанавливаем язык и связываем переводы
                pll_set_post_language($new_post_id, $target_lang);

                $translations = pll_get_post_translations($post_id);
                $translations[$target_lang] = $new_post_id;
                pll_save_post_translations($translations);

                // Копируем мета-данные и таксономии
                $this->copy_post_meta($post_id, $new_post_id, $target_lang, $source_lang);
                $this->copy_taxonomies($post_id, $new_post_id, $target_lang, $source_lang);

                // Копируем ACF поля и изображения
                if (get_option('apyt_translate_acf') === 'yes' && function_exists('get_field_objects')) {
                    $core->acf->copy_post_acf_fields($post_id, $new_post_id, $target_lang, $source_lang);
                }

                if (get_option('apyt_translate_images') === 'yes') {
                    $core->images->copy_attached_images($post_id, $new_post_id, $target_lang, $source_lang);
                }

                error_log("APYT: Successfully created translation for post {$post_id} to {$target_lang}. New post ID: {$new_post_id}");
                return $new_post_id;
            }
        } catch (Exception $e) {
            error_log("APYT: Exception in create_post_translation: " . $e->getMessage());
        }

        return false;
    }

    public function update_post_translation($source_post_id, $translation_id, $source_lang, $target_lang, $post) {
        $core = APYT_Core::get_instance();

        try {
            // Переводим обновленные поля
            $translated_title = $core->api->translate_text($post->post_title, $target_lang, $source_lang);
            $translated_content = $core->api->translate_text($post->post_content, $target_lang, $source_lang);
            $translated_excerpt = $post->post_excerpt ?
                $core->api->translate_text($post->post_excerpt, $target_lang, $source_lang) : '';

            $updated_post = array(
                'ID'           => $translation_id,
                'post_title'   => $translated_title,
                'post_content' => $translated_content,
                'post_excerpt' => $translated_excerpt,
            );

            // Отключаем хуки на время обновления
            $this->disable_hooks();

            $result = wp_update_post($updated_post, true);

            // Восстанавливаем хуки
            $this->enable_hooks();

            if (is_wp_error($result)) {
                error_log("APYT: Error updating post translation: " . $result->get_error_message());
                return false;
            }

            // Обновляем мета-данные и таксономии
            $this->copy_post_meta($source_post_id, $translation_id, $target_lang, $source_lang);
            $this->copy_taxonomies($source_post_id, $translation_id, $target_lang, $source_lang);

            // Обновляем ACF поля и изображения
            if (get_option('apyt_translate_acf') === 'yes' && function_exists('get_field_objects')) {
                $core->acf->copy_post_acf_fields($source_post_id, $translation_id, $target_lang, $source_lang);
            }

            if (get_option('apyt_translate_images') === 'yes') {
                $core->images->copy_attached_images($source_post_id, $translation_id, $target_lang, $source_lang);
            }

            error_log("APYT: Successfully updated translation for post {$source_post_id} to {$target_lang}");
            return true;

        } catch (Exception $e) {
            error_log("APYT: Exception in update_post_translation: " . $e->get_message());
            return false;
        }
    }

    private function disable_hooks() {
        remove_action('save_post', array($this, 'handle_post_save'), 5);
    }

    private function enable_hooks() {
        add_action('save_post', array($this, 'handle_post_save'), 5, 3);
    }

    public function manual_translate_post() {
        if (!wp_verify_nonce($_POST['nonce'], 'apyt_manual_translate')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_id = intval($_POST['post_id']);
        $target_lang = sanitize_text_field($_POST['language']);

        $post = get_post($post_id);
        $source_lang = pll_get_post_language($post_id);

        if (!$post || !$source_lang) {
            wp_send_json_error('Post or language not found');
        }

        $valid_languages = $this->validate_languages(array($target_lang));
        if (empty($valid_languages)) {
            wp_send_json_error('Target language not configured in Polylang');
        }

        // Проверяем, не существует ли уже перевод
        $existing_translation = pll_get_post($post_id, $target_lang);
        if ($existing_translation) {
            wp_send_json_error('Translation already exists');
        }

        // Устанавливаем глобальный флаг
        self::$is_processing = true;

        $result = $this->create_post_translation($post_id, $source_lang, $target_lang, $post);

        // Снимаем флаг
        self::$is_processing = false;

        if ($result) {
            $edit_link = get_edit_post_link($result);
            wp_send_json_success(array(
                'message' => 'Translation created successfully',
                'edit_link' => $edit_link
            ));
        } else {
            wp_send_json_error('Translation failed - check error logs');
        }
    }

    public function update_translation_post() {
        if (!wp_verify_nonce($_POST['nonce'], 'apyt_manual_translate')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_id = intval($_POST['post_id']);
        $target_lang = sanitize_text_field($_POST['language']);

        $post = get_post($post_id);
        $source_lang = pll_get_post_language($post_id);
        $translation_id = pll_get_post($post_id, $target_lang);

        if (!$post || !$source_lang || !$translation_id) {
            wp_send_json_error('Post, language or translation not found');
        }

        // Устанавливаем глобальный флаг
        self::$is_processing = true;

        $result = $this->update_post_translation($post_id, $translation_id, $source_lang, $target_lang, $post);

        // Снимаем флаг
        self::$is_processing = false;

        if ($result) {
            wp_send_json_success('Translation updated successfully');
        } else {
            wp_send_json_error('Translation update failed - check error logs');
        }
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

    private function copy_post_meta($source_post_id, $target_post_id, $target_lang, $source_lang) {
        $meta_data = get_post_meta($source_post_id);

        foreach ($meta_data as $key => $values) {
            // Пропускаем системные мета-поля и наши служебные поля
            if (in_array($key, array('_edit_lock', '_edit_last', '_wp_old_slug', '_apyt_created', '_apyt_source_post', '_apyt_source_lang', '_thumbnail_id'))) continue;

            foreach ($values as $value) {
                update_post_meta($target_post_id, $key, $value);
            }
        }
    }

    private function copy_taxonomies($source_post_id, $target_post_id, $target_lang, $source_lang) {
        $taxonomies = get_object_taxonomies(get_post_type($source_post_id));

        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($source_post_id, $taxonomy);

            $translated_terms = array();
            foreach ($terms as $term) {
                $translated_term_id = pll_get_term($term->term_id, $target_lang);
                if ($translated_term_id) {
                    $translated_terms[] = $translated_term_id;
                }
            }

            if (!empty($translated_terms)) {
                wp_set_object_terms($target_post_id, $translated_terms, $taxonomy);
            }
        }
    }
}