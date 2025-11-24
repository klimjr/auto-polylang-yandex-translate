<?php
/**
 * Plugin Name: Auto Polylang Yandex Translate
 * Description: Автоматическое создание и обновление переводов через Yandex Translate API для Polylang Pro
 * Version: 2.1.0
 * Author: Page-Proofs
 * Text Domain: auto-polylang-yandex
 */

if (!defined('ABSPATH')) {
    exit;
}

// Проверяем требуемые плагины
register_activation_hook(__FILE__, 'apyt_activation_check');
function apyt_activation_check() {
    if (!function_exists('pll_languages_list')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Для работы плагина Auto Polylang Yandex Translate требуется активированный Polylang Pro.');
    }
}

// Загружаем классы плагина
spl_autoload_register('apyt_autoloader');
function apyt_autoloader($class_name) {
    if (false !== strpos($class_name, 'APYT_')) {
        $classes_dir = plugin_dir_path(__FILE__) . 'includes/';
        $class_file = 'class-' . str_replace('_', '-', strtolower($class_name)) . '.php';
        require_once $classes_dir . $class_file;
    }
}

// Инициализация плагина
function apyt_init() {
    if (!function_exists('pll_languages_list')) {
        add_action('admin_notices', 'apyt_polylang_missing_notice');
        return;
    }

    new APYT_Core();
}
add_action('plugins_loaded', 'apyt_init');

function apyt_polylang_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><strong>Auto Polylang Yandex Translate:</strong> Требуется активированный Polylang Pro для работы плагина.</p>
    </div>
    <?php
}

// Добавляем ссылку на настройки в списке плагинов
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'apyt_settings_link');
function apyt_settings_link($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=auto-polylang-yandex-translate') . '">Настройки</a>';
    array_unshift($links, $settings_link);
    return $links;
}