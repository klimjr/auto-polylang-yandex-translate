<?php

class APYT_API {

    private $api_key;
    private $folder_id;

    public function __construct() {
        $this->api_key = get_option('apyt_yandex_api_key');
        $this->folder_id = get_option('apyt_translate_folder_id');

        add_action('wp_ajax_test_yandex_translate', array($this, 'test_yandex_translate'));
    }

    public function translate_text($text, $target_lang, $source_lang = null) {
        if (empty($text) || strlen(trim($text)) === 0) {
            return $text;
        }

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!$source_lang) {
            $source_lang = '';
        }

        $iam_token = $this->get_iam_token();
        if (is_wp_error($iam_token)) {
            error_log('Yandex IAM token error: ' . $iam_token->get_error_message());
            return new WP_Error('iam_error', $iam_token->get_error_message());
        }

        $url = 'https://translate.api.cloud.yandex.net/translate/v2/translate';

        $body = array(
            'targetLanguageCode' => $target_lang,
            'texts' => array($text)
        );

        if ($source_lang) {
            $body['sourceLanguageCode'] = $source_lang;
        }

        if ($this->folder_id) {
            $body['folderId'] = $this->folder_id;
        }

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $iam_token
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            error_log('Yandex Translate API error: ' . $response->get_error_message());
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body_response = wp_remote_retrieve_body($response);
        $data = json_decode($body_response, true);

        if ($response_code === 200 && isset($data['translations'][0]['text'])) {
            return $data['translations'][0]['text'];
        } else {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            error_log('Yandex Translate API error: ' . $error_message . ' | Response: ' . $body_response);
            return new WP_Error('api_error', $error_message);
        }
    }

    private function get_iam_token() {
        $stored_token = get_transient('apyt_iam_token');
        if ($stored_token) {
            return $stored_token;
        }

        $url = 'https://iam.api.cloud.yandex.net/iam/v1/tokens';

        $body = array(
            'yandexPassportOauthToken' => $this->api_key
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body_response = wp_remote_retrieve_body($response);
        $data = json_decode($body_response, true);

        if (isset($data['iamToken'])) {
            $token = $data['iamToken'];
            set_transient('apyt_iam_token', $token, 11 * HOUR_IN_SECONDS);
            return $token;
        } else {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            return new WP_Error('iam_error', 'Failed to get IAM token: ' . $error_message);
        }
    }

    public function test_yandex_translate() {
        if (!wp_verify_nonce($_POST['nonce'], 'test_yandex_translate')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (empty($this->api_key)) {
            wp_send_json_error('API ключ не настроен');
        }

        $test_text = "Hello, this is a test translation";
        $result = $this->translate_text($test_text, 'ru');

        if ($result && !is_wp_error($result) && $result !== $test_text) {
            wp_send_json_success('✅ Yandex API работает корректно. Перевод: "' . $result . '"');
        } else {
            $error_message = is_wp_error($result) ? $result->get_error_message() : 'Неизвестная ошибка';
            wp_send_json_error('❌ Ошибка: ' . $error_message);
        }
    }
}