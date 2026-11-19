=== OpenForms ===
Contributors: laserology
Tags: woocommerce, forms, product fields, file uploads, quotes
Requires at least: 6.3
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add modular forms to WooCommerce products and variations. Form data and file uploads are stored against the order for easy review.

== Description ==

OpenForms lets store owners attach configurable form fields to any WooCommerce product, or to individual variations of a variable product. Shoppers fill in the form on the product page, the answers travel with the cart item through checkout, and every submission is stored against the order for shop staff to review.

Each field can be one of the built-in generic types: One-line text, Multi-line text, Email, URL, Phone number, Number, Password, Colour, Date, Time, Dropdown, or File upload. Every field gets its own name, placeholder, default value and required flag, and dropdown fields accept their own option list. Fields can be marked as required, and submitted values are validated on both the client and the server.

File uploads are stored in a private folder inside the uploads directory. Files are given unguessable tokens and are only served through an authorized download endpoint, to shop staff or to the shopper whose cart still contains the file.

The plugin also includes a "Get a Quote" block. Every form gets its own ID and editable name, its fields are generic inputs that can be arranged in groups, rows and columns, and a virtual success message that can be styled and positioned in the editor. Submissions are stored as a private post type, prefixed with the form name, and emailed to the store owner when a new request arrives.

== Installation ==

1. Upload the `open-forms` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen.
3. Edit a product and open the OpenForms tab under Product data to configure its fields.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. WooCommerce must be installed and active.

= Where are uploaded files stored? =

Uploads are stored in a private `openforms` folder inside the WordPress uploads directory. The folder is protected from direct web access and files are served only through an authorized download endpoint.

= Can I use forms on individual variations? =

Yes. Product-level fields apply to every variation, and each variation can also have its own fields that appear when that variation is selected.

== Changelog ==

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
