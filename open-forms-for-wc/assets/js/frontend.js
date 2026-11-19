(function ($) {
	// Debug output is opt-in via ?openforms_debug=1 so it can be left in
	// place for support without flooding every visitor's console.
	var debug = function () {
		return /openforms_debug=1/.test(window.location.search);
	};

	var log = function () {
		if (debug()) {
			window.console && window.console.log.apply(window.console, arguments);
		}
	};

	var getScope = function ($target) {
		var $scope = $target.closest('.product');

		if (!$scope.length) {
			$scope = $target;
		}

		return $scope;
	};

	var toggleFields = function ($scope, variationId) {
		$scope.find('.openforms-variation-fields').hide();

		if (variationId) {
			$scope
				.find('.openforms-variation-fields[data-variation-id="' + variationId + '"]')
				.show();
		}
	};

	// The hidden input that holds the current variation id. The classic form
	// and the block based add-to-cart both write the selected variation here,
	// so reading it is the most reliable cross-theme way to learn the selection.
	var readVariationId = function ($form) {
		return $form.find('.variation_id, input[name="variation_id"]').first().val() || '';
	};

	var handleFound = function (event, variation) {
		var $form = $(event.target).closest('form.variations_form');

		if (!$form.length) {
			$form = $(event.target);
		}

		log('openforms: found_variation', event.target, variation && variation.variation_id);
		toggleFields(getScope($form), variation && variation.variation_id);
	};

	var handleReset = function (event) {
		var $form = $(event.target).closest('form.variations_form');

		if (!$form.length) {
			$form = $(event.target);
		}

		log('openforms: reset_data', event.target);
		toggleFields(getScope($form), null);
	};

	// Classic variations form events. Bound on the document without a selector
	// filter so the fields react to variation changes even when the form is
	// injected or rebuilt by AJAX.
	$(document).on('found_variation', handleFound);
	$(document).on('reset_data', handleReset);
	$(document).on('show_variation', function (event, variation) {
		log('openforms: show_variation', event.target, variation && variation.variation_id);
		toggleFields(getScope($(event.target)), variation && variation.variation_id);
	});
	$(document).on('hide_variation', function (event) {
		log('openforms: hide_variation', event.target);
		toggleFields(getScope($(event.target)), null);
	});

	// Attribute selects/radios in a classic form. Read the hidden variation id
	// shortly after the change so the AJAX or the event handlers have time to
	// write it.
	$(document).on(
		'change',
		'form select, form input[type="radio"]',
		function () {
			var $form = $(this).closest('form');

			if (!$form.length) {
				return;
			}

			window.setTimeout(function () {
				var variationId = readVariationId($form);

				log('openforms: attribute change ->', variationId || '(none)');
				toggleFields(getScope($form), variationId);
			}, 400);
		}
	);

	// The block based add-to-cart (WooCommerce Interactivity API) selects
	// variations with chips and never fires the events above. It writes the
	// selected variation into the hidden variation_id input, so poll that and
	// only touch the DOM when the value actually changes.
	window.setInterval(function () {
		$('form').each(function () {
			var $form = $(this);

			if (!$form.find('.variation_id, input[name="variation_id"]').length) {
				return;
			}

			var id = readVariationId($form);
			var known = $form.data('openforms-variation-id');

			if (known === id) {
				return;
			}

			$form.data('openforms-variation-id', id);
			log('openforms: variation_id ->', id || '(none)');

			if (id) {
				toggleFields(getScope($form), id);
			} else {
				toggleFields(getScope($form), null);
			}
		});
	}, 500);

	// Show fields for whatever variation is already selected on load.
	$(function () {
		$('form').each(function () {
			var $form = $(this);
			var variationId = readVariationId($form);

			if (!variationId) {
				return;
			}

			log(
				'openforms: init',
				$form.get(0),
				variationId,
				$form.closest('.product').find('.openforms-variation-fields').length + ' variation field block(s)'
			);

			toggleFields(getScope($form), variationId);
		});
	});
})(jQuery);
