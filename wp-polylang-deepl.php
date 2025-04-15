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

require_once 'class/VP_Polylang_Deepl.php';
VP_Polylang_Deepl::init();

// admin
if (is_admin()) {
    require_once 'class/VP_Polylang_Deepl_Admin.php';
    VP_Polylang_Deepl_Admin::init();
    $plugin = plugin_basename(__FILE__);
    add_filter("plugin_action_links_$plugin", function ($links) {
        $settings_link = '<a href="admin.php?page=vp-polylang-deepl">' . __('Settings') . '</a>';
        array_push($links, $settings_link);
        return $links;
    });
}
