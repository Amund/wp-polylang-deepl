<?php

class VP_Polylang_Deepl_Admin
{
    static function init()
    {
        add_action('admin_menu', [static::class, 'admin_menu'], 9999);

        if (VP_Polylang_Deepl::is_active()) {
            add_action('pll_save_post', [VP_Polylang_Deepl::class, 'pll_save_post'], 1, 3);
            add_action('pll_translate_media', [VP_Polylang_Deepl::class, 'pll_translate_media'], 100, 3);
        }
    }

    static function admin_menu($wp_admin_bar)
    {
        add_submenu_page('mlang', 'VP Polylang Deepl', 'Deepl auto-translate', 'manage_options', 'vp-polylang-deepl', [static::class, 'render'], 9999);
    }

    static function render()
    {
        $check_polylang = '';
        $check_deepl_library = '';
        $check_deepl_api = '';
        $status_deepl = '';
        if (!VP_Polylang_Deepl::is_active()) {
            if (!VP_Polylang_Deepl::check_deepl_library()) {
                $check_deepl_library = [
                    'tag' => 'p',
                    'class' => 'notice notice-warning',
                    'content' => 'Deepl n\'est pas chargé. Veuillez l\'ajouter avec <code>make composer require deeplcom/deepl-php</code>'
                ];
            }
            if (!VP_Polylang_Deepl::check_polylang()) {
                $check_polylang = [
                    'tag' => 'p',
                    'class' => 'notice notice-warning',
                    'content' => 'L\'extension Polylang n\'est pas active.',
                ];
            }
            if (!VP_Polylang_Deepl::check_deepl_api()) {
                $check_deepl_api = [
                    'tag' => 'p',
                    'class' => 'notice notice-warning',
                    'content' => 'La clé d\'API Deepl n\'est pas définie. Veuillez ajouter la variable d\'environnement <code>DEEPL="ma-cle-api"</code>)',
                ];
            }
        } else {
            $deeplClient = new \DeepL\DeepLClient($_ENV['DEEPL']);
            $usage = $deeplClient->getUsage();
            if ($usage->character) {
                $count = number_format($usage->character->count, 0, ',', ' ');
                $limit = number_format($usage->character->limit, 0, ',', ' ');
                if ($usage->character->count >= $usage->character->limit) {
                    $class = 'notice notice-warning';
                } else {
                    $class = 'notice notice-success';
                    $status_deepl = [
                        'tag' => 'p',
                        'class' => $class,
                        'content' => 'Caractères utilisés : ' . $count . ' / ' . $limit,
                    ];
                }
            } else {
                $status_deepl = [
                    'tag' => 'p',
                    'class' => 'notice notice-warning',
                    'content' => 'Impossible de récupérer les informations d\'utilisation de Deepl.',
                ];
            }
        }

        echo vp::render([
            'tag' => 'div',
            'class' => 'wrap',
            'content' => [
                ['tag' => 'h1', 'content' => 'Vupar: Deepl auto-translate'],
                ['tag' => 'p', 'content' => 'Effectue une traduction automatique des posts et médias en utilisant Deepl lors de l\'ajout d\'une traduction.'],
                $check_deepl_library,
                $check_polylang,
                $check_deepl_api,
                $status_deepl,
            ],
        ]);
    }
}
