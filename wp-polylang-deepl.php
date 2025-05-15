<?php

/**
 * Plugin Name:       Polylang Deepl
 * Plugin URI:        https://github.com/Amund/wp-polylang-deepl
 * Description:       Traduction automatique des posts Gutenberg avec Polylang et l'api Deepl.
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Dimitri Avenel
 * License:           MIT
 */

if (!defined('ABSPATH')) {
    exit();
}

define('POLYLANG_DEEPL_PLUGIN_NAME', plugin_basename(__DIR__));
define('POLYLANG_DEEPL_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('POLYLANG_DEEPL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('POLYLANG_DEEPL_PLUGIN_BASENAME', plugin_basename(__FILE__));

add_action('init', function () {
    if (is_admin() && current_user_can('edit_posts')) {
        require_once 'class/Polylang_Deepl.php';
        require_once 'class/Polylang_Deepl_Admin.php';
        Polylang_Deepl_Admin::init();
    }
});
