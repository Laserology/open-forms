/**
 * Get a Quote frontend. The file picker is rendered as a themed button and the
 * native control is hidden, so mirror the chosen file's name next to it.
 */
(function () {
	function fileName(input) {
		if (!input.files || !input.files.length) {
			return '';
		}

		return input.files[0].name;
	}

	document.addEventListener('change', function (event) {
		var input = event.target;

		if (!input || 'file' !== input.type || !input.classList) {
			return;
		}

		if (!input.classList.contains('openforms-quote__file-input')) {
			return;
		}

		var field = input.closest('.openforms-quote__file');
		var name = field ? field.querySelector('.openforms-quote__file-name') : null;

		if (name) {
			name.textContent = fileName(input);
		}
	});
})();
