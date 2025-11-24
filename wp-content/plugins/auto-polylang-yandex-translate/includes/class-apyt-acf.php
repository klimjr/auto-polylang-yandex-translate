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

            if ($value) {
                $translated_value = $this->translate_acf_field_value($value, $field, $target_lang, $source_lang, $target_post_id);
                update_field($field_name, $translated_value, $target_post_id);
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

            if ($value) {
                $translated_value = $this->translate_acf_field_value($value, $field, $target_lang, $source_lang, $target_term_id);
                update_field($field_name, $translated_value, $taxonomy . '_' . $target_term_id);
            }
        }
    }

    public function translate_acf_field_value($value, $field, $target_lang, $source_lang, $target_id = null) {
        $core = APYT_Core::get_instance();
        $field_type = $field['type'];

        switch ($field_type) {
            case 'text':
            case 'textarea':
            case 'wysiwyg':
            case 'email':
            case 'url':
                if (is_string($value) && !empty(trim($value))) {
                    return $core->api->translate_text($value, $target_lang, $source_lang);
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
                    return $translated_gallery;
                }
                break;

            case 'image':
                if (is_numeric($value) && $value > 0) {
                    $translated_image_id = $core->images->duplicate_attachment($value, $target_id, $target_lang);
                    return $translated_image_id ? $translated_image_id : $value;
                }
                break;

            case 'file':
                if (is_numeric($value) && $value > 0) {
                    $translated_file_id = $core->images->duplicate_attachment($value, $target_id, $target_lang);
                    return $translated_file_id ? $translated_file_id : $value;
                }
                break;

            case 'repeater':
                if (is_array($value)) {
                    foreach ($value as $key => $row) {
                        foreach ($row as $sub_field_name => $sub_value) {
                            $sub_field = isset($field['sub_fields']) ? $this->find_sub_field($field['sub_fields'], $sub_field_name) : null;
                            if ($sub_field) {
                                $value[$key][$sub_field_name] = $this->translate_acf_field_value($sub_value, $sub_field, $target_lang, $source_lang, $target_id);
                            }
                        }
                    }
                }
                break;

            case 'flexible_content':
                if (is_array($value)) {
                    foreach ($value as $key => $layout) {
                        if (isset($layout['acf_fc_layout'])) {
                            foreach ($layout as $sub_field_name => $sub_value) {
                                if ($sub_field_name !== 'acf_fc_layout') {
                                    $layout_field = $this->find_flexible_field($field['layouts'], $layout['acf_fc_layout'], $sub_field_name);
                                    if ($layout_field) {
                                        $value[$key][$sub_field_name] = $this->translate_acf_field_value($sub_value, $layout_field, $target_lang, $source_lang, $target_id);
                                    }
                                }
                            }
                        }
                    }
                }
                break;

            case 'group':
                if (is_array($value)) {
                    foreach ($value as $sub_field_name => $sub_value) {
                        $sub_field = isset($field['sub_fields']) ? $this->find_sub_field($field['sub_fields'], $sub_field_name) : null;
                        if ($sub_field) {
                            $value[$sub_field_name] = $this->translate_acf_field_value($sub_value, $sub_field, $target_lang, $source_lang, $target_id);
                        }
                    }
                }
                break;
        }

        return $value;
    }

    private function find_sub_field($sub_fields, $field_name) {
        foreach ($sub_fields as $sub_field) {
            if ($sub_field['name'] === $field_name) {
                return $sub_field;
            }
        }
        return null;
    }

    private function find_flexible_field($layouts, $layout_name, $field_name) {
        foreach ($layouts as $layout) {
            if ($layout['name'] === $layout_name) {
                foreach ($layout['sub_fields'] as $sub_field) {
                    if ($sub_field['name'] === $field_name) {
                        return $sub_field;
                    }
                }
            }
        }
        return null;
    }
}