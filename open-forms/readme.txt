=== OpenForms ===
Contributors: laserology
Tags: forms, quote forms, form builder, file uploads, blocks
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add modular quote forms to any WordPress site. Product option forms for WooCommerce are available through the OpenForms for WooCommerce add-on.

== Description ==

OpenForms is a lightweight block based form builder. The "Get a Quote" block gives every form its own ID and editable name, and its fields are generic inputs that can be arranged in groups, rows and columns. A virtual success message can be styled and positioned in the editor.

Submissions are stored as a private post type, grouped by the form that received them, prefixed with the form name, and emailed to the site owner when a new request arrives.

Each field can be one of the built-in generic types: One-line text, Multi-line text, Email, URL, Phone number, Number, Password, Colour, Date, Time, Dropdown, or File upload. Every field gets its own name, placeholder, default value and required flag, and dropdown fields accept their own option list. Fields can be marked as required, and submitted values are validated on the server.

File uploads are stored in a private folder inside the uploads directory. Files are given unguessable tokens and are only served through an authorized download endpoint to whoever can view the submission that owns them.

The plugin does not require WooCommerce. The separate **OpenForms for WooCommerce** add-on uses the same field engine to add product option forms to products and variations, carry the answers through the cart, and store them on the order.

== Installation ==

1. Upload the `open-forms` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen.
3. Add a "Get a Quote" block to any page or post and configure its fields.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

No. The base plugin works on any WordPress site. If you want forms on WooCommerce products and variations, install the separate **OpenForms for WooCommerce** add-on.

= Where are uploaded files stored? =

Uploads are stored in a private `openforms` folder inside the WordPress uploads directory. The folder is protected from direct web access and files are served only through an authorized download endpoint.

= Can forms be reused across pages? =

Yes. Forms are identified by a random ID stored in the block, so the same form definition can be placed on multiple pages. Submissions are grouped by that ID in the admin.

== Changelog ==

= 1.2.0 =
* Split the WooCommerce product integration into the separate "OpenForms for WooCommerce" add-on.
* The base plugin now provides the quote form builder, the shared field engine and private file handling without requiring WooCommerce.

= 1.1.0 =
* Each "Get a Quote" block now gets a random form ID and an editable name; submissions are grouped and prefixed with the form name in the admin.
* Quote fields are generic input types (text, number, colour, date, dropdown, file, …) with configurable names, placeholders, default values, options and a per-field admin preview flag.
* Quote fields and the success message can be placed inside groups, rows and columns nested in a form.
* Added a virtual success message block that is visible in the editor and only rendered after a successful submission.
* Added Colour, Date, Time, Phone number, Password and Dropdown field types to the shared field registry.

= 1.9.6 =
* Fixed the plugin text domain to match the plugin slug.
* Fixed sanitization and escaping issues reported by Plugin Check.
* Removed the deprecated `load_plugin_textdomain()` call.

= 1.9.5 =
* Added file uploads to the "Get a Quote" block and hardened the upload path.
