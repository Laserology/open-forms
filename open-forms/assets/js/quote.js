/**
 * Get a Quote frontend. The file picker is rendered as a themed button and the
 * native control is hidden, so mirror the chosen file names next to it.
 */
(function () {
	function fileNames(input) {
		if (!input.files || !input.files.length) {
			return '';
		}

		return Array.prototype.map.call(input.files, function (file) {
			return file.name;
		}).join(', ');
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
			name.textContent = fileNames(input);
		}
	});

	// A form may contain more than one file field, so check every picker.
	function hasFiles(form) {
		var inputs = form.querySelectorAll('.openforms-quote__file-input');

		for (var i = 0; i < inputs.length; i++) {
			if (inputs[i].files && inputs[i].files.length) {
				return true;
			}
		}

		return false;
	}

	// The form posts to admin-post.php, so there is no navigation to show
	// progress. Reveal the status line and lock the button as soon as the
	// browser accepts the submission, otherwise a big file looks like a frozen
	// page.
	document.addEventListener('submit', function (event) {
		var form = event.target;

		if (!form || !form.classList || !form.classList.contains('openforms-quote__form')) {
			return;
		}

		var status = form.querySelector('.openforms-quote__status');
		var button = form.querySelector('button[type="submit"]');

		if (status) {
			status.textContent = hasFiles(form) ? status.dataset.uploading : status.dataset.sending;
			status.hidden = false;
		}

		if (button) {
			button.disabled = true;
		}
	});

	// Restoring the page from the back/forward cache replays the submit event,
	// so clear the locked state again or the form looks permanently busy.
	window.addEventListener('pageshow', function (event) {
		if (!event.persisted) {
			return;
		}

		var forms = document.querySelectorAll('.openforms-quote__form');

		Array.prototype.forEach.call(forms, function (form) {
			var status = form.querySelector('.openforms-quote__status');
			var button = form.querySelector('button[type="submit"]');

			if (status) {
				status.hidden = true;
				status.textContent = '';
			}

			if (button) {
				button.disabled = false;
			}
		});
	});
})();
