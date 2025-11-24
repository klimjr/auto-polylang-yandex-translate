<?php

class APYT_Terms {

    private static $is_processing = false;

    public function __construct() {
        add_action('created_term', array($this, 'handle_term_save'), 5, 3);
        add_action('edited_term', array($this, 'handle_term_save'), 5, 3);
        add_action('wp_ajax_manual_translate_term', array($this, 'manual_translate_term'));
    }

    public function handle_term_save($term_id, $tt_id, $taxonomy) {
        // Глобальная защита от рекурсии
        if (self::$is_processing) {
            error_log("APYT: Global processing lock active, skipping term {$term_id}");
            return;
        }

        // Проверяем условия для перевода
        if (!$this->should_translate_term($term_id, $taxonomy)) {
            return;
        }

        $source_language = pll_get_term_language($term_id);
        if (!$source_language) {
            error_log("APYT: Source language not found for term {$term_id}");
            return;
        }

        $target_languages = get_option('apyt_target_languages', array());
        $target_languages = $this->validate_languages($target_languages);
        $target_languages = array_diff($target_languages, array($source_language));

        if (empty($target_languages)) {
            error_log("APYT: No valid target languages for term {$term_id}");
            return;
        }

        error_log("APYT: Starting translation for term {$term_id} to languages: " . implode(', ', $target_languages));

        // Устанавливаем глобальный флаг обработки
        self::$is_processing = true;

        foreach ($target_languages as $target_lang) {
            $translation_id = pll_get_term($term_id, $target_lang);

            if (!$translation_id) {
                // Создаем новый перевод
                error_log("APYT: Creating translation for term {$term_id} to {$target_lang}");
                $result = $this->create_term_translation($term_id, $source_language, $target_lang, $taxonomy);
                if ($result) {
                    error_log("APYT: Successfully created translation for term {$term_id} to {$target_lang}");
                } else {
                    error_log("APYT: Failed to create translation for term {$term_id} to {$target_lang}");
                }
            } elseif (get_option('apyt_update_translations') === 'yes') {
                // Обновляем существующий перевод
                error_log("APYT: Updating translation for term {$term_id} to {$target_lang}");
                $result = $this->update_term_translation($term_id, $translation_id, $source_language, $target_lang, $taxonomy);
                if ($result) {
                    error_log("APYT: Successfully updated translation for term {$term_id} to {$target_lang}");
                } else {
                    error_log("APYT: Failed to update translation for term {$term_id} to {$target_lang}");
                }
            }
        }

        // Снимаем флаг
        self::$is_processing = false;

        error_log("APYT: Finished processing term {$term_id}");
    }

