# Lizenzliste tannwald-portal

Stand 01.11.2026, gemessen mit `ddev composer licenses --no-dev --format=json` (Laufzeit-Abhängigkeiten, ohne
Entwicklungswerkzeuge) und `npm ls --all --long --json` im Theme-Ordner `web/themes/custom/tannwald` (installierte
Pakete; optionale Plattform-Binärpakete anderer Betriebssysteme sind nicht installiert und darum nicht gelistet).
Das Repo selbst steht unter GPL-2.0-or-later (`LICENSE.txt`), wie Drupal. Alle gelisteten Lizenzen sind mit
GPL-2.0-or-later verträglich (MIT, BSD, ISC, Apache-2.0, MPL-2.0 und GPL-2.0-or-later selbst).

- Schrift **Inter** (SIL Open Font License 1.1): liegt selbst gehostet im Theme (`web/themes/custom/tannwald/fonts/`, Lizenztext `fonts/LICENSE-Inter.txt`, gebaut nach `dist/fonts/`); kein Request an einen Font-Dienst (seit 1.1, Phase 2).
Demo-Dokumente, Bulletins und Stammdaten unter `fixtures/` sind fiktiv und selbst verfasst.
Die 20 Bilder unter `fixtures/bilder/` (WebP, je ≤ 400 KB) hat der Betreiber am 02.11.2026 mit einem Bildgenerator
erzeugt; sie zeigen fiktive Maschinen ohne Marken, Texte und Personen. Herkunft und Einbau: `fixtures/bilder/manifest.json`, ADR-009.

Logo-Bildmarke: Betreiber, erzeugt mit Nano Banana am 2026-11-03, als SVG nachgebaut (`components/logo`, `images/favicon.svg`;
Referenz `fixtures/logo/mark-blue-512.png`).

## Composer (PHP), 125 Pakete

| Lizenz | Pakete |
|---|---|
| MIT | 87 |
| GPL-2.0-or-later | 23 |
| BSD-3-Clause | 9 |
| BSD-2-Clause | 3 |
| BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only | 2 |
| GPL-2.0+ | 1 |

### MIT (87)

`asm89/stack-cors` v2.4.0 · `composer/installers` v2.3.0 · `composer/semver` 3.4.4 · `consolidation/annotated-command` 4.10.5 · `consolidation/config` 3.2.1 · `consolidation/filter-via-dot-access-data` 2.0.3 · `consolidation/log` 3.1.2 · `consolidation/output-formatters` 4.7.1 · `consolidation/robo` 5.1.1 · `consolidation/site-alias` 4.1.3 · `consolidation/site-process` 6.1.0 · `dflydev/dot-access-data` v3.0.3 · `doctrine/lexer` 3.0.3 · `egulias/email-validator` 4.0.4 · `grasmash/expander` 3.0.1 · `grasmash/yaml-cli` 3.2.1 · `guzzlehttp/guzzle` 7.16.1 · `guzzlehttp/promises` 2.6.0 · `guzzlehttp/psr7` 2.13.2 · `justinrainbow/json-schema` 6.8.2 · `laravel/prompts` v0.3.26 · `league/container` 4.2.5 · `league/html-to-markdown` 5.2.3 · `masterminds/html5` 2.10.1 · `openai-php/client` v0.22.0 · `phootwork/collection` v3.2.3 · `phootwork/lang` v3.2.3 · `php-http/discovery` 1.20.0 · `php-http/guzzle7-adapter` 1.1.0 · `php-http/httplug` 2.4.1 · `php-http/multipart-stream-builder` 1.4.2 · `php-http/promise` 1.3.1 · `php-tuf/composer-stager` v2.1.1 · `phpowermove/docblock` v4.0 · `psr/cache` 3.0.0 · `psr/container` 2.0.2 · `psr/event-dispatcher` 1.0.0 · `psr/http-client` 1.0.3 · `psr/http-factory` 1.1.0 · `psr/http-message` 2.0 · `psr/log` 3.0.2 · `psy/psysh` v0.12.24 · `ralouphie/getallheaders` 3.0.3 · `revolt/event-loop` v1.0.9 · `symfony/cache-contracts` v3.7.1 · `symfony/cache` v8.0.15 · `symfony/console` v7.4.22 · `symfony/css-selector` v7.4.22 · `symfony/dependency-injection` v7.4.21 · `symfony/deprecation-contracts` v3.7.1 · `symfony/error-handler` v7.4.22 · `symfony/event-dispatcher-contracts` v3.7.1 · `symfony/event-dispatcher` v7.4.17 · `symfony/expression-language` v7.4.21 · `symfony/filesystem` v7.4.21 · `symfony/finder` v7.4.22 · `symfony/http-foundation` v7.4.21 · `symfony/http-kernel` v7.4.21 · `symfony/intl` v7.4.22 · `symfony/mailer` v7.4.19 · `symfony/mime` v7.4.22 · `symfony/polyfill-ctype` v1.37.0 · `symfony/polyfill-iconv` v1.43.0 · `symfony/polyfill-intl-grapheme` v1.43.3 · `symfony/polyfill-intl-idn` v1.43.0 · `symfony/polyfill-intl-normalizer` v1.43.0 · `symfony/polyfill-mbstring` v1.43.3 · `symfony/polyfill-php80` v1.43.0 · `symfony/polyfill-php81` v1.38.1 · `symfony/polyfill-php83` v1.43.0 · `symfony/polyfill-php84` v1.43.0 · `symfony/polyfill-php85` v1.43.0 · `symfony/polyfill-php86` v1.43.0 · `symfony/process` v7.4.22 · `symfony/psr-http-message-bridge` v7.4.21 · `symfony/routing` v7.4.21 · `symfony/runtime` v7.4.21 · `symfony/serializer` v7.4.21 · `symfony/service-contracts` v3.7.3 · `symfony/string` v7.4.22 · `symfony/translation-contracts` v3.7.1 · `symfony/validator` v7.4.21 · `symfony/var-dumper` v7.4.22 · `symfony/var-exporter` v7.4.18 · `symfony/yaml` v7.4.22 · `twig/html-extra` v3.29.0 · `yethee/tiktoken` 0.5.1

