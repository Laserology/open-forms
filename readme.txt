=== OpenForms ===
Contributors: laserology
Tags: woocommerce, forms, product fields, file uploads, quotes
Requires at least: 6.3
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add modular forms to WooCommerce products and variations. Form data and file uploads are stored against the order for easy review.

== Description ==

OpenForms lets store owners attach configurable form fields to any WooCommerce product, or to individual variations of a variable product. Shoppers fill in the form on the product page, the answers travel with the cart item through checkout, and every submission is stored against the order for shop staff to review.

Each field can be one of six built-in types: Text, Textarea, URL, Email, Number, or File Upload. Fields can be marked as required, and submitted values are validated on both the client and the server.

File uploads are stored in a private folder inside the uploads directory. Files are given unguessable tokens and are only served through an authorized download endpoint, to shop staff or to the shopper whose cart still contains the file.

The plugin also includes a "Get a Quote" block that stores quote requests as a private post type and emails the store owner when a new request arrives.

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

= 1.9.6 =
* Fixed the plugin text domain to match the plugin slug.
* Fixed sanitization and escaping issues reported by Plugin Check.
* Removed the deprecated `load_plugin_textdomain()` call.

= 1.9.5 =
* Added file uploads to the "Get a Quote" block and hardened the upload path.
