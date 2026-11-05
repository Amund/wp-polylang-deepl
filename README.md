# Polylang Deepl

Traduction automatique des posts et des médias avec [DeepL](https://www.deepl.com/), au moment où [Polylang](https://polylang.pro/) crée une traduction.

## Prérequis

- PHP 8.1 ou plus, WordPress 6.1 ou plus
- Polylang ou Polylang Pro
- Une clé d'API DeepL (gratuite ou payante)
- Des contenus en blocs ACF : le texte des blocs core n'est pas traduit (voir [Limites](#limites))

## Installation

Avec Composer, par exemple sur un site [Bedrock](https://roots.io/bedrock/) :

```bash
composer require amund/wp-polylang-deepl
```

La bibliothèque `deeplcom/deepl-php` est installée en dépendance.

Ensuite, définir la clé d'API DeepL, au choix :

- dans le `.env` (Bedrock) ou en variable d'environnement : `DEEPL="ma-cle-api"`
- en constante, dans `wp-config.php` ou `config/application.php` : `define('DEEPL', 'ma-cle-api');`

La page **Langues > Deepl auto-translate** indique ce qui manque, ou le nombre de caractères DeepL utilisés sur le quota.

## Fonctionnement

### Posts

Quand on ajoute une traduction depuis l'interface Polylang (le « + » dans la liste des posts ou dans l'éditeur), le plugin :

1. reprend le titre et les blocs du post source ;
2. traduit le titre et les champs texte des blocs ACF, y compris dans les blocs imbriqués (`core/group`, `core/columns`…) et les répéteurs ;
3. enregistre la traduction en **brouillon**, à relire avant publication.

Seuls les champs ACF de type `text`, `textarea` et `wysiwyg` sont traduits. Les valeurs de select, les URL, les couleurs… restent identiques à la source. Un champ dont le type est introuvable (champ supprimé, ACF non chargé) est traduit.

La traduction n'a lieu que si l'utilisateur a le droit de lire le post source.

### Médias

Quand Polylang crée la traduction d'un média, le titre, la description, la légende et le texte alternatif sont traduits.

### Erreurs

Une erreur DeepL (quota atteint, clé invalide, réseau, langue non supportée…) ne bloque jamais WordPress : le contenu reste non traduit, comme si le plugin était désactivé.

L'erreur est écrite dans le log PHP, préfixée par `[wp-polylang-deepl]`. Pour les posts, elle est aussi affichée une fois dans l'éditeur.

### Langues

La langue source et la langue cible sont déduites des locales Polylang :

| Locale WordPress | Code DeepL cible |
|---|---|
| `en_US` | `en-US` |
| autres `en_*` (`en_GB`, `en_AU`, `en_CA`…) | `en-GB` |
| `pt_BR` | `pt-BR` |
| autres `pt_*` (`pt_PT`, `pt_PT_ao90`, `pt_AO`…) | `pt-PT` |
| `zh_TW`, `zh_HK`, `zh_MO` | `zh-Hant` |
| autres `zh_*` | `zh-Hans` |
| toutes les autres (`fr_FR`, `de_DE`, `es_MX`…) | code de base (`fr`, `de`, `es`…) |

En langue source, DeepL n'accepte que le code de base (`en`, `pt`, `zh`…).

## Filtres

### `vp_polylang_deepl_translatable_field_types`

Types de champs ACF traduits. Par défaut : `['text', 'textarea', 'wysiwyg']`.

```php
add_filter('vp_polylang_deepl_translatable_field_types', function (array $types) {
    $types[] = 'my_custom_text_field';
    return $types;
});
```

### `vp_polylang_deepl_lang`

Code de langue envoyé à DeepL, pour une locale donnée.

```php
// traduire en anglais américain pour le Canada
add_filter('vp_polylang_deepl_lang', function (string $code, string $locale, bool $target) {
    if ($target && $locale === 'en_CA') {
        return 'en-US';
    }
    return $code;
}, 10, 3);
```

## Limites

- Le texte des blocs core (paragraphes, titres…) et le contenu classique (hors blocs) ne sont pas traduits.
- Le HTML des champs `wysiwyg` est envoyé à DeepL comme du texte (sans `tag_handling`). Les balises sont préservées en pratique, sans garantie.
- Les shortcodes dans les champs texte peuvent être traduits par DeepL.
- Chaque traduction consomme du quota DeepL, y compris si le brouillon créé est ensuite supprimé.

## Licence

MIT
