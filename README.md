# OpenForms

**Add modular forms to WooCommerce products and variations.** Form data and file uploads are stored against the order for easy review.

OpenForms is a lightweight WordPress plugin that lets store owners attach configurable form fields to any WooCommerce product - or to individual variations of a variable product. Shoppers fill the form on the product page, the answers travel with the cart item through checkout, and every submission ends up attached to the order, where it can be reviewed by the shop staff.

The goal of this plugin is to provied a bare-bones, simple, and easy no-nonsense product forms solution for stores of any size. This plugin does not and likely will never have a "Pro" version. The goal of the project is to be the most open source friendly as possible.

- **Plugin name:** OpenForms
- **Version:** 1.1.0
- **Requires:** WordPress, WooCommerce, PHP 8.0+
- **License:** [GPL-2.0-or-later](LICENSE)

---

## What it does

OpenForms adds a form builder to the product edit screen. Each form is a simple list of fields, and each field can be one of the built-in generic types:

| Field type | Renders as | Notes |
| --- | --- | --- |
| One-line text | text input | default type |
| Multi-line text | textarea | |
| Email | email input | validated client-side & server-side |
| URL | URL input | validated client-side & server-side |
| Phone number | tel input | |
| Number | number input | validated server-side |
| Password | password input | |
| Colour | colour picker | validated server-side |
| Date / Time | date / time input | |
| Dropdown | select | options configured per field |
| File upload | file input | validated, sanitized, stored privately |

