# AGENTS.md

## Layout

This repository contains two plugins that are deployed together but can be activated independently:

- `open-forms/` — the base plugin: quote forms, the shared field engine, private uploads and the download endpoint. It does not require WooCommerce.
- `open-forms-for-wc/` — the WooCommerce add-on: product option forms on products and variations, cart/checkout handling and the order review screen. It requires WooCommerce and the base plugin.

Changes to `open-forms/src/FieldHelpers.php` or `open-forms/src/DataHelpers.php` affect both plugins.

## Changing CSS

When modifying any CSS or JS, always bump the corresponding loader version to avoid cache collisions:

- Base plugin assets: `OPENFORMS_VERSION` in `open-forms/OpenForms.php`.
- WooCommerce add-on assets: `OPENFORMS_WC_VERSION` in `open-forms-for-wc/open-forms-for-wc.php`.

## Changes in general

Avoid adding overly large, complex code that is hard to read or understand. All new code should follow existing styling and comments in the plugin and file names should try to match/blend in.
Use standard PHP/WordPress/WooCommerce layouts and schemes, and re-use core methods before trying to implement your own.

## Verification

There is no automated test suite or CI — verify changes manually:

- `php -l <file>` syntax-checks a single PHP file (see the global AGENTS.md for the flatpak invocation).
- Lint every PHP file in both plugins, for example:
  `find open-forms open-forms-for-wc -name '*.php' -exec host-spawn flatpak run --filesystem=host --command=/usr/lib/sdk/php83/bin/php org.freedesktop.Sdk//24.08 -l {} \;`
- Deployment smoke test happens on the live site via `./deploy.sh`, which deploys both plugins. Use `bash open-forms/deploy.sh` or `bash open-forms-for-wc/deploy.sh` to deploy one.
