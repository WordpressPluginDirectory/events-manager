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
import { dispatch, select, subscribe } from '@wordpress/data';
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

	// Also read from the em/event-when canvas block inside the editor iframe.
	// Gutenberg 6.6+ renders block content in iframe[name="editor-canvas"], so
	// document.querySelector can't reach it. The canvas block has the user's
	// current (live, non-disabled) values and is the authoritative source for
	// date/time/recurrence data when it is present.
	const editorFrame = document.querySelector( 'iframe[name="editor-canvas"]' );
	const canvasBlock = editorFrame?.contentDocument?.querySelector( '.em-event-when-block' );
	if ( canvasBlock ) {
		containers.push( canvasBlock );
		dbg( 'container .em-event-when-block (iframe) FOUND' );
	}

	const seen = new Set();
	const params = new URLSearchParams();
	const debugFields = [];

	for ( const root of containers ) {
		const fields = root.querySelectorAll(
			// Disabled fields are intentionally included: saved recurring events
			// disable their primary recurrence inputs in the classic metabox HTML,
			// causing `:not([disabled])` to silently drop the times and trigger a
			// false "Main recurrence set times are required" validation error on
			// second save. The canvas block always has enabled inputs (it re-fetches
			// fresh HTML each load), so including disabled fields here is harmless
			// when the canvas is the container, and necessary for the metabox fallback.
			'input[name]:not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="file"]), select[name], textarea[name]'
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

				// Mirror canvas-block inputs to hidden metaboxes before the
				// meta-box-loader POST serialises them.
				syncAllCanvasToMetabox();

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
			syncAllCanvasToMetabox();
			editor.unlockPostSaving( LOCK_KEY );
			editor.savePost();
		} );
}

/* -----------------------------------------------------------------
 * Post-save metabox reload.
 *
 * Gutenberg mounts the classic-metabox HTML exactly once, on initial editor
 * load, and never re-renders it from the server afterwards — on each save it
 * only POSTs the metabox forms to the meta-box-loader endpoint to PERSIST them,
 * then discards the (refreshed) HTML the server returns. EM's metaboxes are
 * stateful in ways the DOM therefore never learns about after that first mount:
 * the server mints record IDs and per-record nonces during the save that the
 * frozen form never receives.
 *
 * The sharp edge is recurring events. A brand-new recurring event's recurrence
 * form renders with an empty recurrence_set_id and its pattern fields enabled.
 * The first save creates the recurrence set server-side and assigns it an id —
 * but the DOM form still shows the empty id. On the NEXT save EM reads that
 * stale form, treats the empty-id row as a brand-new set (creating a duplicate
 * alongside the real one), and validation rejects it with "Main recurrence set
 * times are required." From the user's seat: create a repeating event, save, try
 * to save again → validation errors out of nowhere.
 *
 * Fix: after a save (and its metabox-loader follow-up) completes, re-fetch the
 * rendered metabox HTML from the exact endpoint WordPress itself uses on initial
 * mount (window._wpMetaBoxUrl) and swap the .inside of every EM postbox, then
 * re-run EM's UI initialisation so Selectize / flatpickr / timepickers rebind to
 * the fresh nodes. This gives every EM metabox the post-save recurrence_set_id,
 * record ids and nonces — exactly what a classic-editor page reload would.
 * ----------------------------------------------------------------- */

/**
 * Mirror every named input from the em/event-when canvas block into the hidden
 * classic metaboxes so Gutenberg's meta-box-loader POST serialises the values
 * the user actually edited. Called right before editor.savePost().
 *
 * For inputs that already exist in the metabox: copy the value (and un-disable
 * if needed, inserting a shadow hidden input). For inputs that don't exist yet
 * (e.g. a newly-added recurrence set): append a hidden input to the appropriate
 * metabox .inside so it ends up in the serialised form.
 */
