(function ($) {
	$(function () {
		$('form.variations_form').each(function () {
			var $form = $(this);

			var showFields = function (variationId) {
				$form.find('.openforms-variation-fields').hide();

				if (variationId) {
					$form
						.find('.openforms-variation-fields[data-variation-id="' + variationId + '"]')
						.show();
				}
			};

			$form.on('found_variation', function (e, variation) {
				showFields(variation.variation_id);
			});

			$form.on('reset_data', function () {
				showFields(null);
			});
		});
	});
})(jQuery);
