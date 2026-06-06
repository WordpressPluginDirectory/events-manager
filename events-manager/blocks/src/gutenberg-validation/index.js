/**
 * Events Manager — Gutenberg validation guard (ACF-style click intercept).
 *
 * Why: EM's date/time/location data lives in the classic metabox and is NOT
 * sent in Gutenberg's REST save payload. The metabox is submitted in a
 * separate POST to post.php AFTER the REST save succeeds — by then the post
 * is already published and any validation failure can only demote it to draft,
 * which is the UX the user is trying to avoid.
 *
 * Pattern (adapted from ACF, see Gutenberg issue #12692):
 *   1. Intercept clicks on the Publish/Update button in the CAPTURE phase, so
 *      React's bubble-phase handler never runs.
 *   2. Snapshot the classic metabox form (#post) via jQuery serialize() —
 *      captures every metabox value including EM's dates, location, _emnonce.
 *   3. POST the snapshot to /events-manager/v1/blocks/event/validate, which
 *      rehydrates $_POST and runs EM's validate() pipeline against the
 *      editor's CURRENT state (not the DB state).
 *   4. If invalid: createErrorNotice, close publish sidebar, leave editor
 *      untouched — no save, no demotion, clear inline feedback.
 *   5. If valid: dispatch savePost() programmatically; Gutenberg saves as
 *      normal and the metabox AJAX follow-up writes the meta.
 *
 * We listen at document level with capture: true because Gutenberg / React
 * attach their handlers below document, and we need to preventDefault BEFORE
 * React's save-action dispatcher runs.
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import domReady from '@wordpress/dom-ready';

const NOTICE_ID    = 'em-validation';
const LOCK_KEY     = 'em-validation';
const ENDPOINT     = '/events-manager/v1/blocks/event/validate';
const EM_POST_TYPES = [ 'event', 'location', 'event-recurring' ];

/**
 * Debug logging — opt-in via either:
 *   • URL query string:  ?em_debug=1
 *   • localStorage:      localStorage.setItem('em_validation_debug', '1')
 *
 * When on, every interception logs the container scan results, the serialised
 * form data, and the endpoint response under the [em-validation] prefix.
 */
const DEBUG = (
	( typeof window !== 'undefined' && /[?&]em_debug=1\b/.test( window.location.search ) ) ||
	( typeof window !== 'undefined' && window.localStorage && window.localStorage.getItem( 'em_validation_debug' ) === '1' )
);
function dbg( ...args ) {
	if ( DEBUG ) {
		// eslint-disable-next-line no-console
		console.log( '[em-validation]', ...args );
	}
}

/**
 * Whether the current editor is editing an EM CPT.
 *
 * Looked up lazily (per-click) rather than at load time because the editor
 * data store may not be populated by domReady().
 */
function isEMPostType() {
	const editorRO = select( 'core/editor' );
	if ( ! editorRO || typeof editorRO.getCurrentPostType !== 'function' ) {
		return false;
	}
	return EM_POST_TYPES.includes( editorRO.getCurrentPostType() );
}

/**
 * Serialise the classic editor form so the validation endpoint sees every
 * metabox field — including ones whose JS-driven date pickers update via
 * setAttribute('value', …) rather than the live .value property (EM's
 * flatpickr does this).
 *
 * Strategy: scan a set of candidate containers for every named input/select/
 * textarea, read each element's current value falling back to its `value`
 * attribute, then URL-encode into the same shape jQuery.serialize() produces.
 *
 * The containers list is broad so Gutenberg DOM changes between WP versions
 * don't silently drop fields. Duplicates (same input matched by multiple
 * containers) are de-duped by element identity.
 */
