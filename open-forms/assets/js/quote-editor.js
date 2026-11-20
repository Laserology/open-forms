/**
 * Get a Quote block editor. The heading, subheading and submit button are the
 * standard core heading, paragraph and button blocks, and the form is made of
 * generic field blocks, so editors can add, remove and reorder everything just
 * like any native WordPress block. Fields may also be placed inside a group,
 * row or column as long as that container sits inside the form.
 *
 * Every form gets a random ID and an editable name, and a virtual success block
 * that is always visible here but only rendered after a submission. The blocks
 * are rendered server-side by GetQuote.php, so the frontend markup always comes
 * from PHP.
 */
(function (wp) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var registerBlockType = wp.blocks.registerBlockType;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var RichText = wp.blockEditor.RichText;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useBorderProps = wp.blockEditor.__experimentalUseBorderProps || wp.blockEditor.useBorderProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useSelect = wp.data.useSelect;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl = wp.components.ToggleControl;
	var __ = wp.i18n.__;

	var settings = window.openformsQuote || {};
	var FIELD_TYPES = settings.fieldTypes || [{ value: 'text', label: __('One-line text', 'open-forms') }];
	var FIELD_LABELS = {};
	var SUCCESS = settings.successMessage || __('Thanks! Your request has been sent.', 'open-forms');

	FIELD_TYPES.forEach(function (type) {
		FIELD_LABELS[type.value] = type.label;
	});

	// Blocks that may sit between the form and its fields.
	var CONTAINERS = ['openforms/get-quote', 'core/group', 'core/column', 'core/stack'];

	var ALLOWED = ['core/heading', 'core/paragraph', 'core/group', 'core/stack', 'core/columns', 'core/column', 'openforms/quote-field', 'openforms/quote-success', 'core/buttons', 'core/button'];

	var TEMPLATE = [
		['core/heading', { level: 2, content: __('Get a Quote', 'open-forms') }],
		['core/paragraph', { content: __('Tell us about your project and we will be in touch.', 'open-forms') }],
		['openforms/quote-success'],
		['openforms/quote-field', { key: 'name', label: __('Name', 'open-forms'), type: 'text', required: true, preview: true }],
		['openforms/quote-field', { key: 'email', label: __('Email', 'open-forms'), type: 'email', required: true, preview: true }],
		['openforms/quote-field', { key: 'phone', label: __('Phone', 'open-forms'), type: 'tel' }],
		['openforms/quote-field', { key: 'message', label: __('Details', 'open-forms'), type: 'textarea', full: true }],
		['openforms/quote-field', { key: 'attachment', label: __('Attachment', 'open-forms'), type: 'file', full: true }],
		['core/buttons', {}, [['core/button', { text: __('Request a Quote', 'open-forms') }]]]
	];

	// Fields saved by the original quote block only stored a key.
	var LEGACY_FIELDS = {
		name: { type: 'text', label: __('Name', 'open-forms'), required: true },
		email: { type: 'email', label: __('Email', 'open-forms'), required: true, preview: true },
		phone: { type: 'tel', label: __('Phone', 'open-forms'), preview: true },
		budget: { type: 'select', label: __('Estimated Budget', 'open-forms'), preview: true },
		message: { type: 'textarea', label: __('Project Details', 'open-forms'), required: true, full: true },
		file: { type: 'file', label: __('File Upload', 'open-forms'), full: true }
	};

	function fieldType(attributes) {
		if (attributes.type && FIELD_LABELS[attributes.type]) {
			return attributes.type;
		}

		var legacy = LEGACY_FIELDS[attributes.key];

		return legacy ? legacy.type : 'text';
	}

	function fieldLabel(attributes) {
		if (attributes.label) {
			return attributes.label;
		}

		var legacy = LEGACY_FIELDS[attributes.key];

		return legacy ? legacy.label : (FIELD_LABELS[fieldType(attributes)] || __('Field', 'open-forms'));
	}

	function fieldRequired(attributes) {
		if (attributes.type) {
			return !!attributes.required;
		}

		var legacy = LEGACY_FIELDS[attributes.key];

		return legacy ? !!legacy.required : false;
	}

	function fieldFull(attributes) {
		if (attributes.type) {
			return !!attributes.full;
		}

		var legacy = LEGACY_FIELDS[attributes.key];

		return legacy ? !!legacy.full : false;
	}

	function fieldPreview(attributes) {
		if (attributes.type) {
			return !!attributes.preview;
		}

		var legacy = LEGACY_FIELDS[attributes.key];

		return legacy ? !!legacy.preview : false;
	}

	// Make the legacy defaults explicit before overriding one of them, so the
	// other defaults do not silently disappear on the server.
	function explicitAttributes(attributes, changes) {
		if (attributes.type) {
			return changes;
		}

		var next = { type: fieldType(attributes) };

		if (fieldRequired(attributes)) {
			next.required = true;
		}

		if (fieldFull(attributes)) {
			next.full = true;
		}

		if (fieldPreview(attributes)) {
			next.preview = true;
		}

		return Object.assign(next, changes);
	}

	// A short random key. Used for the form ID and for each new field key, so
	// renaming a field never changes where its submissions are stored.
	function randomId() {
		var bytes = new Uint8Array(8);

		if (window.crypto && window.crypto.getRandomValues) {
			window.crypto.getRandomValues(bytes);
		} else {
			for (var i = 0; i < bytes.length; i++) {
				bytes[i] = Math.floor(Math.random() * 256);
			}
		}

		return Array.prototype.map.call(bytes, function (byte) {
			return ('0' + byte.toString(16)).slice(-2);
		}).join('');
	}

	// Whether the block is nested anywhere inside a Get a Quote form.
	function useInsideForm(clientId) {
		return useSelect(function (select) {
			var editor = select('core/block-editor');
			var parents = editor.getBlockParents ? editor.getBlockParents(clientId) : [];

			return parents.some(function (id) {
				var block = editor.getBlock(id);
				return block && 'openforms/get-quote' === block.name;
			});
		}, [clientId]);
	}

	registerBlockType('openforms/get-quote', {
		title: __('Get a Quote', 'open-forms'),
		description: __('A quote request form. Submissions are stored in the admin.', 'open-forms'),
		category: 'text',
		icon: 'money',
		apiVersion: 3,
		attributes: {
			// Random ID generated on insert, used to group submissions.
			formId: { type: 'string', default: '' },
			// User editable name, shown as a prefix in the admin.
			formName: { type: 'string', default: '' }
		},
		providesContext: {
			'openforms/formId': 'formId'
		},
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
			var formId = props.attributes.formId;
			var formName = props.attributes.formName;

			// Give new forms their identity on first render. Existing forms keep
			// their stored ID, so submissions never move. The name is only
			// defaulted while the ID is created, so clearing it later sticks.
			useEffect(function () {
				if (!formId) {
					props.setAttributes({
						formId: randomId(),
						formName: formName || __('Quote form', 'open-forms')
					});
				}
			}, [formId, formName]);

			var inspector = el(InspectorControls, null,
				el(PanelBody, { title: __('Form', 'open-forms') },
					el(TextControl, {
						label: __('Form name', 'open-forms'),
						value: formName,
						placeholder: __('Quote form', 'open-forms'),
						help: __('Shown as a prefix on submissions in the admin.', 'open-forms'),
						onChange: function (value) {
							props.setAttributes({ formName: value });
						}
					}),
					el(TextControl, {
						label: __('Form ID', 'open-forms'),
						value: formId || '…',
						disabled: true,
						help: __('Generated automatically and used to group submissions.', 'open-forms')
					})
				)
			);

			return el(Fragment, null,
				inspector,
				el('div', blockProps,
					el(InnerBlocks, { allowedBlocks: ALLOWED, template: TEMPLATE, templateLock: false })
				)
			);
		},
		save: function () {
			return el(InnerBlocks.Content);
		}
	});

	registerBlockType('openforms/quote-field', {
		title: __('Quote Field', 'open-forms'),
		description: __('A field inside a Get a Quote form.', 'open-forms'),
		category: 'text',
		icon: 'forms',
		parent: CONTAINERS,
		apiVersion: 3,
		attributes: {
			key: { type: 'string', default: '' },
			label: { type: 'string', default: '' },
			type: { type: 'string', default: '' },
			placeholder: { type: 'string', default: '' },
			default: { type: 'string', default: '' },
			required: { type: 'boolean', default: false },
			full: { type: 'boolean', default: false },
			preview: { type: 'boolean', default: false },
			options: { type: 'array', default: [], items: { type: 'string' } }
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
		usesContext: ['openforms/formId'],
		edit: function (props) {
			var key = props.attributes.key;
			var type = fieldType(props.attributes);
			var defaultValue = props.attributes.default || '';
			var defaultPlaceholder = 'color' === type ? '#000000' : ('date' === type ? 'YYYY-MM-DD' : ('time' === type ? 'HH:MM' : undefined));
			var insideForm = useInsideForm(props.clientId);

			useEffect(function () {
				if (!key) {
					props.setAttributes({ key: randomId() });
				}
			}, [key]);

			// Border serialization is skipped on the wrapper so the border can
			// be applied to the input itself instead of the label wrapper.
			var blockProps = useBlockProps({ className: 'openforms-quote__field' + (fieldFull(props.attributes) ? ' openforms-quote__field--full' : '') });
			var borderProps = useBorderProps(props.attributes);
			var inputClass = borderProps.className ? ' ' + borderProps.className : '';
			var inputProps = { disabled: true, style: borderProps.style };
			var input;

			if ('textarea' === type) {
				inputProps.className = 'openforms-quote__textarea' + inputClass;
				inputProps.rows = 4;
				inputProps.value = defaultValue;
				input = el('textarea', inputProps);
			} else if ('select' === type) {
				inputProps.className = 'openforms-quote__select' + inputClass;
				inputProps.value = defaultValue;
				input = el('select', inputProps,
					el('option', { value: '' }, __('Select an option…', 'open-forms')),
					(props.attributes.options || []).filter(function (option) {
						return option && option.trim();
					}).map(function (option) {
						return el('option', { key: option, value: option }, option);
					})
				);
			} else if ('file' === type) {
				// Mirrors the frontend: a themed button labels the hidden input.
				input = el('span', { className: 'openforms-quote__file' },
					el('span', { className: 'openforms-quote__file-button wp-block-button__link wp-element-button' + inputClass, style: borderProps.style }, __('Choose files', 'open-forms'))
				);
			} else {
				inputProps.type = type;
				inputProps.className = 'openforms-quote__input' + inputClass;
				inputProps.placeholder = props.attributes.placeholder || undefined;
				inputProps.value = defaultValue;
				input = el('input', inputProps);
			}

			var title = fieldLabel(props.attributes);
			var mark = fieldRequired(props.attributes) ? el('span', { className: 'required' }, ' *') : null;

			var inspector = el(InspectorControls, null,
				el(PanelBody, { title: __('Field', 'open-forms') },
					el(TextControl, {
						label: __('Title', 'open-forms'),
						value: props.attributes.label,
						placeholder: FIELD_LABELS[type] || __('Field', 'open-forms'),
						onChange: function (label) {
							props.setAttributes({ label: label });
						}
					}),
					el(SelectControl, {
						label: __('Field type', 'open-forms'),
						value: type,
						options: FIELD_TYPES.map(function (option) {
							return { label: option.label, value: option.value };
						}),
						onChange: function (value) {
							props.setAttributes(explicitAttributes(props.attributes, { type: value }));
						}
					}),
					'select' === type ? el(TextareaControl, {
						label: __('Options', 'open-forms'),
						value: (props.attributes.options || []).join('\n'),
						help: __('One option per line.', 'open-forms'),
						onChange: function (value) {
							props.setAttributes({ options: value.split('\n') });
						}
					}) : null,
					'file' !== type && 'select' !== type ? el(TextControl, {
						label: __('Placeholder', 'open-forms'),
						value: props.attributes.placeholder,
						onChange: function (value) {
							props.setAttributes({ placeholder: value });
						}
					}) : null,
					'file' !== type ? ('select' === type ? el(SelectControl, {
						label: __('Default value', 'open-forms'),
						value: defaultValue,
						options: [{ label: __('None', 'open-forms'), value: '' }].concat((props.attributes.options || []).filter(function (option) {
							return option && option.trim();
						}).map(function (option) {
							return { label: option, value: option };
						})),
						onChange: function (value) {
							props.setAttributes({ default: value });
						}
					}) : el(TextControl, {
						label: __('Default value', 'open-forms'),
						value: props.attributes.default,
						placeholder: defaultPlaceholder,
						help: __('Shown before the visitor changes the field.', 'open-forms'),
						onChange: function (value) {
							props.setAttributes({ default: value });
						}
					})) : null,
					el(ToggleControl, {
						label: __('Required', 'open-forms'),
						checked: fieldRequired(props.attributes),
						onChange: function (value) {
							// Keep the legacy defaults explicit when an older field is edited.
							props.setAttributes(explicitAttributes(props.attributes, { required: value }));
						}
					}),
					el(ToggleControl, {
						label: __('Full width', 'open-forms'),
						checked: fieldFull(props.attributes),
						onChange: function (value) {
							props.setAttributes(explicitAttributes(props.attributes, { full: value }));
						}
					}),
					el(ToggleControl, {
						label: __('Show in admin preview', 'open-forms'),
						help: __('Show this value in the quick preview on the quotes screen.', 'open-forms'),
						checked: fieldPreview(props.attributes),
						onChange: function (value) {
							props.setAttributes(explicitAttributes(props.attributes, { preview: value }));
						}
					}),
					!insideForm ? el('p', { className: 'openforms-quote__field-warning' }, __('This field only renders inside a Get a Quote form.', 'open-forms')) : null
				)
			);

			return el(Fragment, null,
				inspector,
				el('p', blockProps,
					el('label', { className: 'openforms-quote__label', htmlFor: 'openforms-quote-' + (key || 'field') }, title, mark),
					input
				)
			);
		},
		save: function () {
			return null;
		}
	});

	registerBlockType('openforms/quote-success', {
		title: __('Success Message', 'open-forms'),
		description: __('Shown after the form is submitted successfully.', 'open-forms'),
		category: 'text',
		icon: 'yes-alt',
		parent: CONTAINERS,
		apiVersion: 3,
		attributes: {
			message: { type: 'string', default: SUCCESS }
		},
		usesContext: ['openforms/formId'],
		supports: {
			color: { text: true, background: true, gradients: true },
			typography: { fontSize: true, lineHeight: true },
			spacing: { margin: true, padding: true },
			border: { color: true, width: true, style: true, radius: true },
			__experimentalBorder: { color: true, width: true, style: true, radius: true }
		},
		edit: function (props) {
			var blockProps = useBlockProps({ className: 'openforms-quote__notice openforms-quote__notice--success' });
			var insideForm = useInsideForm(props.clientId);

			return el(Fragment, null,
				el(InspectorControls, null,
					el(PanelBody, { title: __('Success message', 'open-forms') },
						el(TextareaControl, {
							label: __('Message', 'open-forms'),
							value: props.attributes.message,
							onChange: function (value) {
								props.setAttributes({ message: value });
							}
						}),
						!insideForm ? el('p', { className: 'openforms-quote__field-warning' }, __('This message only renders inside a Get a Quote form.', 'open-forms')) : null
					)
				),
				el(RichText, Object.assign({ tagName: 'p' }, blockProps, {
					value: props.attributes.message,
					placeholder: SUCCESS,
					onChange: function (value) {
						props.setAttributes({ message: value });
					}
				}))
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