New field types can be registered by developers through the [`openforms_field_types`](#extending) filter.

Every field can be marked as **required**. Any submitted file goes through WordPress's core filetype checks and is moved into a private `openforms` folder inside the site's upload directory. The folder is denied over the web (via `.htaccess`), files are stored under unguessable random tokens, and they are only ever served through an authorized download endpoint — to shop staff, or to the shopper whose cart still holds the file. The files are never exposed to the public directly.

---

## Requirements

- WordPress 5.x or newer (HPOS-aware)
- WooCommerce (required)
- PHP 8.0 or newer

## Installation

1. Upload the `open-forms` folder to `/wp-content/plugins/` (or install the zip from the WordPress repository / GitHub release).
2. Activate the **OpenForms** plugin in the WordPress admin.
3. Open a product edit screen and configure a form (see below).

## Usage (for store owners)

### 1. Configure a form on a product

Edit any product and open the **OpenForms** tab under **Product data**:

- Tick **Enable forms** to activate the form on the product page.
- Use **Add Field** to create rows, choosing a name, a field type, an optional default value, and whether the field is required. Dropdown fields also get a one-option-per-line list.
- Use **Remove** to delete a row.
- Click **Update** to save.

For **variable products** you have two layers of forms:

- **Product-level fields** — configured on the main product screen. They apply to *every* variation.
- **Variation-level fields** — configured inside each variation's **OpenForms** panel. They only apply when that specific variation is selected on the frontend.

### 2. The shopper experience

On the product page, the configured fields appear above the **Add to cart** button.

- For simple products, the form is always visible.
- For variable products, the product-level fields are always visible, and each variation's fields are revealed as soon as the shopper selects that variation.

The shopper fills in the fields and adds the product to the cart. Required fields are enforced before the item is added, and malformed URLs, emails or numbers are rejected with a friendly notice.

### 3. The cart, checkout and order

- Submitted values are shown underneath the item in the **cart** and **checkout** summaries (files appear as a clickable link).
- Once the order is placed, every submitted value is persisted to the order's line item and a summary order note is added automatically (e.g. `OpenForms: T-shirt - Size: M; Personalisation: "Hello world"`).
- On the customer-facing **order received** page, order details and order emails, the submitted values are shown as a neat table (files display their name and size; the raw JSON is hidden).
- In the admin, the **Form Attachments** meta box on the order screen lists the submitted fields for each line item, including the file name and size.

### 4. The "Get a Quote" block

Insert the **Get a Quote** block into any post or page. It starts with a heading, a paragraph, a success message, a few fields and a submit button, all of which are normal blocks you can add, remove and reorder.

- Every form gets its own random ID and a **Form name** (set in the block sidebar). Quotes are grouped under that name in the admin and prefixed with it in the quotes list.
- **Quote Field** blocks are generic inputs. Pick a type, give the field a title, placeholder, default value, required flag, full-width flag and (optionally) show it in the admin quick preview. Dropdown fields take their own option list.
- Fields may be dragged into a group, row, column or stack inside the form. They are hidden on the frontend unless a quote form surrounds them.
- The **Success Message** block is always visible in the editor so you can restyle and reposition it; on the frontend it only appears after a successful submission.
- Submissions appear under **Quotes** in the admin. Deleting a form's block removes the form from the admin form filter; stored quotes keep their data.

---

## How it works (developer perspective)

The plugin is deliberately modular. `OpenForms.php` bootstraps everything: it guards the PHP version and defines the plugin constants on `plugins_loaded`, then constructs the plugin class, which loads `src/Init.php`. `Init::boot()` registers a small PSR-4 style autoloader that maps the `OpenForms\` namespace to the `src/` directory, loads the text domain, and instantiates the eight independent modules.

### Architecture

```
OpenForms.php              Entry point, constants, PHP 8.0 guard, plugin class
src/
├── Init.php               Registers the autoloader and loads the modules
├── FieldHelpers.php       Shared field registry + render/validate/sanitize/upload helpers
├── DataHelpers.php        Shared helpers for the form payload JSON (decode, tokens, file render)
├── Admin/
│   └── ProductSettings.php  Product data tab + variation panel, persistence, admin JS
├── Frontend/
│   └── ProductForm.php     Renders fields above the add-to-cart button
├── Services/
│   ├── CartHandler.php    Validation, cart item data, cart/checkout display, order meta
│   ├── Download.php       Authorized download endpoint for private uploads
│   ├── Cleanup.php        Deletes uploads orphaned by removed cart items / deleted orders
│   ├── FormRegistry.php   Tracks the quote forms (ID, name, fields) found in block content
│   └── QuoteHandler.php   Quote submissions, notification emails, admin viewer for the get-a-quote block
├── Blocks/
│   └── GetQuote.php       "Get a Quote" form, generic field and success message blocks
└── DTOs/
    └── OrderFormData.php  Order-screen meta box + summary order notes
assets/
├── js/admin.js            Add/remove field rows (uses a hidden template row)
├── js/frontend.js         Shows/hides variation fields on found_variation
├── js/quote-editor.js     Block editor assets for the quote form
└── css/                   admin.css, frontend.css, quote.css
```

### Shared field logic — `src/FieldHelpers.php`

The `FieldHelpers` class is the single source of truth for fields:

- `FieldHelpers::types()` — returns the registered field types. Wrapped in the `openforms_field_types` filter. The generic types are One-line text, Multi-line text, Email, URL, Phone number, Number, Password, Colour, Date, Time, File upload and Dropdown; each maps to a native input type. Dropdown fields carry their own option list through `FieldHelpers::options()`.
- `FieldHelpers::for_post( $post_id )` — returns the cleaned, enabled field list for a product or variation, keyed by field key. Returns an empty array when forms are disabled.
- `FieldHelpers::render( $input_key, $field )` — outputs the label + input markup.
- `FieldHelpers::validate( $input_key, $field )` — returns an array of error messages (required check, URL/email/number format, file upload errors).
- `FieldHelpers::sanitize( $type, $value )` — type-aware sanitization of submitted values.
- `FieldHelpers::sanitize_default( $type, $value, $options )` — cleans the authored default value for a field (dropdowns must match their options; files have none).
- `FieldHelpers::save_file( $input_key )` — moves an uploaded file into the private `openforms` uploads folder after WordPress filetype checks, storing it under a random token and returning file details (token, name, download url, path, size).
- `FieldHelpers::is_valid_token( $token )` / `FieldHelpers::files_for_token( $token )` — shared token validation and file lookup used by the download endpoint and cleanup.

### Shared payload helpers — `src/DataHelpers.php`

The `DataHelpers` class owns the JSON payload stored on cart items and order line items:

- `DataHelpers::decode( $json )` — decodes `openforms_data` into an array, returning an empty array when it is missing or not valid JSON.
- `DataHelpers::file_tokens( $data )` — the file tokens referenced by a decoded payload.
- `DataHelpers::render_file( $entry, $with_link, $with_size )` — renders a file entry's value (name, optional download link and formatted size).

### 1. Admin configuration — `src/Admin/ProductSettings.php`

- Registers an **OpenForms** tab inside the **Product data** metabox on the product edit screen and a **per-variation panel** inside the variable product editor.
- Renders an "Enable forms" checkbox plus a repeatable field table (name / type / default / required / remove). A hidden template `<tr>` with an `INDEX` placeholder is used by `assets/js/admin.js` to add rows; picking the Dropdown type reveals an options textarea under the name.
- Saves config via `woocommerce_process_product_meta` and `woocommerce_save_product_variation` into post meta:
  - `_openforms_enabled` → `'yes'` / `'no'`
  - `_openforms_fields` → array of `{ key, name, type, required, default, options }` (`options` only for dropdowns)
- Field keys are generated from a sanitized title plus a running index (`sanitize_title( $name ) . '-' . $i`).

### 2. Frontend rendering — `src/Frontend/ProductForm.php`

- Hooks `woocommerce_before_add_to_cart_button` to output the fields above the add-to-cart button.
- Renders **product-level fields** always. Field input names use `openforms[<key>]`.
- For **variable products**, renders each variation's fields in a hidden `<div class="openforms-variation-fields" data-variation-id="…">`. Field names are prefixed with the variation ID (`openforms[<variation_id>_<key>]`) so they are unique.
- `assets/js/frontend.js` listens to WooCommerce's `found_variation` / `reset_data` events to show and hide the variation-specific fields.

### 3. Cart & checkout lifecycle — `src/Services/CartHandler.php`

The submitted data travels through the WooCommerce pipeline via these hooks:

1. **Validate** — `woocommerce_add_to_cart_validation` runs `FieldHelpers::validate()` for every merged product + variation field and blocks add-to-cart with notices if any fail.
2. **Attach to cart item** — `woocommerce_add_cart_item_data` sanitizes each value, moves uploaded files, and stores everything as a JSON string under the `openforms_data` cart item key.
3. **Display** — `woocommerce_get_item_data` decodes that JSON and shows each field/value in the cart and checkout item summaries (files as links).
4. **Persist to order** — `woocommerce_checkout_create_order_line_item` writes the same JSON onto the order line item meta (`openforms_data`).
5. **Hide raw meta** — `woocommerce_hidden_order_itemmeta` keeps the raw JSON out of the standard order meta table, since it is rendered in its own meta box instead.

### 4. Order admin — `src/DTOs/OrderFormData.php`

- Adds a **Form Attachments** meta box to the order screen. It detects whether the store uses legacy order posts or HPOS (`CustomOrdersTableController`) and registers against the correct screen.
- Renders a table per line item showing every submitted field, with files as links plus formatted file size.
- `woocommerce_display_item_meta` swaps the raw `openforms_data` JSON for a readable table on the customer-facing order screens (order received page, order details, emails). File entries are shown as name + size since the download endpoint only serves files while the token is still in the cart.
- `woocommerce_checkout_order_created` appends a compact **order note** summarizing each item's form content, so the data shows up in email/status history too.

### 5. Private file delivery — `src/Services/Download.php`

Uploaded files are stored in a dedicated `openforms` folder under `wp-content/uploads`. The plugin writes its own protection files there (`index.php` and `.htaccess` denying direct web access) and re-creates any that go missing on the next upload or download, and every file is stored under a random, unguessable token instead of its real name. Uploads are capped at 75MB by default.

`.htaccess` is only honoured by Apache. On Nginx (or Apache with `AllowOverride none`) add the equivalent server rule — `location ^~ /wp-content/uploads/openforms/ { deny all; }` — or point the storage folder outside the document root with the `openforms_upload_dir` filter. The size ceiling is filterable through `openforms_max_upload_size`.

Files are only served through the `?openforms_download=<token>` endpoint (`template_redirect`). The request is allowed when the token is still present in the shopper's active cart, when the current user can `edit_shop_orders` (admin / shop staff), or when the file is a quote attachment and the user can edit the quote that owns it — so the cart and checkout links work while the token hasn't been placed on an order yet. Files are always streamed as a forced download with `X-Content-Type-Options: nosniff` so stored content can never render inside a browser origin.

Quote attachments are additionally limited to a fixed allow-list of types — images, PDF, Office documents and zip; see `QuoteHandler::attachment_mimes()`.

### Quote forms — `src/Blocks/GetQuote.php` + `src/Services/FormRegistry.php`

The **Get a Quote** block is built from inner blocks: core headings/paragraphs, `openforms/quote-field` blocks, a `openforms/quote-success` block and a core button. Fields and the success message may be nested inside `core/group`, `core/columns`/`core/column` or `core/stack` blocks as long as the container is inside the form.

- **Form identity** — the editor generates a random `formId` on insert and keeps a user editable `formName`. Both live in the block attributes; `provides_context` passes the ID down to nested field and success blocks, which is also how they enforce being inside a form (`uses_context`).
- **Field types** — fields use the shared generic type registry above, so a field is just a name, a type (text, number, colour, date, file, dropdown, …), an optional placeholder, default value, required flag, full-width flag and an "show in admin preview" flag. Dropdowns store their own option list.
- **Registry** — `FormRegistry` mirrors the forms found in saved post content into the `openforms_forms` option (name, field definitions, source post IDs). The render callback also refreshes it, so a rendered form can always be validated even before the post is re-saved. Removing the block (or deleting/trashing the post) removes the form from the registry; stored quotes keep their snapshot.
- **Submissions** — `QuoteHandler` looks the posted `form` ID up in the registry and validates/sanitizes the posted values against that definition. The form name prefixes the quote title (`Form name – First preview value`) and each quote stores an ordered field snapshot (`_openforms_quote_fields`), so the admin list and meta box render any number of fields. Per-field file uploads are grouped by field key.
- **Success message** — `openforms/quote-success` is a dynamic block that is always visible in the editor (style and position are block supports) but only renders on the frontend when the redirect carries a matching `openforms_quote_form` and `openforms_quote=success`. Forms without one fall back to the notice above the form.
- **Admin** — the quotes list adds a Form column, an adaptive Preview column (fields flagged for preview) and a form filter dropdown built from the registry. Existing quotes created before forms were identified still display their old fixed data.

### 7. Submission rate limit — `src/Services/QuoteHandler.php`

The quote form posts to `admin-post.php` with no authentication, so every submission is rate limited: one quote per 10 minutes per IP. The limit exists for two reasons — an attachment fills disk space, and each stored quote triggers a notification email. Text-only submissions are covered too; previously only uploads were, which left an unlimited path to the inbox.

- Filterable through `openforms_quote_submission_interval` (set it to `0` to disable). The older `openforms_quote_upload_interval` still applies as the base value, so existing overrides keep working.
- The IP is hashed before being stored, so no address is written to the database, and the limit is skipped for logged-in users who can already publish content — which keeps the form usable while testing.
- The cooldown is claimed only after the quote is stored, so a submission rejected for validation or a bad upload does not lock the visitor out for 10 minutes.
- Blocked submissions redirect back with the `throttle` notice.

### 8. New quote notifications — `src/Services/QuoteHandler.php`

Once a quote is stored, the site owner is emailed through WordPress' own mailer (`wp_mail()`), so any SMTP plugin or transactional mailer already in place picks the messages up without extra configuration.

- **To** — the site admin email by default. Override it, or add more people with the `openforms_quote_notification_recipient` filter; returning an empty value turns notifications off.
- **Subject** — `[Site name] New quote request: <form name>`.
- **Body** — an HTML table of the submitted fields plus a link to the quote's admin screen. Override it with the `openforms_quote_notification_body` filter.
- **Reply-To** — the value of the first email field, so replying to the notification goes back to the customer.
- **Attachment** — when a file was uploaded it is attached to the email itself, not linked. The stored file sits in the private folder and is only reachable by a signed-in admin, so a link would be dead for anyone reading their mail on a phone. Files over 10MB (`openforms_quote_notification_max_attachment_size` to change) are left out — mail servers routinely refuse messages past a few tens of megabytes — and the email says so instead, so you know the file exists and to fetch it from the admin.
- A delivery failure is logged through `wc_get_logger()` only; the quote stays saved and the visitor still sees the normal success notice.

Note the mail is sent inline during the request, so a very large upload briefly delays the redirect while the message is handed to the mailer.

### 6. Orphan cleanup — `src/Services/Cleanup.php`

Uploads are deleted as soon as their only reference goes away, so abandoned files do not accumulate in the private folder:

- **Cart item removed** — the file tokens are queued in the shopper's session (so the item can still be restored via the cart's "Undo" notice) and physically deleted once the cart is emptied.
- **Cart emptied** — every queued token that is not referenced by an existing order is deleted. During checkout the new order is persisted before the cart is emptied, so files that now belong to an order are kept.
- **Order deleted** — on permanent deletion (`woocommerce_before_delete_order` for WooCommerce 8.0+ HPOS *and* legacy orders, `before_delete_post` as a fallback for older stores) every file referenced by the order's line items is deleted, unless another order still references the same file. Trashing an order is left untouched, so restorable orders keep their files.

### Storage summary

| What | Where |
| --- | --- |
| Enabled flag | post meta `_openforms_enabled` (`yes`/`no`) |
| Product field definitions | post meta `_openforms_fields` (serialized array) |
| Quote form definitions | option `openforms_forms` (name, fields, source post IDs) |
| Quote submissions | post meta `_openforms_quote_form`, `_openforms_quote_form_name`, `_openforms_quote_fields`, `_openforms_quote_source` |
| Quote attachments | post meta `_openforms_quote_file`, grouped by field key |
| Cart item data | `openforms_data` JSON key on the cart item |
| Order line item data | `openforms_data` JSON meta on the order line item |
| Uploaded files | `wp-content/uploads/openforms/` (private, denied over the web, served via authorized download) |

### Extending

- **Add a custom field type** with the `openforms_field_types` filter. Register a new slug with a `label` and an `input` type (`text` or `textarea`), then provide render/validate/sanitize handling for your slug in your own code:

```php
add_filter( 'openforms_field_types', function ( $types ) {
    $types['date'] = array( 'label' => __( 'Date', 'your-textdomain' ), 'input' => 'text' );
    return $types;
} );
```

- All data hand-offs use standard WordPress / WooCommerce hooks, so the plugin plays nicely with other code that hooks into product, cart and order lifecycle events.

---

## Development & deployment

- `deploy.sh` — rsyncs the plugin to a live server (excludes `.git`, `*.md` and itself).
- `.github/workflows/publish-plugin.yml` — on every GitHub **release**, deploys the plugin to the WordPress.org plugin repository via [10up's deploy action](https://github.com/10up/action-wordpress-plugin-deploy) and uploads the built zip to the release.

## Contributing

Bug reports and feature requests are welcome via the GitHub issue templates. When changing CSS or JS, remember to bump the corresponding asset version (`OPENFORMS_VERSION` in `OpenForms.php`) to avoid cache collisions.

## AI Disclaimer

This project is developed with the help of DeepSeek V4 Flash, an AI coding assistant. AI-generated code is reviewed by humans and may be used to speed up development, but the final responsibility for the code and its quality lies with the maintainers. Ultimately we use LLMs as tools, not replacements for skill.

## License

[GPL-2.0-or-later](LICENSE)
