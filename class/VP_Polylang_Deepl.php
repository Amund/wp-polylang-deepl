<?php

/**
 * Automatic translation of posts and medias with DeepL, when Polylang creates a translation.
 *
 * Posts: when a translation is added from the Polylang interface (post-new.php?from_post=…&new_lang=…),
 * the title and the text fields of the source post's ACF blocks are translated, and the new post is
 * saved as a draft. Medias: title, description, caption and alternative text are translated when
 * Polylang creates the media translation.
 *
 * DeepL errors never break WordPress: the content is left untranslated, the error is logged and,
 * for posts, displayed once to the user in the editor.
 */
class VP_Polylang_Deepl
{
    /**
     * True once a translated post has been saved in this request, prevents translation loops
     * (wp_update_post() triggers pll_save_post again).
     */
    private static bool $translating = false;

    /**
     * Whether automatic translation can run: Polylang active, DeepL library loaded and API key set.
     */
    static function is_active(): bool
    {
        return self::check_polylang() && self::check_deepl_library() && self::check_deepl_api();
    }

    /**
     * Whether Polylang (or Polylang Pro) is active.
     */
    static function check_polylang(): bool
    {
        return class_exists('Polylang');
    }

    /**
     * Whether the DeepL PHP library (deeplcom/deepl-php) is loaded.
     */
    static function check_deepl_library(): bool
    {
        return class_exists('\DeepL\DeepLClient');
    }

    /**
     * Whether a DeepL API key is defined (see api_key()).
     */
    static function check_deepl_api(): bool
    {
        return self::api_key() !== '';
    }

    /**
     * DeepL API key.
     *
     * Looked up in this order: `$_ENV['DEEPL']` (Bedrock dotenv), the `DEEPL` environment variable
     * (getenv), then the `DEEPL` constant.
     *
     * @return string API key, or an empty string if not defined.
     */
    static function api_key(): string
    {
        if (!empty($_ENV['DEEPL'])) {
            return (string) $_ENV['DEEPL'];
        }
        $env = getenv('DEEPL');
        if (!empty($env)) {
            return (string) $env;
        }
        if (defined('DEEPL') && !empty(constant('DEEPL'))) {
            return (string) constant('DEEPL');
        }
        return '';
    }

    /**
     * Logs a translation error, and optionally keeps it to be displayed once to the current user.
     *
     * The message is kept 5 minutes in a transient, read by pop_user_error().
     *
     * @param string $context Short description of what was translated, for the log (e.g. "post 42").
     * @param \Throwable $e Error thrown during the translation.
     * @param bool $notify_user Whether to display the error to the current user.
     */
    static function report_error(string $context, \Throwable $e, bool $notify_user): void
    {
        error_log('[wp-polylang-deepl] ' . $context . ' : ' . get_class($e) . ' : ' . $e->getMessage());

        $user_id = get_current_user_id();
        if ($notify_user && $user_id) {
            set_transient('vp_polylang_deepl_error_' . $user_id, $e->getMessage(), 5 * MINUTE_IN_SECONDS);
        }
    }

    /**
     * Gets and deletes the translation error kept for the current user by report_error().
     *
     * @return string|null Message to display (not escaped), or null if there is no error.
     */
    static function pop_user_error(): ?string
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

    /**
     * Collects references to the translatable strings of ACF blocks, recursively.
     *
     * Inner blocks are always visited, whatever the parent block is (core/group, core/columns…).
     * A value is collected if it is a non empty, non numeric string, its key does not start with `_`
     * (ACF field key references), and its field type is translatable (see is_translatable_field()).
     * Core blocks content (innerHTML) is not translated.
     *
     * @param array $blocks Blocks, as returned by parse_blocks(). Modified through the references.
     * @param array $collector Receives references to the strings: assigning a value to an entry
     *                         changes the matching block attribute.
     */
    static function translate_blocks_collect(array &$blocks, array &$collector): void
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

    /**
     * Whether an ACF block value holds reader-facing text, based on its ACF field type.
     *
     * The field key is read from `_<name>` (also for repeater sub fields, e.g. `_items_0_title`),
     * or from the data key itself when it is a field key (`field_…`). Only `text`, `textarea` and
     * `wysiwyg` fields are translated, so select values, urls, colors… are kept as is.
     * When the field type can't be resolved (deleted field, ACF not loaded…), the value is translated.
     *
     * Filterable with `vp_polylang_deepl_translatable_field_types` (array of ACF field types).
     *
     * @param array $data ACF block data (`attrs.data`).
     * @param string $key Data key of the value.
     * @return bool
     */
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

