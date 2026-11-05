<?php

class VP_Polylang_Deepl_Admin
{
    static function init()
    {
        add_action('admin_menu', [static::class, 'admin_menu'], 9999);

        if (VP_Polylang_Deepl::is_active()) {
            add_action('pll_save_post', [VP_Polylang_Deepl::class, 'pll_save_post'], 1, 3);
            add_action('pll_translate_media', [VP_Polylang_Deepl::class, 'pll_translate_media'], 100, 3);
            add_action('admin_notices', [static::class, 'admin_notices']);
            add_action('enqueue_block_editor_assets', [static::class, 'block_editor_notices']);
        }
    }

    // classic screens: standard admin notice
    static function admin_notices()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
            return; // handled by block_editor_notices()
        }
        $message = VP_Polylang_Deepl::pop_user_error();
        if ($message) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    // block editor: classic admin notices are hidden, use the editor notices store
    static function block_editor_notices()
    {
        $message = VP_Polylang_Deepl::pop_user_error();
        if ($message) {
            wp_add_inline_script(
                'wp-edit-post', // depends on wp-data, wp-notices and wp-dom-ready
                'wp.domReady(function(){wp.data.dispatch("core/notices").createNotice("error",' . wp_json_encode($message) . ',{isDismissible:true});});',
                'after'
            );
        }
    }

    static function admin_menu()
    {
        add_submenu_page('mlang', 'VP Polylang Deepl', 'Deepl auto-translate', 'manage_options', 'vp-polylang-deepl', [static::class, 'render'], 9999);
    }

    static function render()
    {
        // list of [type, html message], type is a WordPress notice type (warning, error, success)
        $notices = [];

        if (!VP_Polylang_Deepl::is_active()) {
            if (!VP_Polylang_Deepl::check_deepl_library()) {
                $notices[] = ['warning', 'Deepl n\'est pas chargé. Veuillez l\'ajouter avec <code>make composer require deeplcom/deepl-php</code>'];
            }
            if (!VP_Polylang_Deepl::check_polylang()) {
                $notices[] = ['warning', 'L\'extension Polylang n\'est pas active.'];
            }
            if (!VP_Polylang_Deepl::check_deepl_api()) {
                $notices[] = ['warning', 'La clé d\'API Deepl n\'est pas définie. Veuillez ajouter la variable d\'environnement <code>DEEPL="ma-cle-api"</code> ou la constante <code>define(\'DEEPL\', \'ma-cle-api\')</code>.'];
            }
        } else {
            try {
                $deeplClient = new \DeepL\DeepLClient(VP_Polylang_Deepl::api_key());
                $usage = $deeplClient->getUsage();
                if ($usage->character) {
                    $count = number_format($usage->character->count, 0, ',', ' ');
                    $limit = number_format($usage->character->limit, 0, ',', ' ');
                    if ($usage->character->count >= $usage->character->limit) {
                        $notices[] = ['warning', 'Quota DeepL atteint : ' . $count . ' / ' . $limit . ' caractères. Les nouvelles traductions automatiques échoueront.'];
                    } else {
                        $notices[] = ['success', 'Caractères utilisés : ' . $count . ' / ' . $limit];
                    }
                } else {
                    $notices[] = ['warning', 'Impossible de récupérer les informations d\'utilisation de Deepl.'];
                }
            } catch (\Throwable $e) {
                VP_Polylang_Deepl::report_error('usage', $e, false);
                $notices[] = ['error', 'Impossible de contacter DeepL : ' . esc_html($e->getMessage())];
            }
        }

        echo '<div class="wrap">';
        echo '<h1>Vupar: Deepl auto-translate</h1>';
        echo '<p>Effectue une traduction automatique des posts et médias en utilisant Deepl lors de l\'ajout d\'une traduction.</p>';
        foreach ($notices as [$type, $message]) {
            echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . $message . '</p></div>';
        }
        echo '</div>';
    }
}
