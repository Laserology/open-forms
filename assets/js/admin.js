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

	// Use the highest existing row index + 1 so removing a middle row never
	// causes a new row to reuse an index that is still present (which would
	// silently overwrite a field on save).
	function nextFieldIndex(tbody) {
		var index = 0;
		var rows = realRows(tbody);

		for (var i = 0; i < rows.length; i++) {
			var input = rows[i].querySelector('input[name*="[fields]["][name]');
			var match = input ? input.name.match(/\[fields\]\[(\d+)\]\[name\]/) : null;

			if (match) {
				index = Math.max(index, parseInt(match[1], 10) + 1);
			}
		}

		return index;
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
			var index = nextFieldIndex(tbody);

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