function syncAllCanvasToMetabox() {
	// The canvas block renders inside iframe[name="editor-canvas"] in Gutenberg
	// 6.6+. document.querySelector can't reach across document boundaries, so we
	// must look inside the iframe's contentDocument explicitly.
	const editorFrame = document.querySelector( 'iframe[name="editor-canvas"]' );
	const canvas = editorFrame?.contentDocument?.querySelector( '.em-event-when-block' )
	               || document.querySelector( '.em-event-when-block' ); // fallback: non-iframed context
	if ( ! canvas ) {
		return;
	}

	canvas.querySelectorAll( 'input[name], select[name], textarea[name]' ).forEach( ( input ) => {
		const name = input.name;
		if ( ! name ) {
			return;
		}
		// Skip non-data controls.
		if ( input.type === 'submit' || input.type === 'button' || input.type === 'reset' || input.type === 'file' ) {
			return;
		}
		// Unchecked checkboxes/radios don't submit — skip them.
		if ( ( input.type === 'checkbox' || input.type === 'radio' ) && ! input.checked ) {
			return;
		}

		const escaped = CSS.escape( name );
		const existing = document.querySelector(
			`#em-event-when [name="${ escaped }"], #em-event-recurring [name="${ escaped }"]`
		);

		const getValue = ( el ) => {
			if ( el.type === 'checkbox' || el.type === 'radio' ) {
				return el.checked ? el.value : null;
			}
			if ( el.tagName === 'SELECT' && el.multiple ) {
				return Array.from( el.selectedOptions ).map( ( o ) => o.value );
			}
			return el.value;
		};

		const val = getValue( input );

		if ( existing ) {
			if ( existing.type === 'checkbox' || existing.type === 'radio' ) {
				existing.checked = input.checked;
			} else if ( Array.isArray( val ) ) {
				Array.from( existing.options || [] ).forEach( ( opt ) => {
					opt.selected = val.includes( opt.value );
				} );
			} else {
				existing.value = val ?? '';
			}
			// If canvas field is enabled but metabox field is disabled, the
			// serialiser skips it. Shadow it with an enabled hidden input.
			if ( ! input.disabled && existing.disabled ) {
				const shadow = document.createElement( 'input' );
				shadow.type  = 'hidden';
				shadow.name  = name;
				shadow.value = Array.isArray( val ) ? val.join( ',' ) : ( val ?? '' );
				existing.insertAdjacentElement( 'afterend', shadow );
			}
		} else {
			// New input not present in the hidden metabox (e.g. dynamically
			// added recurrence set). Append a hidden copy so it gets POSTed.
			const targetInside = /^recurrences/.test( name )
				? document.querySelector( '#em-event-recurring .inside' )
				: document.querySelector( '#em-event-when .inside' );
			if ( targetInside ) {
				const hidden = document.createElement( 'input' );
				hidden.type  = 'hidden';
				hidden.name  = name;
				hidden.value = Array.isArray( val ) ? val.join( ',' ) : ( val ?? '' );
				targetInside.appendChild( hidden );
			}
		}
	} );

	dbg( 'canvas → metabox sync complete' );
}

/**
 * Insert the em/event-when block at position 0 if it isn't already in the post.
 * Retries up to ~5 seconds to allow Gutenberg's block-editor store to populate.
 * Called once on domReady.
 */
function ensureWhenBlock() {
	if ( ! isEMPostType() ) {
		return;
	}

	const MAX_ATTEMPTS = 25;
	let attempts = 0;

	const tryInsert = () => {
		attempts++;

		const blockEditorSelect = select( 'core/block-editor' );
		if ( ! blockEditorSelect || typeof blockEditorSelect.getBlocks !== 'function' ) {
			if ( attempts < MAX_ATTEMPTS ) {
				setTimeout( tryInsert, 200 );
			}
			return;
		}

		const existing = blockEditorSelect.getBlocks().find( ( b ) => b.name === 'em/event-when' );
		if ( existing ) {
			return; // already present
		}

		const createBlock = window.wp?.blocks?.createBlock;
		if ( ! createBlock ) {
			return;
		}

		const block = createBlock( 'em/event-when' );
		// Lock: prevent the user from accidentally removing or moving the block.
		block.attributes = { ...block.attributes, lock: { move: true, remove: true } };

		dispatch( 'core/block-editor' ).insertBlocks( block, 0 );
		dbg( 'em/event-when block injected at position 0' );
	};

	setTimeout( tryInsert, 300 );
}

let emMetaBoxReloadInFlight = false;

