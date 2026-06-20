/**
 * em/event-when — inline date/time/recurrence canvas block.
 *
 * Renders the EM "When" and "Recurrences" classic-metabox HTML directly in the
 * Gutenberg editor canvas, pinned below the title. The classic metaboxes are
 * hidden via CSS (they stay in the DOM so Gutenberg's meta-box-loader POST can
 * still serialize them). Before each save, the validation guard calls
 * window.emSyncCanvasToMetabox() to mirror canvas-block inputs into the hidden
 * metaboxes. After each save, window.emReloadWhenBlock() re-fetches the server
 * HTML so nonces and record IDs stay fresh — the same URL that reloadEMMetaBoxes()
 * already uses.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useEffect, useRef } from '@wordpress/element';
import metadata from './block.json';

const BLOCK_CLASS = 'em-event-when-block';

function EventWhenEdit() {
	const containerRef = useRef( null );

	function loadContent() {
		const container = containerRef.current;
		if ( ! container ) return;

		const url = window._wpMetaBoxUrl;
		if ( ! url ) return;

		fetch( url, { credentials: 'same-origin' } )
			.then( ( r ) => r.text() )
			.then( ( html ) => {
				const doc = new DOMParser().parseFromString( html, 'text/html' );

				const whenInside  = doc.getElementById( 'em-event-when' )?.querySelector( '.inside' );
				const recurInside = doc.getElementById( 'em-event-recurring' )?.querySelector( '.inside' );

				if ( typeof window.em_unsetup_ui_elements === 'function' ) {
					try { window.em_unsetup_ui_elements( container ); } catch ( _ ) {}
				}

				container.innerHTML = '';

				if ( whenInside ) {
					container.insertAdjacentHTML( 'beforeend', whenInside.innerHTML );
				}
				if ( recurInside ) {
					// Wrap recurrences in a div so the recurring-section CSS from the
					// classic metabox context still applies (it targets .em-recurrence-sets).
					container.insertAdjacentHTML( 'beforeend', '<div class="em-recurrences-section">' + recurInside.innerHTML + '</div>' );
				}

				if ( typeof window.em_setup_ui_elements === 'function' ) {
					try { window.em_setup_ui_elements( container ); } catch ( _ ) {}
				}
			} )
			.catch( ( e ) => {
				// eslint-disable-next-line no-console
				console.error( '[EM] event-when block: fetch failed', e );
			} );
	}

	useEffect( () => {
		loadContent();

		// Expose refresh hook — called by reloadEMMetaBoxes() in the validation
		// guard after each save so nonces and record IDs stay current.
		window.emReloadWhenBlock = loadContent;

		return () => {
			window.emReloadWhenBlock = null;
			const container = containerRef.current;
			if ( container && typeof window.em_unsetup_ui_elements === 'function' ) {
				try { window.em_unsetup_ui_elements( container ); } catch ( _ ) {}
			}
		};
	}, [] );

	return (
		<div className={ `${ BLOCK_CLASS } em` } ref={ containerRef } />
	);
}

registerBlockType( metadata.name, {
	edit: EventWhenEdit,
	save: () => null,
} );
