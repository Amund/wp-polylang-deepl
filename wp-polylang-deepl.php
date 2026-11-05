<?php

/**
 * Plugin Name:       Polylang Deepl
 * Plugin URI:        https://github.com/Amund/wp-polylang-deepl
 * Description:       Automatic translation of posts using Polylang and Deepl.
 * Version:           1.0.0
 * Requires at least: 6.1
 * Requires PHP:      8.1
 * Author:            Dimitri Avenel
 * License:           MIT
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/class/VP_Polylang_Deepl.php';

// admin
if (is_admin()) {
    require_once __DIR__ . '/class/VP_Polylang_Deepl_Admin.php';
    // wait for all plugins to be loaded, so Polylang detection does not depend on plugins load order
    add_action('plugins_loaded', [VP_Polylang_Deepl_Admin::class, 'init']);
    $plugin = plugin_basename(__FILE__);
    add_filter("plugin_action_links_$plugin", function ($links) {
        $settings_link = '<a href="admin.php?page=vp-polylang-deepl">' . __('Settings') . '</a>';
        array_push($links, $settings_link);
        return $links;
    });
}
