/**
 * Editor script for the nrwp/data block.
 *
 * Written against the `wp` globals so the plugin works without a build step.
 */
( function ( blocks, blockEditor, components, element, i18n, ServerSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var Placeholder = components.Placeholder;

	blocks.registerBlockType( 'nrwp/data', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			function textControl( name, label, help ) {
				return el( TextControl, {
					label: label,
					help: help,
					value: attributes[ name ],
					onChange: function ( value ) {
						var update = {};
						update[ name ] = value;
						setAttributes( update );
					},
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				} );
			}

			var keyControl = textControl(
				'dataKey',
				__( 'Data key', 'node-red-wp' ),
				__( 'The key Node-RED writes to, e.g. "temperature".', 'node-red-wp' )
			);

			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Data point', 'node-red-wp' ) },
					el( 'div', { style: { display: 'grid', gap: '16px' } },
						keyControl,
						textControl( 'title', __( 'Title', 'node-red-wp' ) ),
						textControl( 'unit', __( 'Unit', 'node-red-wp' ), __( 'Shown after the value, e.g. "°C".', 'node-red-wp' ) ),
						textControl( 'fallback', __( 'Fallback text', 'node-red-wp' ), __( 'Shown while the key has no value.', 'node-red-wp' ) )
					)
				)
			);

			var content = attributes.dataKey
				? el( ServerSideRender, { block: 'nrwp/data', attributes: attributes, skipBlockSupportAttributes: true } )
				: el(
					Placeholder,
					{
						icon: 'rss',
						label: __( 'Node-RED Data', 'node-red-wp' ),
						instructions: __( 'Enter the data key to display.', 'node-red-wp' ),
					},
					keyControl
				);

			return el( 'div', useBlockProps(), inspector, content );
		},

		// Rendered on the server.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n,
	window.wp.serverSideRender
);
