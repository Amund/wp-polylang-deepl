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

    static function report_error(string $context, \Throwable $e, bool $notify_user)
    {
        error_log('[wp-polylang-deepl] ' . $context . ' : ' . get_class($e) . ' : ' . $e->getMessage());

        $user_id = get_current_user_id();
        if ($notify_user && $user_id) {
            set_transient('vp_polylang_deepl_error_' . $user_id, $e->getMessage(), 5 * MINUTE_IN_SECONDS);
        }
    }

    static function pop_user_error()
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return null;
        }
        $key = 'vp_polylang_deepl_error_' . $user_id;
        $message = get_transient($key);
        if ($message === false) {
            return null;
        }
        delete_transient($key);
        return 'La traduction automatique DeepL a échoué, le contenu n\'a pas été traduit. Erreur : ' . $message;
    }

    static function translate_blocks_collect(array &$blocks, array &$collector)
    {
        // put all references to translatable strings in the collector
        foreach ($blocks as &$block) {
            // recursive call for inner blocks, whatever the parent block is (core/group, core/columns...)
            if (!empty($block['innerBlocks'])) {
                self::translate_blocks_collect($block['innerBlocks'], $collector);
            }
            if (!isset($block['attrs']['data']) || !is_array($block['attrs']['data'])) {
                continue;
            }
            foreach ($block['attrs']['data'] as $key => $value) {
                if (
                    !str_starts_with($key, '_') && !empty($value) && is_string($value) && !is_numeric($value) &&
                    self::is_translatable_field($block['attrs']['data'], (string) $key)
                ) {
                    $collector[] = &$block['attrs']['data'][$key];
                }
            }
        }
    }

    // only translate ACF fields whose value is reader-facing text (not select values, urls, colors...)
    static function is_translatable_field(array $data, string $key): bool
    {
        // ACF stores the field key in "_<name>", or sometimes uses the field key as data key
        $field_key = $data['_' . $key] ?? (str_starts_with($key, 'field_') ? $key : null);
        $field = (is_string($field_key) && function_exists('acf_get_field')) ? acf_get_field($field_key) : false;

        if (empty($field['type'])) {
            return true; // unknown field type: keep previous behavior
        }
        $types = apply_filters('vp_polylang_deepl_translatable_field_types', ['text', 'textarea', 'wysiwyg']);
        return in_array($field['type'], (array) $types, true);
    }

    // DeepL API limits: 50 texts and 128 KiB per request
    const MAX_TEXTS_PER_REQUEST = 50;
    const MAX_BYTES_PER_REQUEST = 100 * 1024; // margin for request overhead

    // split texts into chunks respecting DeepL request limits
    static function chunk_texts(array $texts)
    {
        $chunks = [];
        $chunk = [];
        $bytes = 0;
        foreach ($texts as $text) {
            $size = strlen((string) $text);
            if (!empty($chunk) && (count($chunk) >= self::MAX_TEXTS_PER_REQUEST || $bytes + $size > self::MAX_BYTES_PER_REQUEST)) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }
            $chunk[] = (string) $text;
            $bytes += $size;
        }
        if (!empty($chunk)) {
            $chunks[] = $chunk;
        }
        return $chunks;
    }

    // translate a list of texts, returns translated strings in the same order
    static function translate_texts(array $texts, string $from_lang, string $to_lang)
    {
        $deeplClient = new \DeepL\DeepLClient($_ENV['DEEPL']);
        $translated = [];
        foreach (self::chunk_texts($texts) as $chunk) {
            foreach ($deeplClient->translateText($chunk, $from_lang, $to_lang) as $result) {
                $translated[] = $result->text;
            }
        }
        if (count($translated) !== count($texts)) {
            throw new \RuntimeException('DeepL returned ' . count($translated) . ' texts for ' . count($texts) . ' sent');
        }
        return $translated;
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
                // translate all strings, leave the post untouched on any failure
                try {
                    $translated = self::translate_texts($collector, $from_lang, $to_lang);

                    // replace all referenced strings with translated strings
                    foreach ($collector as $index => &$reference) {
                        $reference = $translated[$index];
                    }
                    unset($reference);
                } catch (\Throwable $e) {
                    self::report_error('post ' . $post_id, $e, true);
                    return;
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
            // translate all strings, leave the media untouched on any failure
            try {
                $translated = self::translate_texts($collector, $from_lang, $to_lang);

                // replace all referenced strings with translated strings
                foreach ($collector as $index => &$reference) {
                    $reference = $translated[$index];
                }
                unset($reference);
            } catch (\Throwable $e) {
                self::report_error('media ' . $to_id, $e, false);
                return;
            }

            // save translated post
            wp_update_post($to_post, false, false);

            if (!empty($alt)) {
                update_post_meta($to_id, '_wp_attachment_image_alt', $alt);
            }
        }
    }
}
