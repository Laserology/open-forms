# AGENTS.md

## Changing CSS
When modifying any CSS or JS in the plugin, always bump the coresponding loader version to avoid cache collisions.

## Changes in general
Avoid adding overly large, complex code that is hard to read or understand. All new code should follow existing styling and comments in the plugin and file names should try to match/blend in.
Use standard PHP/Wordpress/Woocommerce layouts and schemes, and re-use core methods before trying to implement your own.

## Verification

There is no automated test suite or CI — verify changes manually:
- `php -l <file>` syntax-checks a single PHP file (see the global AGENTS.md for the flatpak invocation)
- Deployment smoke test happens on the live site via `./deploy.sh`.