function reloadEMMetaBoxes() {
	const url = typeof window !== 'undefined' ? window._wpMetaBoxUrl : null;
	if ( ! url || emMetaBoxReloadInFlight ) {
		return;
	}
	const liveBoxes = document.querySelectorAll( '.postbox[id^="em-"]' );
	if ( ! liveBoxes.length ) {
		return;
	}

	emMetaBoxReloadInFlight = true;
	dbg( 'reloading EM metaboxes from', url.replace( /nonce=[^&]+/i, 'nonce=***' ) );

	fetch( url, { credentials: 'same-origin' } )
		.then( ( r ) => r.text() )
		.then( ( html ) => {
			const doc = new DOMParser().parseFromString( html, 'text/html' );
			let swapped = 0;
			liveBoxes.forEach( ( box ) => {
				const fresh = doc.getElementById( box.id );
				const liveInside = box.querySelector( '.inside' );
				const freshInside = fresh && fresh.querySelector( '.inside' );
				if ( ! liveInside || ! freshInside ) {
					return;
				}
				// Tear down JS-driven widgets (Selectize, flatpickr, tippy) bound
				// to the old nodes so we don't leak detached instances, then swap
				// in the server-fresh markup and re-initialise.
				if ( typeof window.em_unsetup_ui_elements === 'function' ) {
					try { window.em_unsetup_ui_elements( liveInside ); } catch ( e ) { dbg( 'unsetup failed', box.id, e ); }
				}
				liveInside.innerHTML = freshInside.innerHTML;
				if ( typeof window.em_setup_ui_elements === 'function' ) {
					try { window.em_setup_ui_elements( liveInside ); } catch ( e ) { dbg( 'setup failed', box.id, e ); }
				}
				swapped++;
			} );
			// The swap rebuilt the recurrence form, so re-apply the visibility
			// class the hide rule keys on (see syncRecurringMetabox()).
			syncRecurringMetabox();
			// Also reload the canvas block — it renders the same metabox HTML
			// inline and needs fresh nonces/record-ids after each save.
			if ( typeof window.emReloadWhenBlock === 'function' ) {
				window.emReloadWhenBlock();
			}
			dbg( 'EM metaboxes reloaded:', swapped );
		} )
		.catch( ( e ) => dbg( 'metabox reload failed', e ) )
		.finally( () => {
			emMetaBoxReloadInFlight = false;
		} );
}

/**
 * Watch the save lifecycle and reload EM metaboxes each time the metabox-save
 * phase finishes. isSavingMetaBoxes() is the LAST step of a Gutenberg save (it
 * fires after the REST post save, once per real save — never on autosaves), so
 * its true→false transition is the precise moment the server has persisted the
 * EM meta and the freshly-rendered HTML is worth re-fetching.
 */
function watchSavesForMetaBoxReload() {
	const editorRO = select( 'core/editor' );
	if ( ! editorRO ) {
		return;
	}
	// isSavingMetaBoxes lives on core/edit-post (the metabox machinery is part
	// of the post editor, not the generic editor store).
	const metaBoxesSavingFn = () => {
		const editPost = select( 'core/edit-post' );
		return !! ( editPost && typeof editPost.isSavingMetaBoxes === 'function' && editPost.isSavingMetaBoxes() );
	};

	let wasSavingMetaBoxes = false;
	subscribe( () => {
		if ( ! isEMPostType() ) {
			return;
		}
		const saving = metaBoxesSavingFn();
		if ( wasSavingMetaBoxes && ! saving ) {
			// Metabox persistence just finished — pull the fresh server state in.
			reloadEMMetaBoxes();
		}
		wasSavingMetaBoxes = saving;
	} );
}

domReady( () => {
	// Capture phase: fires before React's bubble-phase listeners so the
	// preventDefault / stopPropagation actually keep Gutenberg from saving.
	document.addEventListener( 'click', onPublishClick, true );

	// Recurring metabox visibility sync — see syncRecurringMetabox().
	startRecurringMetaboxSync();

	// Reload EM metaboxes after each save so stateful forms (recurrences,
	// tickets, bookings) pick up server-assigned ids and fresh nonces.
	watchSavesForMetaBoxReload();

	// Ensure the em/event-when canvas block is present in every EM event editor.
	// For new events: the CPT template handles it. For existing events (saved
	// before this feature existed) the block is missing — inject it here.
	ensureWhenBlock();
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
