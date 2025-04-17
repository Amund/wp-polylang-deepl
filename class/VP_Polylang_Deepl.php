<?php

class VP_Polylang_Deepl
{
    static function init()
    {
        if (self::is_active()) {
            // add_action('admin_menu', [static::class, 'admin_menu'], 9999);
        }
    }

    static function is_active()
    {
        return self::check_polylang() && self::check_deepl_library() && self::check_deepl_api();
    }

    static function check_polylang()
    {
        return class_exists('Polylang');
    }

    static function check_deepl_library()
    {
        return class_exists('\DeepL\DeepLClient');
    }

    static function check_deepl_api()
    {
        return isset($_ENV['DEEPL']) && !empty($_ENV['DEEPL']);
    }

    static function translate_blocks_collect(array &$blocks, array &$collector)
    {
        // put all references to translatable strings in the collector
        foreach ($blocks as &$block) {
            if (!isset($block['attrs']['data']) || !is_array($block['attrs']['data'])) {
                continue;
            }
            foreach ($block['attrs']['data'] as $key => $value) {
                if (!str_starts_with($key, '_') && !empty($value) && is_string($value) && !is_numeric($value)) {
                    $collector[] = &$block['attrs']['data'][$key];
                }
            }
            // recursive call for inner blocks
            if (!empty($block['innerBlocks'])) {
                self::translate_blocks_collect($block['innerBlocks'], $collector);
            }
        }
    }

    static function pll_save_post($post_id, $post, $translations)
    {
        if (empty($_REQUEST['from_post']) || empty($_REQUEST['new_lang'])) {
            return;
        }

        if (
            isset($translations[$_REQUEST['new_lang']]) &&
            $translations[$_REQUEST['new_lang']] == $post_id &&
            !empty($_ENV['DEEPL']) &&
            class_exists('\DeepL\DeepLClient') &&
            !defined('TRANSLATED')
        ) {
            // get source
            $from_lang = pll_get_post_language($_REQUEST['from_post'], 'slug');
            $from_post = get_post($_REQUEST['from_post']);
            $from_content = $from_post->post_content;

            // normalize language code to match deepl api requirements
            $to_lang = pll_get_post_language($post->ID, 'locale');
            if (in_array($to_lang, ['en_GB', 'en_US', 'pt_BR', 'pt_PT'])) {
                $to_lang = str_replace('_', '-', $to_lang);
            } else {
                $to_lang = substr($to_lang, 0, 2);
            }

            // collect all references of strings to translate
            $collector = [];

            if (!empty($from_post->post_title)) {
                $post->post_title = (string) $from_post->post_title;
                $collector[] = &$post->post_title;
            }

            $blocks = null;
            if (has_blocks($from_content)) {
                $blocks = parse_blocks($from_content);
                self::translate_blocks_collect($blocks, $collector);
            }

            if (!empty($collector)) {
                // translate all strings
                $deeplClient = new \DeepL\DeepLClient($_ENV['DEEPL']);
                $translated = $deeplClient->translateText($collector, $from_lang, $to_lang);

                // replace all referenced strings with translated strings
                foreach ($collector as $index => &$reference) {
                    $reference = $translated[$index]->text;
                }

                // update post content with translated blocks
                if ($blocks !== null) {
                    $post->post_content = serialize_blocks($blocks);
                }

                // prevent translating loop
                define('TRANSLATED', 1);
                $post->post_status = 'draft';

                // save translated post
                wp_update_post($post, false, false);
            }
        }
    }

    static function pll_translate_media($from_id, $to_id, $lang)
    {
        $from_lang = pll_get_post_language($from_id, 'slug');

        // normalize language code to match deepl api requirements
        $to_lang = pll_get_post_language($to_id, 'locale');
        if (in_array($to_lang, ['en_GB', 'en_US', 'pt_BR', 'pt_PT'])) {
            $to_lang = str_replace('_', '-', $to_lang);
        } else {
            $to_lang = substr($to_lang, 0, 2);
        }
        $to_post = get_post($to_id);

        // collect all references of strings to translate
        $collector = [];

        if (!empty($to_post->post_title)) { // title
            $collector[] = &$to_post->post_title;
        }
        if (!empty($to_post->post_content)) { // description
            $collector[] = &$to_post->post_content;
        }
        if (!empty($to_post->post_excerpt)) { // legend
            $collector[] = &$to_post->post_excerpt;
        }
        $alt = null;
        if (!empty($to_post->_wp_attachment_image_alt)) { // alt
            $alt = (string) $to_post->_wp_attachment_image_alt;
            $collector[] = &$alt;
        }

        if (!empty($collector)) {
            // translate all strings
            $deeplClient = new \DeepL\DeepLClient($_ENV['DEEPL']);
            $translated = $deeplClient->translateText($collector, $from_lang, $to_lang);

            // replace all referenced strings with translated strings
            foreach ($collector as $index => &$reference) {
                $reference = $translated[$index]->text;
            }

            // save translated post
            wp_update_post($to_post, false, false);

            if (!empty($alt)) {
                update_post_meta($to_id, '_wp_attachment_image_alt', $alt);
            }
        }
    }
}
