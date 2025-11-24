<?php

class APYT_ACF {

    public function copy_post_acf_fields($source_post_id, $target_post_id, $target_lang, $source_lang) {
        if (!function_exists('get_field_objects')) {
            return;
        }

        $field_objects = get_field_objects($source_post_id);

        if (!$field_objects) {
            return;
        }

        foreach ($field_objects as $field_name => $field) {
            $value = get_field($field_name, $source_post_id, false);

            if ($value !== false && $value !== null && $value !== '') {
                $translated_value = $this->translate_acf_field_value($value, $field, $target_lang, $source_lang, $target_post_id);

                // Убеждаемся, что мы не сохраняем пустые значения
                if ($translated_value !== false && $translated_value !== null) {
                    update_field($field_name, $translated_value, $target_post_id);
                }
            }
        }
    }

    public function copy_term_acf_fields($source_term_id, $target_term_id, $target_lang, $source_lang, $taxonomy) {
        if (!function_exists('get_field_objects')) {
            return;
        }

        $field_objects = get_field_objects($taxonomy . '_' . $source_term_id);

        if (!$field_objects) {
            return;
        }

        foreach ($field_objects as $field_name => $field) {
            $value = get_field($field_name, $taxonomy . '_' . $source_term_id, false);

            if ($value !== false && $value !== null && $value !== '') {
                $translated_value = $this->translate_acf_field_value($value, $field, $target_lang, $source_lang, $target_term_id);

                if ($translated_value !== false && $translated_value !== null) {
                    update_field($field_name, $translated_value, $taxonomy . '_' . $target_term_id);
                }
            }
        }
    }

