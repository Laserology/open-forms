=== OpenForms for WooCommerce ===
Contributors: laserology
Tags: woocommerce, forms, product fields, product options, file uploads
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: woocommerce, open-forms

Product option forms for WooCommerce. Add configurable fields to products and variations, carry the answers through the cart, and store them on the order.

== Description ==

OpenForms for WooCommerce is the WooCommerce product integration for the OpenForms plugin. It adds a form builder to the product edit screen so store owners can attach configurable fields to any product, or to individual variations of a variable product.

Shoppers fill in the form on the product page, the answers travel with the cart item through checkout, and every submission is stored against the order for shop staff to review. Uploaded files are private and are linked only from the order and while the file is still attached to the shopper's cart.

Each field can be one of the built-in generic types: One-line text, Multi-line text, Email, URL, Phone number, Number, Password, Colour, Date, Time, Dropdown, or File upload. Every field gets its own name, default value and required flag, and dropdown fields accept their own option list. Submitted values are validated on the server.

This plugin is the WooCommerce integration only. The **OpenForms** base plugin provides the shared field engine, the private upload storage and the quote form builder, and must be installed and active.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate the **OpenForms** base plugin.
3. Install and activate **OpenForms for WooCommerce**.
4. Edit a product and open the **OpenForms** tab under **Product data** to configure its fields.

== Frequently Asked Questions ==

= Do I need the OpenForms plugin? =

Yes. This plugin is the WooCommerce integration for OpenForms and will not load without the base plugin.

= Is this an official WooCommerce plugin? =

No. OpenForms for WooCommerce is an independent plugin and is not affiliated with or endorsed by WooCommerce or Automattic.

= Where are uploaded files stored? =

Uploads are stored in the private `openforms` folder managed by the base plugin inside the WordPress uploads directory. The folder is protected from direct web access and files are served only through an authorized download endpoint.

= Can I use forms on individual variations? =

Yes. Product-level fields apply to every variation, and each variation can also have its own fields that appear when that variation is selected.

== Changelog ==

= 1.0.0 =
* Split the WooCommerce product option forms out of the OpenForms plugin into their own add-on.
* Adds the product data tab, the per-variation panel, the product page fields, the cart and checkout handling, the order attachments box and the order notes.
