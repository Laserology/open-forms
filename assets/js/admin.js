(function () {
	function realRows(tbody) {
		return tbody.querySelectorAll('tr.openforms-field-row:not(.openforms-field-template)');
	}

	function removeEmptyRow(tbody) {
		var empty = tbody.querySelector('tr.openforms-empty-row:not(.openforms-empty-template)');

		if (empty) {
			empty.remove();
		}
	}

	function showEmptyRow(tbody) {
		var template = tbody.querySelector('tr.openforms-empty-template');

		if (!template) {
			return;
		}

		var row = template.cloneNode(true);
		row.classList.remove('openforms-empty-template');
		row.removeAttribute('hidden');
		tbody.insertBefore(row, template);
	}

	document.addEventListener('click', function (e) {
		var addBtn = e.target.closest('.openforms-add-field');

		if (addBtn) {
			var tbody = addBtn.closest('.openforms-config').querySelector('.openforms-fields-body');
			var template = tbody.querySelector('tr.openforms-field-template');

			if (!template) {
				return;
			}

			var row = template.cloneNode(true);
			var index = realRows(tbody).length;

			row.classList.remove('openforms-field-template');
			row.removeAttribute('hidden');
			row.innerHTML = row.innerHTML.split('INDEX').join(index);

			removeEmptyRow(tbody);
			tbody.insertBefore(row, template);
			return;
		}

		var removeBtn = e.target.closest('.openforms-remove-field');

		if (removeBtn) {
			var tbody = removeBtn.closest('.openforms-fields-body');

			removeBtn.closest('tr').remove();

			if (!realRows(tbody).length) {
				showEmptyRow(tbody);
			}
		}
	});
})();