function serialiseClassicForm() {
	// Scan a generous list of containers so we catch BOTH the normal and side
	// metabox areas Gutenberg renders (EM's "When" metabox — which holds the
	// dates and _emnonce — is registered in 'side' context, and earlier scans
	// with querySelector only matched the first .edit-post-meta-boxes-area
	// element, missing the side one).
	//
	// We use querySelectorAll for selectors and accept multiple matches.
	// Final fallback: document.body, so even if Gutenberg restructures the
	// DOM in a future version we still see every named input on the page.
	const containers = [];

	const byId = document.getElementById( 'post' );
	if ( byId ) {
		containers.push( byId );
		dbg( 'container #post FOUND' );
	} else {
		dbg( 'container #post missing' );
	}

	const selectorList = [
		'.edit-post-meta-boxes-area',           // matches BOTH normal and is-side
		'.edit-post-layout__metaboxes',
		'#metaboxes',
		'#poststuff',
	];
	for ( const sel of selectorList ) {
		const matches = document.querySelectorAll( sel );
		dbg( 'container', sel, matches.length );
		matches.forEach( ( el ) => containers.push( el ) );
	}

	// Last-resort fallback so we never silently miss inputs.
	containers.push( document.body );

	const seen = new Set();
	const params = new URLSearchParams();
	const debugFields = [];

	for ( const root of containers ) {
		const fields = root.querySelectorAll(
			'input[name]:not([disabled]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="file"]), select[name]:not([disabled]), textarea[name]:not([disabled])'
		);

		for ( const el of fields ) {
			if ( seen.has( el ) ) continue;
			seen.add( el );

			const type = ( el.type || '' ).toLowerCase();

			// Skip unchecked checkboxes/radios — they shouldn't serialise.
			if ( ( type === 'checkbox' || type === 'radio' ) && ! el.checked ) {
				continue;
			}

			// Read the live value, falling back to the attribute if the live
			// value is empty (covers the flatpickr setAttribute('value', …)
			// case where the property hasn't caught up).
			let value = el.value;
			if ( value === '' || value == null ) {
				const attr = el.getAttribute( 'value' );
				if ( attr ) value = attr;
			}

			if ( type === 'select-multiple' && el.selectedOptions ) {
				for ( const opt of el.selectedOptions ) {
					params.append( el.name, opt.value );
				}
				if ( DEBUG ) {
					debugFields.push( {
						name: el.name,
						type,
						value: [ ...el.selectedOptions ].map( ( o ) => o.value ).join( ',' ),
					} );
				}
				continue;
			}

			params.append( el.name, value == null ? '' : value );

			if ( DEBUG && (
				el.name.startsWith( 'event_' ) ||
				el.name.startsWith( 'location_' ) ||
				el.name === '_emnonce' ||
				el.name.startsWith( '_em' )
			) ) {
				debugFields.push( {
					name: el.name,
					type,
					prop: el.value,
					attr: el.getAttribute( 'value' ),
					used: value,
				} );
			}
		}
	}

	dbg( 'EM-relevant fields:', debugFields );

	const serialized = params.toString();
	dbg( 'serialised form_data length:', serialized.length, '— preview:', serialized.slice( 0, 400 ) );

	return serialized;
}

/**
 * The main click interceptor. Runs in capture phase on document so it fires
 * before any React-attached bubble-phase listener.
 */
