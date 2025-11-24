<?php

class APYT_Core {

    private static $instance = null;
    public $api;
    public $posts;
    public $terms;
    public $acf;
    public $images;
    public $bulk;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->setup_hooks();
    }

    private function load_dependencies() {
        require_once plugin_dir_path(__FILE__) . 'class-apyt-api.php';
        require_once plugin_dir_path(__FILE__) . 'class-apyt-posts.php';
        require_once plugin_dir_path(__FILE__) . 'class-apyt-terms.php';
        require_once plugin_dir_path(__FILE__) . 'class-apyt-acf.php';
        require_once plugin_dir_path(__FILE__) . 'class-apyt-images.php';
        require_once plugin_dir_path(__FILE__) . 'class-apyt-bulk-actions.php';
    }

    private function init_components() {
        $this->api = new APYT_API();
        $this->posts = new APYT_Posts();
        $this->terms = new APYT_Terms();
        $this->acf = new APYT_ACF();
        $this->images = new APYT_Images();
        $this->bulk = new APYT_Bulk_Actions();
    }

    private function setup_hooks() {
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));

        // Локализация
        load_plugin_textdomain('auto-polylang-yandex', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function init() {
        // Регистрируем настройки
        register_setting('apyt_settings', 'apyt_yandex_api_key');
        register_setting('apyt_settings', 'apyt_auto_translate');
        register_setting('apyt_settings', 'apyt_update_translations');
        register_setting('apyt_settings', 'apyt_target_languages');
        register_setting('apyt_settings', 'apyt_translate_folder_id');
        register_setting('apyt_settings', 'apyt_post_types');
        register_setting('apyt_settings', 'apyt_taxonomies');
        register_setting('apyt_settings', 'apyt_translate_acf');
        register_setting('apyt_settings', 'apyt_translate_images');
    }

    public function admin_init() {
        add_settings_section(
                'apyt_main_section',
                'Настройки автоматического перевода Yandex',
                array($this, 'settings_section_callback'),
                'apyt_settings'
        );

        $this->add_settings_fields();
    }

    private function add_settings_fields() {
        $fields = array(
                'apyt_yandex_api_key' => array(
                        'label' => 'Yandex Translate API Key',
                        'callback' => 'yandex_api_key_callback'
                ),
                'apyt_translate_folder_id' => array(
                        'label' => 'Folder ID (для IAM)',
                        'callback' => 'folder_id_callback'
                ),
                'apyt_auto_translate' => array(
                        'label' => 'Автоматический перевод',
                        'callback' => 'auto_translate_callback'
                ),
                'apyt_update_translations' => array(
                        'label' => 'Обновление переводов',
                        'callback' => 'update_translations_callback'
                ),
                'apyt_target_languages' => array(
                        'label' => 'Целевые языки',
                        'callback' => 'target_languages_callback'
                ),
                'apyt_post_types' => array(
                        'label' => 'Типы записей для перевода',
                        'callback' => 'post_types_callback'
                ),
                'apyt_taxonomies' => array(
                        'label' => 'Таксономии для перевода',
                        'callback' => 'taxonomies_callback'
                ),
                'apyt_translate_acf' => array(
                        'label' => 'Перевод ACF полей',
                        'callback' => 'translate_acf_callback'
                ),
                'apyt_translate_images' => array(
                        'label' => 'Копирование изображений',
                        'callback' => 'translate_images_callback'
                )
        );

        foreach ($fields as $field => $data) {
            add_settings_field(
                    $field,
                    $data['label'],
                    array($this, $data['callback']),
                    'apyt_settings',
                    'apyt_main_section'
            );
        }
    }

    public function settings_section_callback() {
        echo '<p>Настройте автоматический перевод контента с помощью Yandex Translate API</p>';
    }

    public function add_admin_menu() {
        add_options_page(
                'Auto Polylang Yandex Translate',
                'Yandex Auto Translate',
                'manage_options',
                'auto-polylang-yandex-translate',
                array($this, 'admin_page')
        );
    }

    public function admin_scripts($hook) {
        if (in_array($hook, array('post.php', 'post-new.php', 'edit-tags.php', 'settings_page_auto-polylang-yandex-translate', 'edit.php'))) {
            // ИСПРАВЛЕННЫЙ ПУТЬ К JS ФАЙЛУ
            wp_enqueue_script('apyt-admin', plugin_dir_url(__FILE__) . '../admin.js', array('jquery'), '2.1.1', true);

            wp_localize_script('apyt-admin', 'apyt_ajax', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('apyt_manual_translate'),
                    'translating' => __('Перевод...', 'auto-polylang-yandex'),
                    'success' => __('Успешно переведено', 'auto-polylang-yandex'),
                    'error' => __('Ошибка перевода', 'auto-polylang-yandex')
            ));
        }
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас недостаточно прав для доступа к этой странице.');
        }
        ?>
        <div class="wrap">
            <h1>Auto Polylang Yandex Translate Settings</h1>

            <div style="background: #fff; padding: 20px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #0073aa;">
                <h3>Статус системы</h3>
                <p><strong>Polylang:</strong> <?php echo function_exists('pll_languages_list') ? '✅ Активен' : '❌ Не активен'; ?></p>
                <p><strong>API ключ:</strong> <?php echo !empty(get_option('apyt_yandex_api_key')) ? '✅ Установлен' : '❌ Не установлен'; ?></p>
                <p><strong>Автоперевод:</strong> <?php echo get_option('apyt_auto_translate') === 'yes' ? '✅ Включен' : '❌ Выключен'; ?></p>
                <p><strong>Обновление переводов:</strong> <?php echo get_option('apyt_update_translations') === 'yes' ? '✅ Включено' : '❌ Выключено'; ?></p>
                <p><strong>ACF:</strong> <?php echo function_exists('get_field_objects') ? '✅ Активен' : '❌ Не активен'; ?></p>

                <h3>Массовый перевод</h3>
                <div id="bulk-translate-section">
                    <p>Перевести все записи без переводов на выбранные языки:</p>
                    <button id="bulk-translate-posts" class="button button-primary">Перевести записи (10 шт)</button>
                    <button id="bulk-translate-terms" class="button button-primary">Перевести термины</button>
                    <div id="bulk-result" style="margin-top: 10px;"></div>
                </div>

                <h3>Тестирование подключения</h3>
                <button id="test-translate" class="button button-secondary">Протестировать Yandex Translate API</button>
                <div id="test-result" style="margin-top: 10px; padding: 10px; border-radius: 4px;"></div>
            </div>

            <form action="options.php" method="post">
                <?php
                settings_fields('apyt_settings');
                do_settings_sections('apyt_settings');
                submit_button('Сохранить настройки');
                ?>
            </form>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#test-translate').on('click', function() {
                    var button = $(this);
                    button.prop('disabled', true).text('Тестирование...');

                    $.post(ajaxurl, {
                        action: 'test_yandex_translate',
                        nonce: '<?php echo wp_create_nonce("test_yandex_translate"); ?>'
                    }, function(response) {
                        button.prop('disabled', false).text('Протестировать Yandex Translate API');
                        if (response.success) {
                            $('#test-result').html('<div style="color: green; background: #f0fff0; padding: 10px; border: 1px solid green;">' + response.data + '</div>');
                        } else {
                            $('#test-result').html('<div style="color: red; background: #fff0f0; padding: 10px; border: 1px solid red;">' + response.data + '</div>');
                        }
                    }).fail(function() {
                        button.prop('disabled', false).text('Протестировать Yandex Translate API');
                        $('#test-result').html('<div style="color: red; background: #fff0f0; padding: 10px; border: 1px solid red;">Ошибка сети при тестировании</div>');
                    });
                });

                // Массовый перевод записей
                $('#bulk-translate-posts').on('click', function() {
                    var button = $(this);
                    button.prop('disabled', true).text('Начинаем перевод...');
                    $('#bulk-result').html('<div class="notice notice-info">Подготовка к переводу...</div>');

                    $.post(ajaxurl, {
                        action: 'bulk_translate_posts',
                        nonce: '<?php echo wp_create_nonce("apyt_bulk_translate"); ?>'
                    }, function(response) {
                        if (response.success) {
                            $('#bulk-result').html('<div class="notice notice-success">' + response.data.message + '</div>');
                        } else {
                            $('#bulk-result').html('<div class="notice notice-error">' + response.data + '</div>');
                        }
                        button.prop('disabled', false).text('Перевести записи (10 шт)');
                    });
                });

                // Массовый перевод терминов
                $('#bulk-translate-terms').on('click', function() {
                    var button = $(this);
                    button.prop('disabled', true).text('Начинаем перевод...');
                    $('#bulk-result').html('<div class="notice notice-info">Подготовка к переводу...</div>');

                    $.post(ajaxurl, {
                        action: 'bulk_translate_terms',
                        nonce: '<?php echo wp_create_nonce("apyt_bulk_translate"); ?>'
                    }, function(response) {
                        if (response.success) {
                            $('#bulk-result').html('<div class="notice notice-success">' + response.data.message + '</div>');
                        } else {
                            $('#bulk-result').html('<div class="notice notice-error">' + response.data + '</div>');
                        }
                        button.prop('disabled', false).text('Перевести термины');
                    });
                });
            });
        </script>
        <?php
    }

    // Callback функции для настроек
    public function yandex_api_key_callback() {
        $api_key = get_option('apyt_yandex_api_key');
        echo '<input type="password" name="apyt_yandex_api_key" value="' . esc_attr($api_key) . '" class="regular-text" />';
        echo '<p class="description">Получите OAuth-токен в <a href="https://yandex.ru/dev/disk/api/doc/ru/concepts/access-token" target="_blank">Yandex OAuth</a></p>';
    }

    public function folder_id_callback() {
        $folder_id = get_option('apyt_translate_folder_id');
        echo '<input type="text" name="apyt_translate_folder_id" value="' . esc_attr($folder_id) . '" class="regular-text" />';
        echo '<p class="description">Folder ID из Yandex Cloud Console (рекомендуется)</p>';
    }

    public function auto_translate_callback() {
        $auto_translate = get_option('apyt_auto_translate', 'no');
        echo '<label><input type="checkbox" name="apyt_auto_translate" value="yes" ' . checked($auto_translate, 'yes', false) . ' /> Включить автоматический перевод после сохранения</label>';
        echo '<p class="description">Переводы создаются только для настроенных языков Polylang</p>';
    }

    public function update_translations_callback() {
        $update_translations = get_option('apyt_update_translations', 'no');
        echo '<label><input type="checkbox" name="apyt_update_translations" value="yes" ' . checked($update_translations, 'yes', false) . ' /> Обновлять существующие переводы при изменении оригинала</label>';
        echo '<p class="description">При сохранении оригинала будут обновляться все связанные переводы</p>';
    }

    public function target_languages_callback() {
        if (!function_exists('pll_languages_list')) {
            echo '<p style="color: #d63638;">Polylang не активирован или не настроены языки</p>';
            return;
        }

        $languages = pll_languages_list(array('fields' => ''));
        $selected_languages = get_option('apyt_target_languages', array());

        echo '<p>Выберите языки, на которые нужно переводить контент:</p>';
        foreach ($languages as $language) {
            $lang_code = $language->slug;
            $lang_name = $language->name;
            $checked = in_array($lang_code, $selected_languages) ? 'checked' : '';
            echo '<label style="display: block; margin-bottom: 5px;">';
            echo '<input type="checkbox" name="apyt_target_languages[]" value="' . esc_attr($lang_code) . '" ' . $checked . ' /> ';
            echo esc_html($lang_name) . ' (' . esc_html($lang_code) . ')';
            echo '</label>';
        }
        echo '<p class="description">Переводы будут создаваться только для выбранных языков</p>';
    }

    public function post_types_callback() {
        $post_types = get_post_types(array('public' => true), 'objects');
        $selected_types = get_option('apyt_post_types', array('post', 'page'));

        foreach ($post_types as $post_type) {
            if ($post_type->name === 'attachment') continue;

            $checked = in_array($post_type->name, $selected_types) ? 'checked' : '';
            echo '<label style="display: block; margin-bottom: 5px;">';
            echo '<input type="checkbox" name="apyt_post_types[]" value="' . esc_attr($post_type->name) . '" ' . $checked . ' /> ';
            echo esc_html($post_type->label);
            echo '</label>';
        }
    }

    public function taxonomies_callback() {
        $taxonomies = get_taxonomies(array('public' => true), 'objects');
        $selected_taxonomies = get_option('apyt_taxonomies', array('category', 'post_tag'));

        foreach ($taxonomies as $taxonomy) {
            $checked = in_array($taxonomy->name, $selected_taxonomies) ? 'checked' : '';
            echo '<label style="display: block; margin-bottom: 5px;">';
            echo '<input type="checkbox" name="apyt_taxonomies[]" value="' . esc_attr($taxonomy->name) . '" ' . $checked . ' /> ';
            echo esc_html($taxonomy->label);
            echo '</label>';
        }
        echo '<p class="description">Включая пользовательские таксономии</p>';
    }

    public function translate_acf_callback() {
        $translate_acf = get_option('apyt_translate_acf', 'yes');
        echo '<label><input type="checkbox" name="apyt_translate_acf" value="yes" ' . checked($translate_acf, 'yes', false) . ' /> Переводить ACF поля</label>';
        echo '<p class="description">Автоматически переводить текстовые поля ACF и копировать изображения в галереях</p>';
    }

    public function translate_images_callback() {
        $translate_images = get_option('apyt_translate_images', 'yes');
        echo '<label><input type="checkbox" name="apyt_translate_images" value="yes" ' . checked($translate_images, 'yes', false) . ' /> Копировать изображения</label>';
        echo '<p class="description">Создавать копии изображений для переводов и связывать их с Polylang</p>';
    }
}