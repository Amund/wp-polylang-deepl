<?php

class Polylang_Deepl_Admin
{
    static function init()
    {
        $data = get_plugin_data(POLYLANG_DEEPL_PLUGIN_PATH . POLYLANG_DEEPL_PLUGIN_NAME . '.php');
        define('POLYLANG_DEEPL_PLUGIN_TITLE', $data['Name']);
        define('POLYLANG_DEEPL_PLUGIN_DESCRIPTION', $data['Description']);

        if (Polylang_Deepl::is_active()) {
            add_action('admin_menu', [static::class, 'admin_menu'], 9999);
            add_filter("plugin_action_links_" . POLYLANG_DEEPL_PLUGIN_BASENAME, [static::class, 'plugin_action_links']);
            add_action('current_screen', [static::class, 'current_screen']);
            add_action('wp_ajax_polylang_deepl_translate_post', [static::class, 'ajax_translate_post']);
        }
    }

    static function plugin_action_links($links)
    {
        $settings_link = '<a href="admin.php?page=wp-polylang-deepl">' . __('Settings') . '</a>';
        array_push($links, $settings_link);
        return $links;
    }

    static function admin_menu($wp_admin_bar)
    {
        add_submenu_page('mlang', POLYLANG_DEEPL_PLUGIN_TITLE, POLYLANG_DEEPL_PLUGIN_TITLE, 'manage_options', 'wp-polylang-deepl', [static::class, 'admin_settings'], 9999);
    }

    static function admin_settings()
    {
        if (!Polylang_Deepl::is_active()) {
            if (!Polylang_Deepl::check_deepl_library()) {
                wp_admin_notice('Deepl n\'est pas chargé. Veuillez l\'ajouter avec <code>make composer require deeplcom/deepl-php</code>', ['type' => 'error']);
            }
            if (!Polylang_Deepl::check_polylang()) {
                wp_admin_notice('L\'extension Polylang n\'est pas active.', ['type' => 'error']);
            }
            if (!Polylang_Deepl::check_deepl_api()) {
                wp_admin_notice('La clé d\'API Deepl n\'est pas définie. Veuillez ajouter la variable d\'environnement <code>DEEPL="ma-cle-api"</code>)', ['type' => 'error']);
            }
        } else {
            $deeplClient = new \DeepL\DeepLClient($_ENV['DEEPL']);
            $usage = $deeplClient->getUsage();
            if (!$usage->character) {
                wp_admin_notice('Le statut du compte Deepl est indisponible.', ['type' => 'warning']);
            } else {
                $count = (int) $usage->character->count ?? 0;
                $limit = (int) $usage->character->limit ?? 0;
                $percent = $limit > 0 ? $count / $limit * 100 : 0;
                $count = number_format($count, 0, ',', ' ');
                $limit = number_format($limit, 0, ',', ' ');
                $percent = number_format($percent, 2, ',', ' ');
                $status = $count >= $limit ? 'warning' : 'success';
                wp_admin_notice('Quota mensuel Deepl : ' . $count . ' / ' . $limit . ' (' . $percent . '%)', ['type' => $status]);
            }
        }

        echo strtr('<div class="wrap"><h1>{title}</h1><p>{description}</p></div>', [
            '{title}' => POLYLANG_DEEPL_PLUGIN_TITLE,
            '{description}' => POLYLANG_DEEPL_PLUGIN_DESCRIPTION,
        ]);
    }

    static function current_screen($screen)
    {
        if (!$screen) {
            return;
        }

        // post in block editor
        if ($screen->is_block_editor && !wp_doing_ajax() && !wp_doing_cron()) {
            $current_post = $_REQUEST['post'] ?? null;
            if (Polylang_Deepl::can_translate_post($current_post)) {
                // prepare js
                $file = 'js/block-editor.js';
                wp_register_script(
                    'wp-polylang-deepl',
                    POLYLANG_DEEPL_PLUGIN_URL . $file,
                    ['wp-editor', 'wp-i18n', 'wp-element', 'wp-compose', 'wp-components'],
                    filemtime(POLYLANG_DEEPL_PLUGIN_PATH . $file)
                );
                wp_localize_script(
                    'wp-polylang-deepl',
                    'polylang_deepl',
                    [
                        'post_id' => $current_post,
                        '_ajax_nonce' => wp_create_nonce('polylang_deepl_translate_post'),
                    ]
                );

                // enqueue js
                add_action('enqueue_block_editor_assets', function () {
                    wp_enqueue_script('wp-polylang-deepl');
                });
            }
        }
    }

    static function ajax_translate_post()
    {
        if (!check_ajax_referer('polylang_deepl_translate_post')) {
            wp_send_json_error('Invalid nonce');
        }

        $post_id = (int) $_REQUEST['post_id'] ?? 0;

        try {
            Polylang_Deepl::translate_post($post_id);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }

        wp_send_json_success();
    }
}
