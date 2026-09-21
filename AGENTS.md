# AGENTS.md

## Changing CSS
When modifying any CSS or JS in the plugin, always bump the coresponding loader version to avoid cache collisions.

## Changes in general
Avoid adding overly large, complex code that is hard to read or understand. All new code should follow existing styling and comments in the plugin and file names should try to match/blend in.
Use standard PHP/Wordpress/Woocommerce layouts and schemes, and re-use core methods before trying to implement your own.

## Verification

There is no automated test suite or CI — verify changes manually:
- `php -l <file>` syntax-checks a single PHP file. The editor runs under Flatpak, so PHP is not on `PATH` inside the sandbox — run it on the host via `host-spawn` + the Flatpak SDK extension (PHP 8.3, system install, SDK 24.08 — php83 has no newer SDK branch, so this stays on 24.08 even though Zed now runs the 26.08 runtime):

  ```
  host-spawn flatpak run --filesystem=host --command=/usr/lib/sdk/php83/bin/php org.freedesktop.Sdk//24.08 -l <file>
  ```
- Node 26 is available the same way (`org.freedesktop.Sdk.Extension.node26`, SDK 26.08):

  ```
  host-spawn flatpak run --filesystem=host --command=/usr/lib/sdk/node26/bin/node org.freedesktop.Sdk//26.08 <script>
  ```

  Notes: `--filesystem=host` is required so the flatpak sandbox can read the repo; `--command` needs the full binary path because `PATH` is not set up under it. `npm`/`npx` shell scripts can't find `node` for the same reason — invoke them via `node /usr/lib/sdk/node26/lib/node_modules/npm/bin/npm-cli.js <args>` instead.
- Deployment smoke test happens on the live site via `./deploy.sh`.