    public function translate_acf_field_value($value, $field, $target_lang, $source_lang, $target_id = null) {
        $core = APYT_Core::get_instance();
        $field_type = $field['type'];

        // Логируем обработку поля для отладки
        error_log("APYT ACF: Processing field {$field['name']} of type {$field_type}");

        switch ($field_type) {
            case 'text':
            case 'textarea':
            case 'wysiwyg':
            case 'email':
            case 'url':
                if (is_string($value) && !empty(trim($value))) {
                    $translated = $core->api->translate_text($value, $target_lang, $source_lang);
                    error_log("APYT ACF: Translated text field {$field['name']}: '{$value}' -> '{$translated}'");
                    return $translated;
                }
                break;

            case 'gallery':
                if (is_array($value) && !empty($value)) {
                    $translated_gallery = array();
                    foreach ($value as $image_id) {
                        $translated_image_id = $core->images->duplicate_attachment($image_id, $target_id, $target_lang);
                        if ($translated_image_id) {
                            $translated_gallery[] = $translated_image_id;
                        } else {
                            $translated_gallery[] = $image_id;
                        }
                    }
                    error_log("APYT ACF: Processed gallery field {$field['name']} with " . count($translated_gallery) . " images");
                    return $translated_gallery;
                }
                break;

            case 'image':
                if (is_numeric($value) && $value > 0) {
                    $translated_image_id = $core->images->duplicate_attachment($value, $target_id, $target_lang);
                    $result = $translated_image_id ? $translated_image_id : $value;
                    error_log("APYT ACF: Processed image field {$field['name']}: {$value} -> {$result}");
                    return $result;
                }
                break;

            case 'file':
                if (is_numeric($value) && $value > 0) {
                    $translated_file_id = $core->images->duplicate_attachment($value, $target_id, $target_lang);
                    $result = $translated_file_id ? $translated_file_id : $value;
                    error_log("APYT ACF: Processed file field {$field['name']}: {$value} -> {$result}");
                    return $result;
                }
                break;

            case 'repeater':
                if (is_array($value) && !empty($value)) {
                    error_log("APYT ACF: Processing repeater field {$field['name']} with " . count($value) . " rows");

                    $translated_repeater = array();
                    foreach ($value as $row_index => $row) {
                        $translated_row = array();
                        foreach ($row as $sub_field_name => $sub_value) {
                            $sub_field = $this->find_sub_field($field['sub_fields'], $sub_field_name);
                            if ($sub_field) {
                                $translated_value = $this->translate_acf_field_value($sub_value, $sub_field, $target_lang, $source_lang, $target_id);
                                $translated_row[$sub_field_name] = $translated_value;
                                error_log("APYT ACF: Repeater row {$row_index}, field {$sub_field_name}: value processed");
                            } else {
                                $translated_row[$sub_field_name] = $sub_value;
                            }
                        }
                        $translated_repeater[] = $translated_row;
                    }
                    return $translated_repeater;
                }
                break;

            case 'flexible_content':
                if (is_array($value) && !empty($value)) {
                    error_log("APYT ACF: Processing flexible content field {$field['name']} with " . count($value) . " layouts");

                    foreach ($value as $layout_index => $layout) {
                        if (isset($layout['acf_fc_layout'])) {
                            $layout_name = $layout['acf_fc_layout'];
                            foreach ($layout as $sub_field_name => $sub_value) {
                                if ($sub_field_name !== 'acf_fc_layout') {
                                    $layout_field = $this->find_flexible_field($field['layouts'], $layout_name, $sub_field_name);
                                    if ($layout_field) {
                                        $value[$layout_index][$sub_field_name] = $this->translate_acf_field_value($sub_value, $layout_field, $target_lang, $source_lang, $target_id);
                                        error_log("APYT ACF: Flexible content layout {$layout_name}, field {$sub_field_name}: value processed");
                                    }
                                }
                            }
                        }
                    }
                    return $value;
                }
                break;

            case 'group':
                if (is_array($value) && !empty($value)) {
                    error_log("APYT ACF: Processing group field {$field['name']}");

                    foreach ($value as $sub_field_name => $sub_value) {
                        $sub_field = $this->find_sub_field($field['sub_fields'], $sub_field_name);
                        if ($sub_field) {
                            $value[$sub_field_name] = $this->translate_acf_field_value($sub_value, $sub_field, $target_lang, $source_lang, $target_id);
                            error_log("APYT ACF: Group field {$sub_field_name}: value processed");
                        }
                    }
                    return $value;
                }
                break;

            case 'post_object':
            case 'relationship':
                if (is_array($value) && !empty($value)) {
                    // Множественный выбор
                    $translated_posts = array();
                    foreach ($value as $post_id) {
                        $translated_post_id = pll_get_post($post_id, $target_lang);
                        if ($translated_post_id && get_post_status($translated_post_id) === 'publish') {
                            $translated_posts[] = $translated_post_id;
                            error_log("APYT ACF: Post object field {$field['name']}: {$post_id} -> {$translated_post_id}");
                        } else {
                            // Если перевода нет, оставляем оригинал (только если пост существует)
                            if (get_post_status($post_id) === 'publish') {
                                $translated_posts[] = $post_id;
                                error_log("APYT ACF: Post object field {$field['name']}: {$post_id} (no translation, using original)");
                            }
                        }
                    }
                    return !empty($translated_posts) ? $translated_posts : $value;
                } elseif (is_numeric($value) && $value > 0) {
                    // Одиночный выбор
                    $translated_post_id = pll_get_post($value, $target_lang);
                    if ($translated_post_id && get_post_status($translated_post_id) === 'publish') {
                        error_log("APYT ACF: Post object field {$field['name']}: {$value} -> {$translated_post_id}");
                        return $translated_post_id;
                    } else {
                        // Если перевода нет, оставляем оригинал (только если пост существует)
                        if (get_post_status($value) === 'publish') {
                            error_log("APYT ACF: Post object field {$field['name']}: {$value} (no translation, using original)");
                            return $value;
                        }
                    }
                }
                break;

            case 'page_link':
                if (is_array($value) && !empty($value)) {
                    // Множественный выбор
                    $translated_links = array();
                    foreach ($value as $post_id) {
                        $translated_post_id = pll_get_post($post_id, $target_lang);
                        if ($translated_post_id && get_post_status($translated_post_id) === 'publish') {
                            $translated_links[] = get_permalink($translated_post_id);
                            error_log("APYT ACF: Page link field {$field['name']}: {$post_id} -> {$translated_post_id}");
                        } else {
                            // Если перевода нет, оставляем оригинал
                            if (get_post_status($post_id) === 'publish') {
                                $translated_links[] = get_permalink($post_id);
                                error_log("APYT ACF: Page link field {$field['name']}: {$post_id} (no translation, using original)");
                            }
                        }
                    }
                    return !empty($translated_links) ? $translated_links : $value;
                } elseif (is_numeric($value) && $value > 0) {
                    // Одиночный выбор
                    $translated_post_id = pll_get_post($value, $target_lang);
                    if ($translated_post_id && get_post_status($translated_post_id) === 'publish') {
                        $link = get_permalink($translated_post_id);
                        error_log("APYT ACF: Page link field {$field['name']}: {$value} -> {$translated_post_id} ({$link})");
                        return $link;
                    } else {
                        // Если перевода нет, оставляем оригинал
                        if (get_post_status($value) === 'publish') {
                            $link = get_permalink($value);
                            error_log("APYT ACF: Page link field {$field['name']}: {$value} (no translation, using original: {$link})");
                            return $link;
                        }
                    }
                }
                break;

            case 'taxonomy':
                if (is_array($value) && !empty($value)) {
                    // Множественный выбор
                    $translated_terms = array();
                    foreach ($value as $term_id) {
                        $translated_term_id = pll_get_term($term_id, $target_lang);
                        if ($translated_term_id && term_exists($translated_term_id)) {
                            $translated_terms[] = $translated_term_id;
                            error_log("APYT ACF: Taxonomy field {$field['name']}: {$term_id} -> {$translated_term_id}");
                        } else {
                            // Если перевода нет, оставляем оригинал
                            if (term_exists($term_id)) {
                                $translated_terms[] = $term_id;
                                error_log("APYT ACF: Taxonomy field {$field['name']}: {$term_id} (no translation, using original)");
                            }
                        }
                    }
                    return !empty($translated_terms) ? $translated_terms : $value;
                } elseif (is_numeric($value) && $value > 0) {
                    // Одиночный выбор
                    $translated_term_id = pll_get_term($value, $target_lang);
                    if ($translated_term_id && term_exists($translated_term_id)) {
                        error_log("APYT ACF: Taxonomy field {$field['name']}: {$value} -> {$translated_term_id}");
                        return $translated_term_id;
                    } else {
                        // Если перевода нет, оставляем оригинал
                        if (term_exists($value)) {
                            error_log("APYT ACF: Taxonomy field {$field['name']}: {$value} (no translation, using original)");
                            return $value;
                        }
                    }
                }
                break;

            case 'true_false':
            case 'checkbox':
            case 'radio':
            case 'select':
                // Эти поля не требуют перевода, просто копируем значение
                error_log("APYT ACF: Copied non-translatable field {$field['name']} of type {$field_type}");
                return $value;
                break;
        }

        error_log("APYT ACF: Field {$field['name']} returned original value (type: {$field_type})");
        return $value;
    }

    private function find_sub_field($sub_fields, $field_name) {
        if (empty($sub_fields)) {
            return null;
        }

        foreach ($sub_fields as $sub_field) {
            if ($sub_field['name'] === $field_name) {
                return $sub_field;
            }
        }

        // Если не нашли по name, попробуем по key
        foreach ($sub_fields as $sub_field) {
            if ($sub_field['key'] === $field_name) {
                return $sub_field;
            }
        }

        return null;
    }

    private function find_flexible_field($layouts, $layout_name, $field_name) {
        if (empty($layouts)) {
            return null;
        }

        foreach ($layouts as $layout) {
            if ($layout['name'] === $layout_name) {
                if (!empty($layout['sub_fields'])) {
                    foreach ($layout['sub_fields'] as $sub_field) {
                        if ($sub_field['name'] === $field_name) {
                            return $sub_field;
                        }
                    }

                    // Если не нашли по name, попробуем по key
                    foreach ($layout['sub_fields'] as $sub_field) {
                        if ($sub_field['key'] === $field_name) {
                            return $sub_field;
                        }
                    }
                }
            }
        }

        return null;
    }
}