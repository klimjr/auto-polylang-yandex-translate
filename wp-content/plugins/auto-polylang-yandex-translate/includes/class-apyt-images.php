<?php

class APYT_Images {

    public function copy_attached_images($source_post_id, $target_post_id, $target_lang, $source_lang) {
        // Копируем featured image
        $thumbnail_id = get_post_thumbnail_id($source_post_id);
        if ($thumbnail_id) {
            $new_thumbnail_id = $this->duplicate_attachment($thumbnail_id, $target_post_id, $target_lang);
            if ($new_thumbnail_id) {
                set_post_thumbnail($target_post_id, $new_thumbnail_id);
            }
        }

        // Копируем вложения галереи
        $attachments = get_attached_media('image', $source_post_id);
        foreach ($attachments as $attachment) {
            $this->duplicate_attachment($attachment->ID, $target_post_id, $target_lang);
        }
    }

    public function duplicate_attachment($source_attachment_id, $target_parent_id, $target_lang) {
        $translated_attachment_id = $this->get_translated_attachment_id($source_attachment_id, $target_lang);

        if ($translated_attachment_id) {
            return $translated_attachment_id;
        }

        $source_attachment = get_post($source_attachment_id);
        if (!$source_attachment) {
            return false;
        }

        $file = get_attached_file($source_attachment_id);
        if (!file_exists($file)) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $new_filename = wp_unique_filename($upload_dir['path'], basename($file));
        $new_file = $upload_dir['path'] . '/' . $new_filename;

        if (!copy($file, $new_file)) {
            return false;
        }

        $file_type = wp_check_filetype($new_filename, null);

        $attachment_data = array(
            'post_mime_type' => $file_type['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', $new_filename),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $new_attachment_id = wp_insert_attachment($attachment_data, $new_file, $target_parent_id);

        if (is_wp_error($new_attachment_id)) {
            return false;
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($new_attachment_id, $new_file);
        wp_update_attachment_metadata($new_attachment_id, $attach_data);

        pll_set_post_language($new_attachment_id, $target_lang);

        $attachment_translations = pll_get_post_translations($source_attachment_id);
        $attachment_translations[$target_lang] = $new_attachment_id;
        pll_save_post_translations($attachment_translations);

        return $new_attachment_id;
    }

    private function get_translated_attachment_id($attachment_id, $target_lang) {
        return pll_get_post($attachment_id, $target_lang);
    }
}