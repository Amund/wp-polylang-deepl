<?php

class Polylang_Deepl
{
    static function is_active(): bool
    {
        return self::check_polylang() && self::check_deepl_library() && self::check_deepl_api();
    }

    static function check_polylang(): bool
    {
        return class_exists('Polylang');
    }

    static function check_deepl_library(): bool
    {
        return class_exists('\DeepL\DeepLClient');
    }

    static function check_deepl_api(): bool
    {
        return isset($_ENV['DEEPL']) && !empty($_ENV['DEEPL']);
    }

    static function can_translate_post($post_id): bool
    {
        // current post
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        // default language
        $default_lang = pll_default_language('slug');
        if (!$default_lang) {
            return false;
        }

        // current language
        $lang = pll_get_post_language($post->ID, 'slug');
        if (!$lang) {
            return false;
        }

        // only translate if not default language
        if ($lang === $default_lang) {
            return false;
        }

        // get default language post
        $default_post_id = pll_get_post($post->ID, $default_lang);
        $default_post = get_post($default_post_id);
        if (!$default_post) {
            return false;
        }

        return true;
    }

    static function normalize_to_deepl_lang_code(string $lang_code): string
    {
        if (in_array($lang_code, ['en_GB', 'en_US', 'pt_BR', 'pt_PT'])) {
            $lang_code = str_replace('_', '-', $lang_code);
        } else {
            $lang_code = substr($lang_code, 0, 2);
        }
        return $lang_code;
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

    static function translate_post(int $post_id): void
    {
        if (empty($post_id)) {
            throw new Exception('post cible inconnu');
        }

        $post = get_post($post_id);
        if (!$post) {
            throw new Exception('post cible non trouvé');
        }

        $from_lang = pll_default_language('slug');
        if (!$from_lang) {
            throw new Exception('langue source non trouvée');
        }

        $to_lang = pll_get_post_language($post->ID, 'locale');
        if (!$to_lang) {
            throw new Exception('langue cible non trouvée');
        }
        $to_lang = self::normalize_to_deepl_lang_code($to_lang);

        if ($from_lang === $to_lang) {
            throw new Exception('langues source et cible identiques');
        }

        $from_post_id = pll_get_post($post->ID, $from_lang);
        $from_post = get_post($from_post_id);
        if (!$from_post) {
            throw new Exception('post source non trouvé');
        }

        // collect all references of strings to translate
        $collector = [];

        // title
        if (!empty($from_post->post_title)) {
            $post->post_title = (string) $from_post->post_title;
            $collector[] = &$post->post_title;
        }

        // excerpt
        if (!empty($from_post->post_excerpt)) {
            $post->post_excerpt = (string) $from_post->post_excerpt;
            $collector[] = &$post->post_excerpt;
        }

        // content
        $blocks = null;
        $from_content = $from_post->post_content;
        if (has_blocks($from_content)) {
            $blocks = parse_blocks($from_content);
            self::translate_blocks_collect($blocks, $collector);
        }

        if (!empty($collector)) {
            // translate all strings
            try {
                $deeplClient = new \DeepL\DeepLClient($_ENV['DEEPL']);
                $translated = $deeplClient->translateText($collector, $from_lang, $to_lang);
            } catch (\DeepL\DeepLException $e) {
                throw new Exception('DeepL api: ' . $e->getMessage());
            }

            // replace all referenced strings with translated strings
            foreach ($collector as $index => &$reference) {
                $reference = $translated[$index]->text;
            }

            // update post content with translated blocks
            if ($blocks !== null) {
                $post->post_content = serialize_blocks($blocks);
            }

            // update post slug
            $post->post_name = sanitize_title($post->post_title);

            // save translated post
            wp_update_post($post, false, false);
        }
    }
}
