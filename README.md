![GitHub Tag](https://img.shields.io/github/v/tag/Amund/wp-polylang-deepl)
![Static Badge](https://img.shields.io/badge/wordpress-plugin-blue)

# wp-polylang-deepl

**wp-polylang-deepl** is a WordPress plugin that automatically translates posts and medias with [DeepL](https://www.deepl.com/) when a translation is created with [Polylang](https://polylang.pro/).

## Features

- Translates the title and the text fields of ACF blocks when a post translation is added from the Polylang interface, including nested blocks and repeaters.
- Saves the translated post as a draft, to be reviewed before publication.
- Translates the title, description, caption and alternative text of medias when Polylang creates their translation.
- Never breaks WordPress on a DeepL error: the content is left untranslated and the error is reported.
- Settings page showing the missing requirements, or the DeepL characters used on the quota.

## Requirements

- PHP 8.1 or later
- WordPress 6.1 or later
- Polylang or Polylang Pro
- A DeepL API key (free or pro)
- The [DeepL PHP library](https://github.com/DeepLcom/deepl-php) (`deeplcom/deepl-php`), installed as a dependency with Composer
- Content made of ACF blocks: the text of core blocks is not translated (see [Known limitations](#known-limitations))

## Installation

### With Composer

1. Add the repository to your `composer.json` file:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Amund/wp-polylang-deepl.git"
        }
    ]
}
```

2. Install the plugin:

```sh
composer require amund/wp-polylang-deepl
```

The package type is `wordpress-plugin`: with [Bedrock](https://roots.io/bedrock/), it is installed in `web/app/plugins/`. Activate it from the "Plugins" menu of the WordPress admin.

### Manually

Copy the plugin folder into `wp-content/plugins/` and activate it from the "Plugins" menu of the WordPress admin. The DeepL PHP library must be loaded by another way (e.g. the Composer autoloader of the site).

## Configuration

Define the DeepL API key, as one of:

- an entry of the `.env` file (Bedrock) or an environment variable: `DEEPL="my-api-key"`
- a constant, in `wp-config.php` or `config/application.php`: `define('DEEPL', 'my-api-key');`

The key is looked up in this order: `$_ENV['DEEPL']`, `getenv('DEEPL')`, then the `DEEPL` constant.

The **Languages > Deepl auto-translate** page shows what is missing, or the number of DeepL characters used on the quota.

## How it works

### Posts

When a translation is added from the Polylang interface (the "+" in the posts list or in the editor), the plugin:

1. copies the title and the blocks of the source post;
2. translates the title and the text fields of ACF blocks, including blocks nested in core blocks (`core/group`, `core/columns`...) and repeater sub fields;
3. saves the translation as a **draft**.

Only ACF fields of type `text`, `textarea` and `wysiwyg` are translated, so select values, urls, colors... are kept as is. A field whose type cannot be resolved (deleted field, ACF not loaded...) is translated.

The translation only runs if the current user can read the source post.

### Medias

When Polylang creates the translation of a media, its title, description, caption and alternative text are translated.

### Errors

A DeepL error (quota reached, invalid key, network, unsupported language...) never breaks WordPress: the content is left untranslated, as if the plugin was not active.

The error is written to the PHP error log, prefixed with `[wp-polylang-deepl]`. For posts, it is also displayed once in the editor.

### Languages

The source and target languages come from the Polylang locales:

| WordPress locale                                 | DeepL target code                   |
| ------------------------------------------------ | ----------------------------------- |
| `en_US`                                          | `en-US`                             |
| other `en_*` (`en_GB`, `en_AU`, `en_CA`...)      | `en-GB`                             |
| `pt_BR`                                          | `pt-BR`                             |
| other `pt_*` (`pt_PT`, `pt_PT_ao90`, `pt_AO`...) | `pt-PT`                             |
| `zh_TW`, `zh_HK`, `zh_MO`                        | `zh-Hant`                           |
| other `zh_*`                                     | `zh-Hans`                           |
| any other (`fr_FR`, `de_DE`, `es_MX`...)         | base code (`fr`, `de`, `es`...)     |

As a source language, DeepL only accepts the base code (`en`, `pt`, `zh`...).

## Filters

### `vp_polylang_deepl_translatable_field_types`

ACF field types to translate. Default: `['text', 'textarea', 'wysiwyg']`.

```php
add_filter('vp_polylang_deepl_translatable_field_types', function (array $types) {
    $types[] = 'my_custom_text_field';
    return $types;
});
```

### `vp_polylang_deepl_lang`

DeepL language code sent for a locale.

```php
// translate to American English for Canada
add_filter('vp_polylang_deepl_lang', function (string $code, string $locale, bool $target) {
    if ($target && $locale === 'en_CA') {
        return 'en-US';
    }
    return $code;
}, 10, 3);
```

## Known limitations

- **Core blocks**: the text of core blocks (paragraphs, headings...) and classic content (outside blocks) is not translated.
- **HTML**: the HTML of `wysiwyg` fields is sent to DeepL as text (without `tag_handling`). Tags are preserved in practice, without guarantee.
- **Shortcodes**: shortcodes in text fields may be translated by DeepL.
- **Quota**: each translation uses DeepL characters, even if the created draft is deleted afterwards.