function onPublishClick( e ) {
	// Match the actual save-triggering button: the sidebar's confirm-Publish
	// button OR the Update button when the post is already published. The
	// toolbar "Publish" button that just opens the sidebar uses a different
	// class (editor-post-publish-panel__toggle) and is intentionally not matched.
	const btn = e.target.closest && e.target.closest( '.editor-post-publish-button' );
	if ( ! btn ) {
		return;
	}

	if ( ! isEMPostType() ) {
		return;
	}

	e.preventDefault();
	e.stopPropagation();

	const editor    = dispatch( 'core/editor' );
	const notices   = dispatch( 'core/notices' );
	const editorRO  = select( 'core/editor' );

	// Publish-sidebar selectors/dispatchers moved from core/edit-post to
	// core/editor in WP 6.6. Prefer the new location, fall back to the legacy
	// one for older installs.
	const isSidebarOpen = () => {
		if ( typeof editorRO.isPublishSidebarOpened === 'function' ) {
			return editorRO.isPublishSidebarOpened();
		}
		const legacy = select( 'core/edit-post' );
		return legacy && typeof legacy.isPublishSidebarOpened === 'function'
			? legacy.isPublishSidebarOpened()
			: false;
	};
	const closeSidebar = () => {
		if ( typeof editor.closePublishSidebar === 'function' ) {
			editor.closePublishSidebar();
			return;
		}
		const legacy = dispatch( 'core/edit-post' );
		if ( legacy && typeof legacy.closePublishSidebar === 'function' ) {
			legacy.closePublishSidebar();
		}
	};

	editor.lockPostSaving( LOCK_KEY );
	notices.removeNotice( NOTICE_ID );

	const requestBody = {
		post_id:   editorRO.getCurrentPostId() || 0,
		post_type: editorRO.getCurrentPostType(),
		form_data: serialiseClassicForm(),
	};
	dbg( 'POST → ' + ENDPOINT, { post_id: requestBody.post_id, post_type: requestBody.post_type } );

	apiFetch( {
		path:   ENDPOINT,
		method: 'POST',
		data:   requestBody,
	} )
		.then( ( response ) => {
			dbg( 'response:', response );
			editor.unlockPostSaving( LOCK_KEY );

			// Unconditional diagnostic when validation fails: helps users
			// (and us) figure out why the server rejected the snapshot without
			// needing to toggle ?em_debug=1 first. Always shows what the
			// endpoint saw vs. expected, with no PII.
			if ( response && response.valid === false ) {
				// eslint-disable-next-line no-console
				console.warn(
					'[em-validation] save blocked — server rejected snapshot.\n' +
					'Errors:\n  - ' +
					( response.errors || [] ).map( ( e ) => e.message || e.code || '?' ).join( '\n  - ' ) +
					'\nform_data length: ' + ( requestBody.form_data || '' ).length +
					'\nEM-relevant fields sent: ' +
					( requestBody.form_data || '' )
						.split( '&' )
						.filter( ( kv ) => /^(event_|location_|_em)/.test( kv ) )
						.join( '\n  ' )
				);
			}

			if ( response && response.valid ) {
				notices.removeNotice( NOTICE_ID );

				// If we were publishing from a draft via the sidebar, also flip
				// the status so the dispatched savePost() actually publishes.
				if (
					isSidebarOpen() &&
					! editorRO.isCurrentPostPublished() &&
					editorRO.getEditedPostAttribute( 'status' ) === 'draft'
				) {
					editor.editPost( { status: 'publish' } );
				}

				editor.savePost();
				return;
			}

			// Validation failed — surface the errors and stop. Leaving the
			// publish lock OFF lets the user click Publish again after fixing
			// fields, which re-enters this handler.
			const headline = __(
				'This event has validation errors and cannot be published until they are fixed:',
				'events-manager'
			);
			const body = ( ( response && response.errors ) || [] )
				.map( ( err ) => ( err && err.message ) || '' )
				.filter( Boolean )
				.join( ' ' );

			notices.createErrorNotice( `${ headline } ${ body }`.trim(), {
				id: NOTICE_ID,
				isDismissible: true,
			} );

			// Close the publish sidebar so the editor canvas (and the error
			// notice in it) is visible — matches ACF's behaviour.
			if ( isSidebarOpen() ) {
				closeSidebar();
			}
		} )
		.catch( () => {
			// Don't trap the user behind a flaky endpoint — warn and proceed
			// with the default save. EM's classic save_post pipeline will still
			// catch invalid data and demote to draft as the legacy fallback.
			notices.createErrorNotice(
				__(
					'Could not check event validation. Saving without pre-check — please verify the event details.',
					'events-manager'
				),
				{ id: NOTICE_ID, isDismissible: true }
			);
			editor.unlockPostSaving( LOCK_KEY );
			editor.savePost();
		} );
}

domReady( () => {
	// Capture phase: fires before React's bubble-phase listeners so the
	// preventDefault / stopPropagation actually keep Gutenberg from saving.
	document.addEventListener( 'click', onPublishClick, true );

	// Recurring metabox visibility sync — see syncRecurringMetabox().
	startRecurringMetaboxSync();
} );

