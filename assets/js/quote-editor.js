/**
 * Get a Quote block editor. The heading, subheading and submit button are the
 * standard core heading, paragraph and button blocks, and the form fields are
 * inner blocks, so editors can add, remove and reorder everything just like
 * any native WordPress block. The block is rendered server-side by
 * GetQuote.php, so the frontend markup always comes from PHP.
 */
(function (wp) {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useBorderProps = wp.blockEditor.__experimentalUseBorderProps || wp.blockEditor.useBorderProps;
	var __ = wp.i18n.__;

	var FIELDS = {
		name:    { label: __('Name', 'openforms'), type: 'text', required: true },
		email:   { label: __('Email', 'openforms'), type: 'email', required: true },
		phone:   { label: __('Phone', 'openforms'), type: 'tel', required: false },
		budget:  { label: __('Estimated Budget', 'openforms'), type: 'select', required: false },
		message: { label: __('Project Details', 'openforms'), type: 'textarea', required: true, full: true }
	};

	var ALLOWED = ['core/heading', 'core/paragraph', 'core/group', 'core/stack', 'openforms/quote-field', 'core/buttons', 'core/button'];
	var TEMPLATE = [
		['core/heading', { level: 2, content: __('Get a Quote', 'openforms') }],
		['core/paragraph', { content: __('Tell us about your project and we will be in touch.', 'openforms') }],
		['openforms/quote-field', { key: 'name' }],
		['openforms/quote-field', { key: 'email' }],
		['openforms/quote-field', { key: 'phone' }],
		['openforms/quote-field', { key: 'budget' }],
		['openforms/quote-field', { key: 'message' }],
		['core/buttons', {}, [['core/button', { text: __('Request a Quote', 'openforms') }]]]
	];

	registerBlockType('openforms/get-quote', {
		title: __('Get a Quote', 'openforms'),
		description: __('A quote request form. Submissions are stored in the admin.', 'openforms'),
		category: 'text',
		icon: 'money',
		apiVersion: 3,
		supports: {
			align: ['wide', 'full'],
			color: { text: true, background: true, gradients: true, link: true },
			typography: { fontSize: true, lineHeight: true },
			spacing: { margin: true, padding: true },
			border: { color: true, width: true, style: true, radius: true },
			__experimentalBorder: { color: true, width: true, style: true, radius: true }
		},
		edit: function (props) {
			var blockProps = useBlockProps({ className: 'openforms-quote' });

			return el('div', blockProps,
				el(InnerBlocks, { allowedBlocks: ALLOWED, template: TEMPLATE, templateLock: false })
			);
		},
		save: function () {
			return el(InnerBlocks.Content);
		}
	});

	registerBlockType('openforms/quote-field', {
		title: __('Quote Field', 'openforms'),
		category: 'text',
		parent: ['openforms/get-quote'],
		apiVersion: 3,
		attributes: {
			key: { type: 'string', default: 'name' }
		},
		supports: {
			color: { text: true, background: true },
			typography: { fontSize: true },
			// The border support is serialized manually onto the input itself
			// (see edit below), so skip it on the label wrapper.
			border: { color: true, width: true, style: true, radius: true, __experimentalSkipSerialization: true },
			__experimentalBorder: { color: true, width: true, style: true, radius: true, __experimentalSkipSerialization: true },
			spacing: { padding: true }
		},
		edit: function (props) {
			var field = FIELDS[props.attributes.key] || FIELDS.name;
			var full = field.full ? ' openforms-quote__field--full' : '';
			var blockProps = useBlockProps({ className: 'openforms-quote__field' + full });
			// Border serialization is skipped on the wrapper so the border can
			// be applied to the input itself instead of the label wrapper.
			var borderProps = useBorderProps(props.attributes);
			var mark = field.required ? el('span', { className: 'required' }, ' *') : null;
			var inputProps = { disabled: true, style: borderProps.style };
			var inputClass = borderProps.className ? ' ' + borderProps.className : '';
			var input;

			if (field.type === 'textarea') {
				inputProps.className = 'openforms-quote__textarea' + inputClass;
				inputProps.rows = 4;
				input = el('textarea', inputProps);
			} else if (field.type === 'select') {
				inputProps.className = 'openforms-quote__select' + inputClass;
				input = el('select', inputProps, el('option', null, ''));
			} else {
				inputProps.type = field.type;
				inputProps.className = 'openforms-quote__input' + inputClass;
				input = el('input', inputProps);
			}

			return el('p', blockProps,
				el('label', { className: 'openforms-quote__label', htmlFor: 'openforms-quote-' + props.attributes.key }, field.label, mark),
				input
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