### GPL-2.0-or-later (23)

`chi-teck/drupal-code-generator` 4.2.0 · `drupal/ai_content_suggestions` 1.5.2 · `drupal/ai_logging` 1.3.3 · `drupal/ai_provider_ollama` 1.2.0-rc3 · `drupal/ai_translate` 1.4.4 · `drupal/ai_validations` 1.4.0 · `drupal/ai` 1.5.2 · `drupal/config_split` 2.0.2 · `drupal/core-composer-scaffold` 11.4.11 · `drupal/core-project-message` 11.4.11 · `drupal/core-recipe-unpack` 11.4.11 · `drupal/core-recommended` 11.4.11 · `drupal/core` 11.4.11 · `drupal/field_validation` 3.0.0 · `drupal/field_widget_actions` 1.5.0 · `drupal/key` 1.22.0 · `drupal/mailer_transport` 2.0.2 · `drupal/masquerade` 2.2.0 · `drupal/migrate_plus` 6.0.10 · `drupal/migrate_tools` 6.1.4 · `drupal/symfony_mailer` 2.0.2 · `drush/drush` 13.8.0 · `html2text/html2text` 4.3.2

### BSD-3-Clause (9)

`league/commonmark` 2.10.7 · `league/config` v1.2.0 · `marc-mabe/php-enum` v4.7.2 · `mck89/peast` v1.17.7 · `nikic/php-parser` v5.9.0 · `pear/pear-core-minimal` v1.10.18 · `sebastian/diff` 6.0.2 · `tijsverkoyen/css-to-inline-styles` v2.4.0 · `twig/twig` v3.30.0

### BSD-2-Clause (3)

`pear/archive_tar` 1.6.1 · `pear/console_getopt` v1.4.3 · `pear/pear_exception` v1.0.2

### BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only (2)

`nette/schema` v1.4.0 · `nette/utils` v4.1.6

### GPL-2.0+ (1)

`drupal/queue_ui` 3.2.3

## npm (Theme-Build), 31 installierte Pakete

Laufzeit im Browser: nur `alpinejs`; Tailwind, Vite und ihre Abhängigkeiten sind Build-Werkzeuge, das gebaute `dist/` ist eingecheckt.

| Lizenz | Pakete |
|---|---|
| MIT | 17 |
| ISC | 1 |
| MPL-2.0 | 1 |
| BSD-3-Clause | 1 |

### MIT (17)

`@jridgewell/gen-mapping` 0.3.13 · `@jridgewell/remapping` 2.3.5 · `@jridgewell/sourcemap-codec` 1.6.0 · `@jridgewell/trace-mapping` 0.3.31 · `@tailwindcss/node` 4.3.3 · `@tailwindcss/oxide-darwin-arm64` 4.3.3 · `@tailwindcss/oxide` 4.3.3 · `@tailwindcss/vite` 4.3.3 · `@vue/reactivity` 3.5.44 · `@vue/shared` 3.5.44 · `alpinejs` 3.17.5 · `enhanced-resolve` 5.26.0 · `jiti` 2.7.0 · `magic-string` 0.30.21 · `tailwindcss` 4.3.3 · `tapable` 2.3.3 · `vite` 6.4.5

### ISC (1)

`graceful-fs` 4.2.11

### MPL-2.0 (1)

`lightningcss` 1.32.0

### BSD-3-Clause (1)

`source-map-js` 1.2.2