/**
 * Recurring metabox visibility sync (Gutenberg-aware).
 *
 * EM's events-manager-event-editor.css gates the Recurrences metabox on the
 * post form carrying .em-is-recurring:
 *
 *   .em form:not(.em-is-recurring) .postbox#em-event-recurring { display:none }
 *
 * In classic admin, includes/js/src/parts/event-editor.js toggles that class
 * by reading the [name="event_type"] field and adding a change listener.
 * That JS is enqueued via EM's lazy asset loader keyed on `.em-event-editor`
 * being on body — which DOES fire in Gutenberg, but the timing relative to
 * Gutenberg's metabox-area mounting is unreliable: by the time event_type
 * lands in the DOM, the editor-ready listener may have already fired against
 * an empty form, or the metabox area's React reconciliation may strip the
 * class set by JS.
 *
 * This routine does the same job but Gutenberg-native:
 *   1. On page load, find [name="event_type"], sync class to form#post.
 *   2. Watch the field for `change` events to keep the class accurate.
 *   3. Use a MutationObserver scoped to the metabox container, because the
 *      field may not exist at domReady — Gutenberg lazy-mounts metaboxes.
 */
function isRecurringValue( field ) {
	if ( ! field ) return false;
	if ( field.type === 'checkbox' ) {
		return field.checked;
	}
	const v = ( field.value || '' ).toLowerCase();
	return v === 'recurring' || v === 'repeating';
}

function syncRecurringMetabox() {
	// Gutenberg renders metaboxes across MULTIPLE forms (one per location:
	// metabox-location-normal / -side / -advanced / metabox-base-form). There
	// is no #post form like in the classic editor, so we can't just target a
	// single element. The hide rule keys on the form WRAPPING the metabox, so
	// the class needs to land on the form that contains #em-event-recurring
	// (and also on any sibling metabox-location-* forms that contain the
	// other recurring-gated bits: .recurring-event-editor, .recurring-event-data).
	const metabox = document.getElementById( 'em-event-recurring' );
	if ( ! metabox ) return;

	const editorRO = select( 'core/editor' );
	const cpt = editorRO && typeof editorRO.getCurrentPostType === 'function'
		? editorRO.getCurrentPostType()
		: '';

	// Repeating-template CPTs (event-recurring, event-<archetype>-recurring,
	// event-<archetype>-repeating) are intrinsically recurring per
	// Archetypes::is_repeating() — the Recurrences metabox must always show.
	let isRecurring;
	if ( /-recurring$|-repeating$/.test( cpt ) ) {
		isRecurring = true;
	} else {
		// Regular event CPT: read [name="event_type"].
		isRecurring = isRecurringValue( document.querySelector( '[name="event_type"]' ) );
	}

	// Apply to every metabox-location-* form in the editor — they're all
	// candidates for hosting recurring-gated content, and the existing CSS
	// rule applies per-form. Cheap; there's typically 3–4 of these.
	const forms = document.querySelectorAll( 'form.metabox-location-normal, form.metabox-location-side, form.metabox-location-advanced, form.metabox-base-form, form#post' );
	forms.forEach( ( f ) => f.classList.toggle( 'em-is-recurring', isRecurring ) );
}

let syncTimer = null;
function scheduleSync() {
	if ( syncTimer ) return;
	syncTimer = setTimeout( () => {
		syncTimer = null;
		syncRecurringMetabox();
	}, 50 );
}

function startRecurringMetaboxSync() {
	// Initial pass (the field may not be in the DOM yet — that's fine, the
	// MutationObserver below catches the late mount).
	syncRecurringMetabox();

	// Delegated change listener — covers any future remount of the event_type
	// field (Gutenberg may re-render the metabox iframe; React may replace
	// children of #post). Single listener at document level.
	document.addEventListener( 'change', ( e ) => {
		if ( e.target && e.target.matches && e.target.matches( '[name="event_type"]' ) ) {
			syncRecurringMetabox();
		}
	}, true );

	// Watch for the event_type field appearing later. We scope the observer
	// to the metabox area when possible, falling back to body. The debounced
	// scheduleSync() avoids running on every minor mutation.
	const root = document.getElementById( 'metaboxes' )
		|| document.getElementById( 'post' )
		|| document.body;
	if ( typeof MutationObserver === 'function' && root ) {
		const observer = new MutationObserver( ( muts ) => {
			for ( const m of muts ) {
				for ( const node of m.addedNodes || [] ) {
					if ( ! ( node instanceof Element ) ) continue;
					if (
						node.matches?.( '[name="event_type"]' ) ||
						node.querySelector?.( '[name="event_type"]' )
					) {
						scheduleSync();
						return;
					}
				}
			}
		} );
		observer.observe( root, { childList: true, subtree: true } );
	}
}