    private function should_translate_term($term_id, $taxonomy) {
        // Проверяем тип таксономии
        $enabled_taxonomies = get_option('apyt_taxonomies', array('category', 'post_tag'));
        if (!in_array($taxonomy, $enabled_taxonomies)) {
            error_log("APYT: Taxonomy {$taxonomy} not enabled for translation");
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

        error_log("APYT: Term {$term_id} passed all checks, proceeding with translation");
        return true;
    }

    public function create_term_translation($term_id, $source_lang, $target_lang, $taxonomy) {
        $core = APYT_Core::get_instance();
        $term = get_term($term_id, $taxonomy);

        if (is_wp_error($term)) {
            error_log("APYT: Error getting term {$term_id}: " . $term->get_error_message());
            return false;
        }

        $translated_name = $core->api->translate_text($term->name, $target_lang, $source_lang);
        $translated_description = $term->description ?
            $core->api->translate_text($term->description, $target_lang, $source_lang) : '';

        // Отключаем наши хуки
        $this->disable_hooks();

        $new_term = wp_insert_term($translated_name, $taxonomy, array(
            'description' => $translated_description,
            'slug'        => sanitize_title($translated_name) . '-' . $target_lang,
            'parent'      => $term->parent
        ));

        // Восстанавливаем хуки
        $this->enable_hooks();

        if (!is_wp_error($new_term)) {
            pll_set_term_language($new_term['term_id'], $target_lang);

            $term_translations = pll_get_term_translations($term_id);
            $term_translations[$target_lang] = $new_term['term_id'];
            pll_save_term_translations($term_translations);

            // Копируем ACF поля для термина
            if (get_option('apyt_translate_acf') === 'yes' && function_exists('get_field_objects')) {
                $core->acf->copy_term_acf_fields($term_id, $new_term['term_id'], $target_lang, $source_lang, $taxonomy);
            }

            error_log("APYT: Successfully created translation for term {$term_id} to {$target_lang}. New term ID: {$new_term['term_id']}");
            return $new_term['term_id'];
        } else {
            error_log("APYT: Failed to create translation for term {$term_id} to {$target_lang}: " . $new_term->get_error_message());
            return false;
        }
    }

    public function update_term_translation($source_term_id, $translation_id, $source_lang, $target_lang, $taxonomy) {
        $core = APYT_Core::get_instance();
        $source_term = get_term($source_term_id, $taxonomy);

        if (is_wp_error($source_term)) {
            error_log("APYT: Error getting source term {$source_term_id}");
            return false;
        }

        $translated_name = $core->api->translate_text($source_term->name, $target_lang, $source_lang);
        $translated_description = $source_term->description ?
            $core->api->translate_text($source_term->description, $target_lang, $source_lang) : '';

        // Отключаем наши хуки
        $this->disable_hooks();

        $updated_term = wp_update_term($translation_id, $taxonomy, array(
            'name'        => $translated_name,
            'description' => $translated_description,
            'slug'        => sanitize_title($translated_name) . '-' . $target_lang,
        ));

        // Восстанавливаем хуки
        $this->enable_hooks();

        if (!is_wp_error($updated_term)) {
            // Обновляем ACF поля для термина
            if (get_option('apyt_translate_acf') === 'yes' && function_exists('get_field_objects')) {
                $core->acf->copy_term_acf_fields($source_term_id, $translation_id, $target_lang, $source_lang, $taxonomy);
            }

            error_log("APYT: Successfully updated translation for term {$source_term_id} to {$target_lang}");
            return true;
        } else {
            error_log("APYT: Failed to update translation for term {$source_term_id} to {$target_lang}: " . $updated_term->get_error_message());
            return false;
        }
    }

    private function disable_hooks() {
        remove_action('created_term', array($this, 'handle_term_save'), 5);
        remove_action('edited_term', array($this, 'handle_term_save'), 5);
    }

    private function enable_hooks() {
        add_action('created_term', array($this, 'handle_term_save'), 5, 3);
        add_action('edited_term', array($this, 'handle_term_save'), 5, 3);
    }

    public function manual_translate_term() {
        if (!wp_verify_nonce($_POST['nonce'], 'apyt_manual_translate')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_categories')) {
            wp_send_json_error('Insufficient permissions');
        }

        $term_id = intval($_POST['term_id']);
        $target_lang = sanitize_text_field($_POST['language']);
        $taxonomy = sanitize_text_field($_POST['taxonomy']);

        $source_lang = pll_get_term_language($term_id);

        if (!$source_lang) {
            wp_send_json_error('Term or language not found');
        }

        $valid_languages = $this->validate_languages(array($target_lang));
        if (empty($valid_languages)) {
            wp_send_json_error('Target language not configured in Polylang');
        }

        // Проверяем, не существует ли уже перевод
        $existing_translation = pll_get_term($term_id, $target_lang);
        if ($existing_translation) {
            wp_send_json_error('Translation already exists');
        }

        // Устанавливаем глобальный флаг
        self::$is_processing = true;

        $result = $this->create_term_translation($term_id, $source_lang, $target_lang, $taxonomy);

        // Снимаем флаг
        self::$is_processing = false;

        if ($result) {
            wp_send_json_success('Translation created successfully');
        } else {
            wp_send_json_error('Translation failed');
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
}