    /**
     * Converts a WordPress locale to a DeepL language code.
     *
     * Source language: base code only (`en_US` → `en`), as required by DeepL.
     * Target language: `en_US` → `en-US`, other `en_*` → `en-GB`; `pt_BR` → `pt-BR`, other `pt_*`
     * (including `pt_PT_ao90`) → `pt-PT`; `zh_TW`, `zh_HK`, `zh_MO` → `zh-Hant`, other `zh_*` →
     * `zh-Hans`; any other locale → base code (`fr_FR` → `fr`, `fil` → `fil`).
     *
     * Filterable with `vp_polylang_deepl_lang` ($code, $locale, $target).
     *
     * @param string|false $locale WordPress locale, as returned by pll_get_post_language($id, 'locale').
     * @param bool $target True for the target language, false for the source language.
     * @return string DeepL language code.
     * @throws \RuntimeException If the post has no language.
     */
    static function deepl_lang(string|false $locale, bool $target): string
    {
        if (!$locale) {
            throw new \RuntimeException('No Polylang language for this post');
        }
        $parts = explode('_', $locale);
        $lang = strtolower($parts[0]); // also handles 3-letter codes
        $region = strtoupper($parts[1] ?? '');

        $code = $lang; // source language: DeepL only accepts the base code
        if ($target) {
            $code = match ($lang) {
                'en' => $region === 'US' ? 'en-US' : 'en-GB',
                'pt' => $region === 'BR' ? 'pt-BR' : 'pt-PT',
                'zh' => in_array($region, ['TW', 'HK', 'MO'], true) ? 'zh-Hant' : 'zh-Hans',
                default => $lang,
            };
        }
        return (string) apply_filters('vp_polylang_deepl_lang', $code, $locale, $target);
    }

    // DeepL API limits: 50 texts and 128 KiB per request
    const MAX_TEXTS_PER_REQUEST = 50;
    const MAX_BYTES_PER_REQUEST = 100 * 1024; // margin for request overhead

    /**
     * Splits texts into chunks respecting DeepL request limits.
     *
     * A chunk holds at most MAX_TEXTS_PER_REQUEST texts and MAX_BYTES_PER_REQUEST bytes, except a
     * single text bigger than the bytes limit, which gets its own chunk.
     *
     * @param array $texts Texts to translate.
     * @return array<string[]> Chunks of texts, in the original order.
     */
    static function chunk_texts(array $texts): array
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

    /**
     * Translates a list of texts with DeepL, in as many requests as needed (see chunk_texts()).
     *
     * @param array $texts Texts to translate.
     * @param string $from_lang DeepL source language code (see deepl_lang()).
     * @param string $to_lang DeepL target language code (see deepl_lang()).
     * @return string[] Translated texts, in the same order as $texts.
     * @throws \Throwable On any DeepL error, or if DeepL does not return one text per text sent.
     */
    static function translate_texts(array $texts, string $from_lang, string $to_lang): array
    {
        $deeplClient = new \DeepL\DeepLClient(VP_Polylang_Deepl::api_key());
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

    /**
     * Translates a new post translation created from the Polylang interface (pll_save_post action).
     *
     * Runs only for the post being created by a "add translation" link (`from_post` and `new_lang`
     * request parameters), when the source post exists and the current user can read it.
     * Translates the source title and ACF blocks, then saves the new post as a draft.
     * On a DeepL error, the post is left as Polylang created it and the error is reported.
     *
     * @param int $post_id Id of the saved post.
     * @param \WP_Post $post Saved post.
     * @param int[] $translations Post ids of the translations, keyed by language slug.
     */
    static function pll_save_post($post_id, $post, $translations): void
    {
        if (empty($_REQUEST['from_post']) || empty($_REQUEST['new_lang'])) {
            return;
        }

        if (
            isset($translations[$_REQUEST['new_lang']]) &&
            $translations[$_REQUEST['new_lang']] == $post_id &&
            self::check_deepl_api() &&
            class_exists('\DeepL\DeepLClient') &&
            !self::$translating
        ) {
            // get source, only if it exists and the current user is allowed to read it
            $from_id = (int) $_REQUEST['from_post'];
            $from_post = get_post($from_id);
            if (!$from_post || !current_user_can('read_post', $from_id)) {
                return;
            }
            $from_content = $from_post->post_content;

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
                    $from_lang = self::deepl_lang(pll_get_post_language($from_post->ID, 'locale'), false);
                    $to_lang = self::deepl_lang(pll_get_post_language($post->ID, 'locale'), true);
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
                self::$translating = true;
                $post->post_status = 'draft';

                // save translated post
                wp_update_post($post, false, false);
            }
        }
    }

    /**
     * Translates a new media translation created by Polylang (pll_translate_media action).
     *
     * Translates the title, description, caption and alternative text of the new media.
     * On a DeepL error, the media is left untranslated and the error is logged only (medias are often
     * translated during an ajax upload, where a notice can't be displayed).
     *
     * @param int $from_id Id of the source media.
     * @param int $to_id Id of the new media translation.
     * @param string $lang Language slug of the new media translation.
     */
    static function pll_translate_media($from_id, $to_id, $lang): void
    {
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
                $from_lang = self::deepl_lang(pll_get_post_language($from_id, 'locale'), false);
                $to_lang = self::deepl_lang(pll_get_post_language($to_id, 'locale'), true);
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
