# OpenForms

**Add modular forms to WooCommerce products and variations.** Form data and file uploads are stored against the order for easy review.

OpenForms is a lightweight WordPress plugin that lets store owners attach configurable form fields to any WooCommerce product - or to individual variations of a variable product. Shoppers fill the form on the product page, the answers travel with the cart item through checkout, and every submission ends up attached to the order, where it can be reviewed by the shop staff.

The goal of this plugin is to provied a bare-bones, simple, and easy no-nonsense product forms solution for stores of any size. This plugin does not and likely will never have a "Pro" version. The goal of the project is to be the most open source friendly as possible.

- **Plugin name:** OpenForms
- **Version:** 1.0.0
- **Requires:** WordPress, WooCommerce, PHP 7.4+
- **License:** [GPL-2.0-or-later](LICENSE)

---

## What it does

OpenForms adds a form builder to the product edit screen. Each form is a simple list of fields, and each field can be one of six built-in types:

| Field type | Renders as | Notes |
| --- | --- | --- |
| Text | single-line input | default type |
| Textarea | multi-line textarea | |
| URL | URL input | validated client-side & server-side |
| Email | email input | validated client-side & server-side |
| Number | number input | validated server-side |
| File Upload | file input | validated, sanitized, stored in `uploads` |

New field types can be registered by developers through the [`openforms_field_types`](#extending) filter.

Every field can be marked as **required**. Any submitted file goes through WordPress's core filetype checks and is moved into a dedicated `openforms` folder inside the site's upload directory, so the file is never exposed to the public directly.

---

## Requirements

- WordPress 5.x or newer (HPOS-aware)
- WooCommerce (required)
- PHP 7.4 or newer

## Installation

1. Upload the `open-forms` folder to `/wp-content/plugins/` (or install the zip from the WordPress repository / GitHub release).
2. Activate the **OpenForms** plugin in the WordPress admin.
3. Open a product edit screen and configure a form (see below).

## Usage (for store owners)

### 1. Configure a form on a product

Edit any product and scroll to the **OpenForms** meta box:

- Tick **Enable forms** to activate the form on the product page.
- Use **Add Field** to create rows, choosing a name, a field type, and whether the field is required.
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
- In the admin, the **Form Attachments** meta box on the order screen lists the submitted fields for each line item, including the file name and size.

---

## How it works (developer perspective)

The plugin is deliberately modular. `OpenForms.php` bootstraps everything: it defines the plugin constants, registers a small PSR-4 style autoloader that maps the `OpenForms\` namespace to the `src/` directory, and instantiates the core `Init` class. `Init` then loads four independent modules.

### Architecture

```
OpenForms.php              Entry point, constants, PSR-4 autoloader
src/
├── Init.php               Loads the four modules
├── Fields.php             Shared field registry + render/validate/sanitize/upload helpers
├── ProductForms.php       Product meta box + variation panel, persistence, admin JS
├── ProductForm.php        Renders fields above the add-to-cart button
├── CartHandler.php        Validation, cart item data, cart/checkout display, order meta
└── OrderFormData.php      Order-screen meta box + summary order notes
assets/
├── js/admin.js            Add/remove field rows (uses a hidden template row)
├── js/frontend.js         Shows/hides variation fields on found_variation
└── css/                   admin.css, frontend.css
```

### Shared field logic — `src/Fields.php`

The `Fields` class is the single source of truth for fields:

- `Fields::types()` — returns the registered field types. Wrapped in the `openforms_field_types` filter.
- `Fields::for_post( $post_id )` — returns the cleaned, enabled field list for a product or variation, keyed by field key. Returns an empty array when forms are disabled.
- `Fields::render( $input_key, $field )` — outputs the label + input markup.
- `Fields::validate( $input_key, $field )` — returns an array of error messages (required check, URL/email/number format, file upload errors).
- `Fields::sanitize( $type, $value )` — type-aware sanitization of submitted values.
- `Fields::save_file( $input_key )` — moves an uploaded file into the `openforms` uploads folder after WordPress filetype checks, returning file details (name, url, path, size).

### 1. Admin configuration — `src/ProductForms.php`

- Registers a **"OpenForms" meta box** on the product edit screen and a **per-variation panel** inside the variable product editor.
- Renders an "Enable forms" checkbox plus a repeatable field table (name / type / required / remove). A hidden template `<tr>` with an `INDEX` placeholder is used by `assets/js/admin.js` to add rows.
- Saves config via `woocommerce_process_product_meta` and `woocommerce_save_product_variation` into post meta:
  - `_openforms_enabled` → `'yes'` / `'no'`
  - `_openforms_fields` → array of `{ key, name, type, required }`
- Field keys are generated from a sanitized title plus a running index (`sanitize_title( $name ) . '-' . $i`).

### 2. Frontend rendering — `src/ProductForm.php`

- Hooks `woocommerce_before_add_to_cart_button` to output the fields above the add-to-cart button.
- Renders **product-level fields** always. Field input names use `openforms[<key>]`.
- For **variable products**, renders each variation's fields in a hidden `<div class="openforms-variation-fields" data-variation-id="…">`. Field names are prefixed with the variation ID (`openforms[<variation_id>_<key>]`) so they are unique.
- `assets/js/frontend.js` listens to WooCommerce's `found_variation` / `reset_data` events to show and hide the variation-specific fields.

### 3. Cart & checkout lifecycle — `src/CartHandler.php`

The submitted data travels through the WooCommerce pipeline via these hooks:

1. **Validate** — `woocommerce_add_to_cart_validation` runs `Fields::validate()` for every merged product + variation field and blocks add-to-cart with notices if any fail.
2. **Attach to cart item** — `woocommerce_add_cart_item_data` sanitizes each value, moves uploaded files, and stores everything as a JSON string under the `openforms_data` cart item key.
3. **Display** — `woocommerce_get_item_data` decodes that JSON and shows each field/value in the cart and checkout item summaries (files as links).
4. **Persist to order** — `woocommerce_checkout_create_order_line_item` writes the same JSON onto the order line item meta (`openforms_data`).
5. **Hide raw meta** — `woocommerce_hidden_order_itemmeta` keeps the raw JSON out of the standard order meta table, since it is rendered in its own meta box instead.

### 4. Order admin — `src/OrderFormData.php`

- Adds a **Form Attachments** meta box to the order screen. It detects whether the store uses legacy order posts or HPOS (`CustomOrdersTableController`) and registers against the correct screen.
- Renders a table per line item showing every submitted field, with files as links plus formatted file size.
- `woocommerce_checkout_order_created` appends a compact **order note** summarizing each item's form content, so the data shows up in email/status history too.

### Storage summary

| What | Where |
| --- | --- |
| Enabled flag | post meta `_openforms_enabled` (`yes`/`no`) |
| Field definitions | post meta `_openforms_fields` (serialized array) |
| Cart item data | `openforms_data` JSON key on the cart item |
| Order line item data | `openforms_data` JSON meta on the order line item |
| Uploaded files | `wp-content/uploads/<date>/openforms/` |

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
