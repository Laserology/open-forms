OpenForms
=========

Modular forms for WordPress, plus product option forms for WooCommerce.

This repository contains two plugins:

open-forms/
    The base plugin. Block based quote forms, the shared field engine,
    private upload storage and the download endpoint. Works on any
    WordPress site and does not require WooCommerce.

open-forms-for-wc/
    The WooCommerce add-on. Product option forms on products and
    variations, cart and checkout handling, and the order review screen.
    Requires WooCommerce and the base plugin.

Requirements
------------

Base plugin:
    WordPress 6.3 or newer
    PHP 8.0 or newer

WooCommerce add-on:
    WordPress 6.3 or newer
    WooCommerce
    OpenForms (the base plugin)
    PHP 8.0 or newer

Installation
------------

1. Copy open-forms/ to wp-content/plugins/ and activate OpenForms.
2. For product forms, copy open-forms-for-wc/ to wp-content/plugins/ and
   activate OpenForms for WooCommerce.
3. Add a "Get a Quote" block to a page, or configure a form on a product.

Documentation
-------------

See README.md for the developer documentation and each plugin's readme.txt
for its WordPress.org listing details.

License
-------

GPL-2.0-or-later. See LICENSE.

OpenForms for WooCommerce is an independent plugin and is not affiliated
with or endorsed by WooCommerce or Automattic.
