<?php
namespace EM\API;

class Service {

	public static function list_events( $params = array() ) {
		$params = Utils::normalize_input( $params );
		$accepted = array( 'search', 'scope', 'status', 'active_status', 'cancelled', 'active', 'category', 'tag', 'location', 'location_id', 'town', 'state', 'country', 'region', 'near', 'near_unit', 'near_distance', 'event_type', 'event_archetype', 'orderby', 'order', 'blog', 'private', 'private_only', 'timeslots' );
		$args = Utils::collection_args( $params, Utils::pick_search_args( $params, $accepted ) );
		if ( empty( $params['context'] ) || $params['context'] !== 'edit' || !current_user_can( 'edit_others_events' ) ) {
			$args['private'] = current_user_can( 'read_private_events' );
			if ( empty( $params['status'] ) ) {
				$args['status'] = 1;
			}
		}
		$events = \EM_Events::get( $args );
		$items = array();
		foreach ( $events as $EM_Event ) {
			$items[] = static::prepare_event( $EM_Event, $params['context'] ?? 'view' );
		}
		return array(
			'items' => $items,
			'pagination' => Utils::pagination( \EM_Events::$num_rows_found, $args['page'], $args['limit'] ),
		);
	}

	public static function get_event( $id, $context = 'view' ) {
		$EM_Event = em_get_event( $id );
		if ( !$EM_Event || !$EM_Event->get_id() ) {
			return Utils::error( 'em_api_event_not_found', __( 'Event not found.', 'events-manager' ), 404 );
		}
		if ( !static::can_read_event( $EM_Event, $context ) ) {
			return Utils::error( 'em_api_event_forbidden', __( 'You do not have permission to view this event.', 'events-manager' ), 403 );
		}
		return static::prepare_event( $EM_Event, $context );
	}

	public static function get_event_availability( $id ) {
		$EM_Event = em_get_event( $id );
		if ( !$EM_Event || !$EM_Event->get_id() ) {
			return Utils::error( 'em_api_event_not_found', __( 'Event not found.', 'events-manager' ), 404 );
		}
		if ( !static::can_read_event( $EM_Event ) ) {
			return Utils::error( 'em_api_event_forbidden', __( 'You do not have permission to view this event.', 'events-manager' ), 403 );
		}
		$EM_Bookings = $EM_Event->get_bookings();
		$tickets = array();
		foreach ( $EM_Event->get_tickets() as $EM_Ticket ) {
			$tickets[] = array(
				'id' => absint( $EM_Ticket->ticket_id ),
				'name' => $EM_Ticket->ticket_name,
				'description' => $EM_Ticket->ticket_description,
				'price' => $EM_Ticket->get_price(),
				'spaces' => $EM_Ticket->get_spaces(),
				'available_spaces' => $EM_Ticket->get_available_spaces(),
				'available' => $EM_Ticket->is_available(),
			);
		}
		return apply_filters( 'em_api_event_availability', array(
			'event_id' => $EM_Event->get_event_uid(),
			'bookings_enabled' => !empty( $EM_Event->event_rsvp ),
			'open' => $EM_Bookings->is_open(),
			'spaces' => $EM_Bookings->get_spaces(),
			'available_spaces' => $EM_Bookings->get_available_spaces(),
			'tickets' => $tickets,
		), $EM_Event );
	}

	/**
	 * Describe everything an agent needs to create a booking for one event:
	 * required fields, where they live in the payload, payment options, and a
	 * ready-to-submit example. Pro augments via the em_api_booking_requirements
	 * filter (custom booking/attendee form fields, gateways).
	 */
	public static function get_booking_requirements( $id ) {
		$EM_Event = em_get_event( $id );
		if ( !$EM_Event || !$EM_Event->get_id() ) {
			return Utils::error( 'em_api_event_not_found', __( 'Event not found.', 'events-manager' ), 404 );
		}
		if ( !static::can_read_event( $EM_Event ) ) {
			return Utils::error( 'em_api_event_forbidden', __( 'You do not have permission to view this event.', 'events-manager' ), 403 );
		}
		$EM_Bookings = $EM_Event->get_bookings();
		$tickets = array();
		foreach ( $EM_Event->get_tickets() as $EM_Ticket ) {
			$price = (float) $EM_Ticket->get_price();
			$tickets[] = array(
				'id'               => absint( $EM_Ticket->ticket_id ),
				'name'             => $EM_Ticket->ticket_name,
				'price'            => $price,
				'is_free'          => $price <= 0,
				'available_spaces' => $EM_Ticket->get_available_spaces(),
				'min'              => !empty( $EM_Ticket->ticket_min ) ? absint( $EM_Ticket->ticket_min ) : 1,
				'max'              => !empty( $EM_Ticket->ticket_max ) ? absint( $EM_Ticket->ticket_max ) : null,
			);
		}
		$payload = array(
			'event_id'         => $EM_Event->get_event_uid(),
			'bookings_open'    => !empty( $EM_Event->event_rsvp ) && $EM_Bookings->is_open(),
			'spaces_available' => $EM_Bookings->get_available_spaces(),
			'tickets'          => $tickets,
			// Core defaults; Pro replaces booking_fields with the custom form and adds attendee_fields + payment.
			'booking_fields'   => array(
				static::with_field_validation( array( 'field' => 'user_name',  'label' => __( 'Name', 'events-manager' ),  'type' => 'text',  'required' => true, 'location' => 'booking.user_name' ), $EM_Event ),
				static::with_field_validation( array( 'field' => 'user_email', 'label' => __( 'Email', 'events-manager' ), 'type' => 'email', 'required' => true, 'location' => 'booking.user_email' ), $EM_Event ),
			),
			'attendee_fields'  => array(),
			'payment'          => array( 'required_when' => 'never', 'field' => 'gateway', 'active_gateways' => array() ),
			'notes'            => array(),
		);
		$payload = apply_filters( 'em_api_booking_requirements', $payload, $EM_Event );
		$payload['example_payload'] = static::build_booking_example( $payload );
		return $payload;
	}

	/**
	 * Attaches a `validation` block to a single requirement-field entry, with format/pattern/example/messages inferred from the field type and event context. Pro overlays its own field-specific overrides (custom regex, custom error messages) by calling this and merging. Returns the field entry (passed through with `validation` added). Safe to call on any shape — fields that have no useful validation just get a minimal `validation: { required_message?, invalid_message? }` block.
	 *
	 * @param array     $field    Requirement-field entry being assembled.
	 * @param \EM_Event $EM_Event Event we're describing requirements for (used for country context on phone fields, etc.).
	 * @return array
	 */
	public static function with_field_validation( $field, $EM_Event = null ) {
		$field['validation'] = static::build_field_validation( $field, $EM_Event );
		return $field;
	}

	/**
	 * Build the validation metadata block for one requirement-field entry. Filterable via `em_api_field_validation` so add-ons or sites can refine the schema per field type without forking the service.
	 *
	 * @return array
	 */
	public static function build_field_validation( $field, $EM_Event = null ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		$label = isset( $field['label'] ) ? (string) $field['label'] : ( isset( $field['field'] ) ? (string) $field['field'] : '' );
		$validation = array(
			/* translators: %s is the human-readable field label, e.g. "Phone" */
			'required_message' => sprintf( __( '%s is required.', 'events-manager' ), $label ?: __( 'Value', 'events-manager' ) ),
		);
		switch ( $type ) {
			case 'email':
			case 'user_email':
				$validation['format']          = 'email';
				$validation['pattern']         = '^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$';
				$validation['example']         = 'jane@example.com';
				$validation['invalid_message'] = __( 'Please enter a valid email address.', 'events-manager' );
				break;
			case 'tel':
			case 'dbem_phone':
				$country = static::resolve_event_country( $EM_Event );
				$phone_enabled = class_exists( '\\EM\\Phone' ) && \EM\Phone::is_enabled();
				if ( $phone_enabled ) {
					$validation['format']           = 'E.164';
					$validation['pattern']          = '^\\+[1-9]\\d{6,14}$';
					$validation['preferred_format'] = 'E.164';
					$validation['invalid_message']  = __( 'Please provide a valid phone number.', 'events-manager' );
					$validation['human_hint']       = __( 'Use international format starting with `+` and a country code, e.g. `+447400123456`.', 'events-manager' );
					$example = \EM\Phone::example_number( $country );
					if ( $example ) {
						$validation['example'] = $example;
					}
				} else {
					$validation['format']          = 'tel';
					$validation['example']         = $country === 'US' ? '+15551234567' : '+447400123456';
					$validation['invalid_message'] = __( 'Please provide a valid phone number.', 'events-manager' );
				}
				if ( $country ) {
					$validation['country_context'] = $country;
				}
				break;
			case 'url':
				$validation['format']          = 'uri';
				$validation['pattern']         = '^https?://.+';
				$validation['example']         = 'https://example.com';
				$validation['invalid_message'] = __( 'Please enter a valid URL.', 'events-manager' );
				break;
			case 'number':
				$validation['format']          = 'integer';
				$validation['pattern']         = '^-?\\d+$';
				$validation['example']         = '1';
				break;
			case 'date':
				$validation['format']  = 'date';
				$validation['pattern'] = '^\\d{4}-\\d{2}-\\d{2}$';
				$validation['example'] = gmdate( 'Y-m-d' );
				break;
		}
		return apply_filters( 'em_api_field_validation', $validation, $field, $EM_Event );
	}

	/**
	 * Best-effort ISO 3166-1 alpha-2 country code for an event, used as default context when building country-aware field examples (phone numbers, etc.).
	 */
	protected static function resolve_event_country( $EM_Event = null ) {
		if ( $EM_Event && method_exists( $EM_Event, 'get_location' ) ) {
			$EM_Location = $EM_Event->get_location();
			if ( $EM_Location && !empty( $EM_Location->location_country ) ) {
				return strtoupper( (string) $EM_Location->location_country );
			}
		}
		$site_default = em_get_option( 'dbem_location_default_country' );
		if ( $site_default ) {
			return strtoupper( (string) $site_default );
		}
		$phone_default = em_get_option( 'dbem_phone_default_country' );
		return $phone_default ? strtoupper( (string) $phone_default ) : '';
	}

	protected static function build_booking_example( $payload ) {
		$example = array( 'event_id' => (string) ( $payload['event_id'] ?? '' ) );
		if ( !empty( $payload['payment']['active_gateways'] ) ) {
			$slugs = array_column( $payload['payment']['active_gateways'], 'slug' );
			$example['gateway'] = in_array( 'offline', $slugs, true ) ? 'offline' : reset( $slugs );
		}
		$booking = array();
		foreach ( (array) ( $payload['booking_fields'] ?? array() ) as $field ) {
			if ( empty( $field['required'] ) ) continue;
			$booking[ $field['field'] ] = static::booking_example_value( $field );
		}
		if ( $booking ) {
			$example['booking'] = $booking;
		}
		$ticket = $payload['tickets'][0] ?? null;
		if ( $ticket ) {
			$ticket_entry = array( 'spaces' => 1 );
			$attendee = array();
			foreach ( (array) ( $payload['attendee_fields'] ?? array() ) as $field ) {
				if ( empty( $field['required'] ) ) continue;
				$attendee[ $field['field'] ] = static::booking_example_value( $field );
			}
			if ( $attendee ) {
				$ticket_entry['ticket_bookings'] = array( array( 'attendee' => $attendee ) );
			}
			$example['em_tickets'] = array( (string) $ticket['id'] => $ticket_entry );
		}
		return $example;
	}

	protected static function booking_example_value( $field ) {
		// Prefer a validator-aware example when build_field_validation set one
		// (the only path that gets phone numbers right per country).
		if ( !empty( $field['validation']['example'] ) ) {
			return (string) $field['validation']['example'];
		}
		if ( !empty( $field['options'] ) && is_array( $field['options'] ) ) {
			return reset( $field['options'] );
		}
		$id = (string) ( $field['field'] ?? '' );
		switch ( $field['type'] ?? 'text' ) {
			case 'user_login': return 'janedoe';
			case 'email':      return 'jane@example.com';
			case 'tel':        return '+447400123456';
			case 'number':     return '1';
			case 'date':       return gmdate( 'Y-m-d' );
			case 'checkbox':   return '1';
		}
		if ( strpos( $id, 'email' ) !== false ) return 'jane@example.com';
		if ( strpos( $id, 'phone' ) !== false ) return '+447400123456';
		if ( strpos( $id, 'login' ) !== false ) return 'janedoe';
		if ( strpos( $id, 'first' ) !== false ) return 'Jane';
		if ( strpos( $id, 'last' )  !== false ) return 'Doe';
		return 'Jane Doe';
	}

	public static function create_event( $data ) {
		return static::save_event( $data );
	}

	public static function update_event( $id, $data ) {
		return static::save_event( $data, $id );
	}

	public static function save_event( $data, $id = 0 ) {
		$data = Utils::normalize_input( $data );
		$EM_Event = $id ? em_get_event( $id ) : new \EM_Event();
		if ( $id && ( !$EM_Event || !$EM_Event->get_id() ) ) {
			return Utils::error( 'em_api_event_not_found', __( 'Event not found.', 'events-manager' ), 404 );
		}
		if ( !$EM_Event->can_manage( 'edit_events', 'edit_others_events' ) ) {
			return Utils::object_error( 'em_api_event_forbidden', $EM_Event, __( 'You do not have permission to save this event.', 'events-manager' ), 403 );
		}
		if ( !empty( $data['em_tickets'] ) && !$EM_Event->can_manage( 'manage_bookings', 'manage_others_bookings' ) ) {
			return Utils::object_error( 'em_api_ticket_forbidden', $EM_Event, __( 'You do not have permission to manage tickets for this event.', 'events-manager' ), 403 );
		}
		static::apply_consent_to_cpt( $EM_Event, $data );
		do_action( 'em_api_event_save_pre', $EM_Event, $data, $id );
		// For partial updates, pre-load current event state so EM_Event::get_post()
		// (which is destructive on missing $_POST keys) doesn't wipe untouched fields.
		$base = $id ? $EM_Event->to_request_data() : array();
		// Translate API-friendly flat times to the nested event_timeranges[0] shape EM expects.
		$data = static::translate_event_input( $data );
		// to_request_data() re-emits the event's time as a timerange with no timerange_id.
		// On update, timeranges::get_post() can't match it to the existing row, so it appends
		// a duplicate and the save fails with "Timeranges cannot overlap". Only re-post the
		// timeranges when the caller is actually changing the time, and carry the existing
		// timerange_id through so EM updates the row in place instead of duplicating it.
		if ( $id ) {
			if ( !array_key_exists( 'event_timeranges', $data ) ) {
				unset( $base['event_timeranges'] );
			} elseif ( isset( $data['event_timeranges'][0] ) && is_array( $data['event_timeranges'][0] ) && empty( $data['event_timeranges'][0]['timerange_id'] ) ) {
				$first = $EM_Event->get_timeranges()->get_first();
				if ( $first && !empty( $first->timerange_id ) ) {
					$data['event_timeranges'][0]['timerange_id'] = $first->timerange_id;
				}
			}
		}
		$request_data = static::normalize_em_tickets_keys( array_merge( $base, $data ) );
		$valid = Utils::with_request_data( $request_data, function() use ( $EM_Event, $data ) {
			if ( !$EM_Event->get_post( true ) ) {
				return false;
			}
			static::apply_force_status( $EM_Event, $data, 'event' );
			return true;
		} );
		if ( !$valid ) {
			return Utils::object_error( 'em_api_event_invalid', $EM_Event, __( 'Event data is invalid.', 'events-manager' ), 400 );
		}
		if ( !$EM_Event->save() ) {
			return Utils::object_error( 'em_api_event_save_failed', $EM_Event, __( 'Event could not be saved.', 'events-manager' ), 500 );
		}
		static::save_event_terms( $EM_Event, $data );
		$featured_result = static::apply_featured_image( $EM_Event->post_id ?? 0, $data );
		if ( is_wp_error( $featured_result ) ) {
			return Utils::object_error( 'em_api_event_featured_image_failed', $EM_Event, $featured_result->get_error_message(), 400 );
		}
		do_action( 'em_api_event_save', $EM_Event, $data, $id );
		return static::prepare_event( $EM_Event, 'edit' );
	}

	public static function delete_event( $id, $force = false ) {
		$EM_Event = em_get_event( $id );
		if ( !$EM_Event || !$EM_Event->get_id() ) {
			return Utils::error( 'em_api_event_not_found', __( 'Event not found.', 'events-manager' ), 404 );
		}
		if ( !$EM_Event->can_manage( 'delete_events', 'delete_others_events' ) ) {
			return Utils::object_error( 'em_api_event_forbidden', $EM_Event, __( 'You do not have permission to delete this event.', 'events-manager' ), 403 );
		}
		$previous = static::prepare_event( $EM_Event, 'edit' );
		if ( !$EM_Event->delete( Utils::is_truthy( $force ) ) ) {
			// Orphan fallback: EM_Event::delete() returns false and strands the em_events row when the WP post is already gone and EM's own orphaned_event detection didn't engage (e.g. a stale post_id or an object-cache race).
			// That row would otherwise return a blank 500 on every retry and never clear, so detect the missing post and remove the row directly via delete_meta(), leaving the normal delete path untouched.
			if ( $EM_Event->event_id && ( empty( $EM_Event->post_id ) || !get_post( $EM_Event->post_id ) ) && $EM_Event->delete_meta() ) {
				wp_cache_delete( $EM_Event->event_id, 'em_events' );
				return array( 'deleted' => true, 'orphaned' => true, 'previous' => $previous );
			}
			return Utils::object_error( 'em_api_event_delete_failed', $EM_Event, __( 'Event could not be deleted.', 'events-manager' ), 500 );
		}
		return array( 'deleted' => true, 'previous' => $previous );
	}

	public static function list_event_tickets( $id, $params = array() ) {
		$EM_Event = em_get_event( $id );
		if ( !$EM_Event || !$EM_Event->get_id() ) {
			return Utils::error( 'em_api_event_not_found', __( 'Event not found.', 'events-manager' ), 404 );
		}
		$params = Utils::normalize_input( $params );
		if ( !static::can_read_event( $EM_Event, $params['context'] ?? 'view' ) ) {
			return Utils::error( 'em_api_event_forbidden', __( 'You do not have permission to view this event.', 'events-manager' ), 403 );
		}
		$items = array();
		foreach ( $EM_Event->get_tickets( true ) as $EM_Ticket ) {
			$items[] = static::prepare_ticket( $EM_Ticket, $params['context'] ?? 'view' );
		}
		return array(
			'items' => $items,
			'pagination' => Utils::pagination( count( $items ), 1, max( 1, count( $items ) ) ),
		);
	}

	public static function get_ticket( $id, $context = 'view' ) {
		$EM_Ticket = static::get_ticket_object( $id );
		if ( is_wp_error( $EM_Ticket ) ) return $EM_Ticket;
		if ( !static::can_read_event( $EM_Ticket->get_event(), $context ) ) {
			return Utils::error( 'em_api_ticket_forbidden', __( 'You do not have permission to view this ticket.', 'events-manager' ), 403 );
		}
		return static::prepare_ticket( $EM_Ticket, $context );
	}

	public static function create_event_ticket( $event_id, $data ) {
		$EM_Event = em_get_event( $event_id );
		if ( !$EM_Event || !$EM_Event->get_id() ) {
			return Utils::error( 'em_api_event_not_found', __( 'Event not found.', 'events-manager' ), 404 );
		}
		if ( !$EM_Event->can_manage( 'manage_bookings', 'manage_others_bookings' ) ) {
			return Utils::object_error( 'em_api_ticket_forbidden', $EM_Event, __( 'You do not have permission to manage tickets for this event.', 'events-manager' ), 403 );
		}
		$data = Utils::normalize_input( $data );
		unset( $data['id'], $data['ticket_id'] );
		$data['event_id'] = $EM_Event->get_event_id();
		$EM_Ticket = new \EM_Ticket();
		$EM_Ticket->event = $EM_Event;
		$EM_Ticket->get_post( $data );
		if ( !$EM_Event->event_rsvp ) {
			$EM_Event->event_rsvp = 1;
			$EM_Event->save();
		}
		if ( !$EM_Ticket->save() ) {
			return Utils::object_error( 'em_api_ticket_save_failed', $EM_Ticket, __( 'Ticket could not be saved.', 'events-manager' ), 400 );
		}
		return static::prepare_ticket( $EM_Ticket, 'edit' );
	}

	public static function update_ticket( $id, $data ) {
		$EM_Ticket = static::get_ticket_object( $id );
		if ( is_wp_error( $EM_Ticket ) ) return $EM_Ticket;
		if ( !$EM_Ticket->can_manage() ) {
			return Utils::object_error( 'em_api_ticket_forbidden', $EM_Ticket, __( 'You do not have permission to update this ticket.', 'events-manager' ), 403 );
		}
		$EM_Ticket->get_post( Utils::normalize_input( $data ) );
		if ( !$EM_Ticket->save() ) {
			return Utils::object_error( 'em_api_ticket_save_failed', $EM_Ticket, __( 'Ticket could not be saved.', 'events-manager' ), 400 );
		}
		return static::prepare_ticket( $EM_Ticket, 'edit' );
	}

	public static function delete_ticket( $id, $force = false ) {
		$EM_Ticket = static::get_ticket_object( $id );
		if ( is_wp_error( $EM_Ticket ) ) return $EM_Ticket;
		if ( !$EM_Ticket->can_manage() ) {
			return Utils::object_error( 'em_api_ticket_forbidden', $EM_Ticket, __( 'You do not have permission to delete this ticket.', 'events-manager' ), 403 );
		}
		$previous = static::prepare_ticket( $EM_Ticket, 'edit' );
		if ( $EM_Ticket->get_bookings_count( false, true ) > 0 ) {
			return Utils::error( 'em_api_ticket_has_bookings', __( 'Tickets with bookings cannot be deleted.', 'events-manager' ), 409, array( 'previous' => $previous ) );
		}
		if ( !$EM_Ticket->delete() ) {
			return Utils::object_error( 'em_api_ticket_delete_failed', $EM_Ticket, __( 'Ticket could not be deleted.', 'events-manager' ), 500 );
		}
		return array( 'deleted' => true, 'previous' => $previous );
	}

	public static function list_locations( $params = array() ) {
		$params = Utils::normalize_input( $params );
		$accepted = array( 'search', 'scope', 'status', 'event_status', 'eventful', 'eventless', 'category', 'tag', 'town', 'state', 'country', 'region', 'near', 'near_unit', 'near_distance', 'orderby', 'order', 'blog', 'private', 'private_only' );
		$args = Utils::collection_args( $params, Utils::pick_search_args( $params, $accepted ) );
		if ( empty( $params['context'] ) || $params['context'] !== 'edit' || !current_user_can( 'edit_others_locations' ) ) {
			$args['private'] = current_user_can( 'read_private_locations' );
			if ( empty( $params['status'] ) ) {
				$args['status'] = 1;
			}
		}
		$locations = \EM_Locations::get( $args );
		$items = array();
		foreach ( $locations as $EM_Location ) {
			$items[] = static::prepare_location( $EM_Location, $params['context'] ?? 'view' );
		}
		return array(
			'items' => $items,
			'pagination' => Utils::pagination( \EM_Locations::$num_rows_found, $args['page'], $args['limit'] ),
		);
	}

	/**
	 * Returns the list of countries, paired with their translated display names from em_get_countries(). By default returns the full ISO list so the endpoint can back a country picker on a fresh install with no locations yet.
	 *
	 * @param array $params Optional.
	 *   - `only_available` (bool): when true, narrow the result to country codes that have at least one stored location row. Useful for "what countries do I have events in?" agent queries.
	 *   - `search` (string): case-insensitive substring filter against code or name.
	 * @return array { items: [ { code, name } ] }
	 */
	public static function list_location_countries( $params = array() ) {
		$params = Utils::normalize_input( $params );
		$names = function_exists( 'em_get_countries' ) ? em_get_countries() : array();
		$only_available = !empty( $params['only_available'] ) && Utils::is_truthy( $params['only_available'] );
		if ( $only_available ) {
			global $wpdb;
			$rows = $wpdb->get_col( "SELECT DISTINCT location_country FROM " . EM_LOCATIONS_TABLE . " WHERE location_country IS NOT NULL AND location_country != '' ORDER BY location_country ASC" );
			$items = array();
			foreach ( $rows as $code ) {
				$items[] = array(
					'code' => $code,
					'name' => isset( $names[ $code ] ) ? $names[ $code ] : $code,
				);
			}
		} else {
			$items = array();
			foreach ( $names as $code => $name ) {
				// em_get_countries() prepends a blank with key 0 when its $add_blank arg is set; our call doesn't ask for it, but guard against any future caller that does.
				if ( !$code || !is_string( $code ) ) continue;
				$items[] = array( 'code' => $code, 'name' => $name );
			}
		}
		if ( !empty( $params['search'] ) ) {
			$needle = mb_strtolower( sanitize_text_field( $params['search'] ) );
			$items = array_values( array_filter( $items, function( $item ) use ( $needle ) {
				return strpos( mb_strtolower( $item['code'] ), $needle ) !== false
					|| strpos( mb_strtolower( $item['name'] ), $needle ) !== false;
			} ) );
		}
		// Sort by display name for natural alphabetical reading.
		usort( $items, function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
		return array( 'items' => $items );
	}

	/**
	 * Returns the distinct list of regions, optionally narrowed to a country.
	 */
	public static function list_location_regions( $params = array() ) {
		return static::list_location_geo_column( 'location_region', $params, array( 'country' ) );
	}

	/**
	 * Returns the distinct list of states, optionally narrowed to country and/or region.
	 */
	public static function list_location_states( $params = array() ) {
		return static::list_location_geo_column( 'location_state', $params, array( 'country', 'region' ) );
	}

	/**
	 * Returns the distinct list of towns/cities, optionally narrowed to country, region, and/or state.
	 */
	public static function list_location_towns( $params = array() ) {
		return static::list_location_geo_column( 'location_town', $params, array( 'country', 'region', 'state' ) );
	}

	/**
	 * Shared helper for the geo discovery endpoints. Returns DISTINCT non-empty values from a single location column, filtered by any of the supplied parent columns.
	 *
	 * @param string $column         The column to SELECT DISTINCT.
	 * @param array  $params         Request params; `search` narrows results, plus any of $filter_columns.
	 * @param array  $filter_columns Parent columns accepted as filters (e.g. `country`, `region`, `state`).
	 * @return array { items: [ string, ... ] }
	 */
	protected static function list_location_geo_column( $column, $params, $filter_columns ) {
		global $wpdb;
		$params = Utils::normalize_input( $params );
		$conds = array();
		foreach ( $filter_columns as $filter ) {
			if ( !empty( $params[ $filter ] ) ) {
				$conds[] = $wpdb->prepare( "location_{$filter} = %s", sanitize_text_field( $params[ $filter ] ) );
			}
		}
		if ( !empty( $params['search'] ) ) {
			$conds[] = $wpdb->prepare( "{$column} LIKE %s", '%' . $wpdb->esc_like( sanitize_text_field( $params['search'] ) ) . '%' );
		}
		$cond = $conds ? ' AND ' . implode( ' AND ', $conds ) : '';
		$sql = "SELECT DISTINCT {$column} FROM " . EM_LOCATIONS_TABLE . " WHERE {$column} IS NOT NULL AND {$column} != ''" . $cond . " ORDER BY {$column} ASC";
		$items = $wpdb->get_col( $sql );
		return array( 'items' => array_values( $items ) );
	}

	public static function get_location( $id, $context = 'view' ) {
		$EM_Location = em_get_location( absint( $id ) );
		if ( !$EM_Location || !$EM_Location->get_id() ) {
			return Utils::error( 'em_api_location_not_found', __( 'Location not found.', 'events-manager' ), 404 );
		}
		if ( !static::can_read_location( $EM_Location, $context ) ) {
			return Utils::error( 'em_api_location_forbidden', __( 'You do not have permission to view this location.', 'events-manager' ), 403 );
		}
		return static::prepare_location( $EM_Location, $context );
	}

	public static function create_location( $data ) {
		return static::save_location( $data );
	}

	public static function update_location( $id, $data ) {
		return static::save_location( $data, $id );
	}

	public static function save_location( $data, $id = 0 ) {
		$data = Utils::normalize_input( $data );
		$EM_Location = $id ? em_get_location( absint( $id ) ) : new \EM_Location();
		if ( $id && ( !$EM_Location || !$EM_Location->get_id() ) ) {
			return Utils::error( 'em_api_location_not_found', __( 'Location not found.', 'events-manager' ), 404 );
		}
		if ( !$EM_Location->can_manage( 'edit_locations', 'edit_others_locations' ) ) {
			return Utils::object_error( 'em_api_location_forbidden', $EM_Location, __( 'You do not have permission to save this location.', 'events-manager' ), 403 );
		}
		static::apply_consent_to_cpt( $EM_Location, $data );
		// For partial updates, pre-load current location state — same reasoning as save_event().
		$base = $id ? $EM_Location->to_request_data() : array();
		$request_data = array_merge( $base, $data );
		$valid = Utils::with_request_data( $request_data, function() use ( $EM_Location, $data ) {
			if ( !$EM_Location->get_post( true ) ) {
				return false;
			}
			static::apply_force_status( $EM_Location, $data, 'location' );
			return true;
		} );
		if ( !$valid ) {
			return Utils::object_error( 'em_api_location_invalid', $EM_Location, __( 'Location data is invalid.', 'events-manager' ), 400 );
		}
		if ( !$EM_Location->save() ) {
			return Utils::object_error( 'em_api_location_save_failed', $EM_Location, __( 'Location could not be saved.', 'events-manager' ), 500 );
		}
		$featured_result = static::apply_featured_image( $EM_Location->post_id ?? 0, $data );
		if ( is_wp_error( $featured_result ) ) {
			return Utils::object_error( 'em_api_location_featured_image_failed', $EM_Location, $featured_result->get_error_message(), 400 );
		}
		return static::prepare_location( $EM_Location, 'edit' );
	}

	/**
	 * Applies a `featured_image` input value to a CPT post — resolving the polymorphic shape to an attachment ID, calling set_post_thumbnail(), and optionally writing `featured_image_alt` to the attachment's alt-text meta. Returns true on success, WP_Error on failure, or null if `featured_image` was not provided (no-op so partial updates leave the existing thumbnail alone).
	 */
	protected static function apply_featured_image( $post_id, $data ) {
		$post_id = absint( $post_id );
		if ( !$post_id || !array_key_exists( 'featured_image', $data ) ) return null;
		$input = $data['featured_image'];
		if ( $input === null || $input === '' || $input === false ) {
			delete_post_thumbnail( $post_id );
			return true;
		}
		$attachment_id = static::resolve_image_input( $input, $post_id );
		if ( is_wp_error( $attachment_id ) ) return $attachment_id;
		if ( !$attachment_id ) return null;
		set_post_thumbnail( $post_id, $attachment_id );
		if ( !empty( $data['featured_image_alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $data['featured_image_alt'] ) );
		}
		return true;
	}

	/**
	 * Returns the featured-image attachment shape for a CPT post, or null when no thumbnail is set. Used by prepare_event / prepare_location to overlay `featured_image` on top of EM's existing `to_api()` output.
	 */
	protected static function get_featured_image_for_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( !$post_id ) return null;
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		return $thumbnail_id ? static::prepare_attachment( $thumbnail_id ) : null;
	}

	public static function delete_location( $id, $force = false ) {
		$EM_Location = em_get_location( absint( $id ) );
		if ( !$EM_Location || !$EM_Location->get_id() ) {
			return Utils::error( 'em_api_location_not_found', __( 'Location not found.', 'events-manager' ), 404 );
		}
		if ( !$EM_Location->can_manage( 'delete_locations', 'delete_others_locations' ) ) {
			return Utils::object_error( 'em_api_location_forbidden', $EM_Location, __( 'You do not have permission to delete this location.', 'events-manager' ), 403 );
		}
		$previous = static::prepare_location( $EM_Location, 'edit' );
		if ( !$EM_Location->delete( Utils::is_truthy( $force ) ) ) {
			return Utils::object_error( 'em_api_location_delete_failed', $EM_Location, __( 'Location could not be deleted.', 'events-manager' ), 500 );
		}
		return array( 'deleted' => true, 'previous' => $previous );
	}

	public static function list_bookings( $params = array() ) {
		$params = Utils::normalize_input( $params );
		if ( !is_user_logged_in() ) {
			return Utils::error( 'em_api_booking_forbidden', __( 'You must be logged in to view bookings.', 'events-manager' ), 401 );
		}
		$accepted = array( 'search', 'scope', 'event', 'event_id', 'status', 'rsvp_status', 'person', 'ticket_id', 'booking_id', 'country', 'region', 'state', 'town', 'near', 'near_unit', 'near_distance', 'orderby', 'order', 'blog', 'timeslot_id' );
		$args = Utils::collection_args( $params, Utils::pick_search_args( $params, $accepted ) );
		if ( !current_user_can( 'manage_others_bookings' ) && !current_user_can( 'manage_bookings' ) ) {
			$args['person'] = get_current_user_id();
		}
		if ( !empty( $params['event_id'] ) && empty( $args['event'] ) ) {
			$args['event'] = $params['event_id'];
		}
		$bookings = \EM_Bookings::get( $args );
		$items = array();
		foreach ( $bookings as $EM_Booking ) {
			if ( static::can_read_booking( $EM_Booking ) ) {
				$items[] = static::prepare_booking( $EM_Booking, $params['context'] ?? 'view' );
			}
		}
		$total = method_exists( '\EM_Bookings', 'count' ) ? \EM_Bookings::count( $args ) : count( $items );
		return array(
			'items' => $items,
			'pagination' => Utils::pagination( $total, $args['page'], $args['limit'] ),
		);
	}

	public static function get_booking( $id, $context = 'view' ) {
		$EM_Booking = em_get_booking( sanitize_text_field( $id ) );
		if ( !$EM_Booking || !$EM_Booking->booking_id ) {
			return Utils::error( 'em_api_booking_not_found', __( 'Booking not found.', 'events-manager' ), 404 );
		}
		if ( !static::can_read_booking( $EM_Booking ) ) {
			return Utils::error( 'em_api_booking_forbidden', __( 'You do not have permission to view this booking.', 'events-manager' ), 403 );
		}
		return static::prepare_booking( $EM_Booking, $context );
	}

	public static function create_booking( $data ) {
		return static::save_booking( $data );
	}

	public static function update_booking( $id, $data ) {
		return static::save_booking( $data, $id );
	}

	public static function save_booking( $data, $id = 0 ) {
		$data = Utils::normalize_input( $data );
		$EM_Booking = $id ? em_get_booking( sanitize_text_field( $id ) ) : new \EM_Booking();
		if ( !$id ) {
			$filtered_booking = apply_filters( 'em_api_create_booking_object', $EM_Booking, $data, $id );
			if ( $filtered_booking instanceof \EM_Booking ) {
				$EM_Booking = $filtered_booking;
			}
		}
		if ( $id && ( !$EM_Booking || !$EM_Booking->booking_id ) ) {
			return Utils::error( 'em_api_booking_not_found', __( 'Booking not found.', 'events-manager' ), 404 );
		}
		if ( $id && !$EM_Booking->can_manage( 'manage_bookings', 'manage_others_bookings' ) ) {
			return Utils::object_error( 'em_api_booking_forbidden', $EM_Booking, __( 'You do not have permission to save this booking.', 'events-manager' ), 403 );
		}
		if ( !$id && !static::can_create_booking() ) {
			return Utils::error( 'em_api_booking_forbidden', __( 'You do not have permission to create bookings through the API.', 'events-manager' ), 403 );
		}
		$request_data = static::booking_request_data( $data, $EM_Booking );
		$override_availability = !empty( $data['override_availability'] ) && current_user_can( 'manage_bookings' );
		$result = Utils::with_request_data( $request_data, function() use ( $EM_Booking, $override_availability ) {
			return $EM_Booking->get_post( $override_availability );
		} );
		if ( !$result || !$EM_Booking->validate( $override_availability ) ) {
			return static::booking_invalid_error( $EM_Booking );
		}
		static::assign_booking_person( $EM_Booking, $data );
		if ( !$EM_Booking->person_id && !empty( $EM_Booking->booking_meta['registration'] ) ) {
			if ( empty( $EM_Booking->booking_meta['registration']['user_email'] ) || !is_email( $EM_Booking->booking_meta['registration']['user_email'] ) ) {
				return Utils::error( 'em_api_booking_person_invalid', __( 'A valid guest email address is required for guest bookings.', 'events-manager' ), 400 );
			}
		}
		if ( !$id ) {
			$result = $EM_Booking->get_event()->get_bookings()->add( $EM_Booking );
		} else {
			$result = $EM_Booking->save( !isset( $data['send_email'] ) || Utils::is_truthy( $data['send_email'] ) );
		}
		if ( !$result ) {
			return Utils::object_error( 'em_api_booking_save_failed', $EM_Booking, __( 'Booking could not be saved.', 'events-manager' ), 500 );
		}
		return static::prepare_booking( $EM_Booking, 'edit' );
	}

	/**
	 * Validation error for create/update booking. Surfaces the real field errors
	 * (now non-empty thanks to object_error) and appends a pointer to
	 * get-booking-requirements so an agent can fetch the exact fields, their
	 * payload locations, and a ready-to-submit example for this event.
	 */
	protected static function booking_invalid_error( $EM_Booking ) {
		$error   = Utils::object_error( 'em_api_booking_invalid', $EM_Booking, __( 'Booking data is invalid.', 'events-manager' ), 400 );
		$message = trim( $error->get_error_message() );
		$hint    = __( "Call get-booking-requirements with this event's ID for the exact required fields, their payload locations (attendee fields go under em_tickets[<ticket_id>].ticket_bookings[].attendee), and a ready-to-submit example.", 'events-manager' );
		if ( strpos( $message, 'get-booking-requirements' ) === false ) {
			$message = $message === '' ? $hint : $message . ' ' . $hint;
		}
		$data = (array) $error->get_error_data();
		$data['booking_requirements_ability'] = 'events-manager/get-booking-requirements';
		return new \WP_Error( 'em_api_booking_invalid', $message, $data );
	}

	public static function set_booking_status( $id, $status, $send_email = true, $ignore_spaces = false ) {
		$EM_Booking = em_get_booking( sanitize_text_field( $id ) );
		if ( !$EM_Booking || !$EM_Booking->booking_id ) {
			return Utils::error( 'em_api_booking_not_found', __( 'Booking not found.', 'events-manager' ), 404 );
		}
		if ( !is_numeric( $status ) || !array_key_exists( absint( $status ), $EM_Booking->status_array ) ) {
			return Utils::error( 'em_api_booking_status_invalid', __( 'Booking status is invalid.', 'events-manager' ), 400 );
		}
		if ( !$EM_Booking->can_manage( 'manage_bookings', 'manage_others_bookings' ) ) {
			return Utils::object_error( 'em_api_booking_forbidden', $EM_Booking, __( 'You do not have permission to change this booking status.', 'events-manager' ), 403 );
		}
		if ( !$EM_Booking->set_status( absint( $status ), Utils::is_truthy( $send_email ), Utils::is_truthy( $ignore_spaces ) ) ) {
			return Utils::object_error( 'em_api_booking_status_failed', $EM_Booking, __( 'Booking status could not be changed.', 'events-manager' ), 500 );
		}
		return static::prepare_booking( $EM_Booking, 'edit' );
	}

	public static function delete_booking( $id ) {
		$EM_Booking = em_get_booking( sanitize_text_field( $id ) );
		if ( !$EM_Booking || !$EM_Booking->booking_id ) {
			return Utils::error( 'em_api_booking_not_found', __( 'Booking not found.', 'events-manager' ), 404 );
		}
		if ( !$EM_Booking->can_manage( 'manage_bookings', 'manage_others_bookings' ) ) {
			return Utils::object_error( 'em_api_booking_forbidden', $EM_Booking, __( 'You do not have permission to delete this booking.', 'events-manager' ), 403 );
		}
		$previous = static::prepare_booking( $EM_Booking, 'edit' );
		if ( !$EM_Booking->delete() ) {
			return Utils::object_error( 'em_api_booking_delete_failed', $EM_Booking, __( 'Booking could not be deleted.', 'events-manager' ), 500 );
		}
		return array( 'deleted' => true, 'previous' => $previous );
	}

	public static function list_terms( $taxonomy, $params = array() ) {
		if ( !taxonomy_exists( $taxonomy ) ) {
			return Utils::error( 'em_api_taxonomy_not_found', __( 'Taxonomy not found.', 'events-manager' ), 404 );
		}
		$params = Utils::normalize_input( $params );
		$page = !empty( $params['page'] ) ? absint( $params['page'] ) : 1;
		$per_page = !empty( $params['per_page'] ) ? min( 100, max( 1, absint( $params['per_page'] ) ) ) : 20;
		$args = array(
			'taxonomy' => $taxonomy,
			'hide_empty' => isset( $params['hide_empty'] ) ? Utils::is_truthy( $params['hide_empty'] ) : false,
			'number' => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
		);
		if ( !empty( $params['search'] ) ) {
			$args['search'] = sanitize_text_field( $params['search'] );
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) return $terms;
		$count_args = $args;
		unset( $count_args['number'], $count_args['offset'] );
		$total = wp_count_terms( $count_args );
		if ( is_wp_error( $total ) ) $total = count( $terms );
		$items = array();
		foreach ( $terms as $term ) {
			$items[] = static::prepare_term( $term, $taxonomy );
		}
		return array(
			'items' => $items,
			'pagination' => Utils::pagination( $total, $page, $per_page ),
		);
	}

	public static function get_term( $taxonomy, $id ) {
		$term = get_term( absint( $id ), $taxonomy );
		if ( !$term || is_wp_error( $term ) ) {
			return Utils::error( 'em_api_term_not_found', __( 'Term not found.', 'events-manager' ), 404 );
		}
		return static::prepare_term( $term, $taxonomy );
	}

	public static function save_term( $taxonomy, $data, $id = 0 ) {
		if ( !static::can_manage_terms() ) {
			return Utils::error( 'em_api_term_forbidden', __( 'You do not have permission to manage event terms.', 'events-manager' ), 403 );
		}
		$data = Utils::normalize_input( $data );
		$args = array();
		if ( isset( $data['slug'] ) ) $args['slug'] = sanitize_title( $data['slug'] );
		if ( isset( $data['description'] ) ) $args['description'] = wp_kses_post( $data['description'] );
		if ( isset( $data['parent'] ) ) $args['parent'] = absint( $data['parent'] );
		$name = !empty( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( $id ) {
			$result = wp_update_term( absint( $id ), $taxonomy, array_merge( array_filter( array( 'name' => $name ) ), $args ) );
		} else {
			if ( !$name ) {
				return Utils::error( 'em_api_term_invalid', __( 'Term name is required.', 'events-manager' ), 400 );
			}
			$result = wp_insert_term( $name, $taxonomy, $args );
		}
		if ( is_wp_error( $result ) ) return $result;
		$term_id = absint( $result['term_id'] );
		$image_result = static::apply_term_color_and_image( $taxonomy, $term_id, $data );
		if ( is_wp_error( $image_result ) ) return $image_result;
		return static::get_term( $taxonomy, $term_id );
	}

	/**
	 * Writes `color` and `image` overlays for a term into EM_META_TABLE under the keys the EM taxonomy admin already uses (`{option_name}-bgcolor` / `{option_name}-image` / `{option_name}-image-id`). Resolves the polymorphic `image` input via resolve_image_input() so URL/base64/multipart all work. No-op for keys that aren't in $data, so partial updates leave existing color/image untouched.
	 */
	protected static function apply_term_color_and_image( $taxonomy, $term_id, $data ) {
		$option_name = static::taxonomy_option_name( $taxonomy );
		if ( !$option_name || !$term_id ) return null;
		global $wpdb;
		if ( array_key_exists( 'color', $data ) ) {
			if ( $data['color'] === null || $data['color'] === '' ) {
				$wpdb->delete( EM_META_TABLE, array( 'object_id' => $term_id, 'meta_key' => $option_name . '-bgcolor' ) );
				wp_cache_delete( $term_id, 'em_' . $option_name . '_colors' );
			} else {
				$color = sanitize_hex_color( $data['color'] );
				if ( !$color ) {
					return Utils::error( 'em_api_term_color_invalid', __( '`color` must be a valid hex colour (e.g. `#80b538`).', 'events-manager' ), 400 );
				}
				static::upsert_em_meta( $term_id, $option_name . '-bgcolor', $color );
				wp_cache_set( $term_id, $color, 'em_' . $option_name . '_colors' );
			}
		}
		if ( array_key_exists( 'image', $data ) ) {
			if ( $data['image'] === null || $data['image'] === '' || $data['image'] === false ) {
				$wpdb->delete( EM_META_TABLE, array( 'object_id' => $term_id, 'meta_key' => $option_name . '-image' ) );
				$wpdb->delete( EM_META_TABLE, array( 'object_id' => $term_id, 'meta_key' => $option_name . '-image-id' ) );
			} else {
				$attachment_id = static::resolve_image_input( $data['image'] );
				if ( is_wp_error( $attachment_id ) ) return $attachment_id;
				if ( $attachment_id ) {
					$url = wp_get_attachment_url( $attachment_id );
					if ( $url ) static::upsert_em_meta( $term_id, $option_name . '-image', $url );
					static::upsert_em_meta( $term_id, $option_name . '-image-id', (string) $attachment_id );
				}
			}
		}
		return true;
	}

	/**
	 * INSERT-or-UPDATE for EM_META_TABLE rows keyed by (object_id, meta_key). Mirrors what em-taxonomy-admin.php does inline, just factored out.
	 */
	protected static function upsert_em_meta( $object_id, $meta_key, $meta_value ) {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . EM_META_TABLE . ' WHERE object_id = %d AND meta_key = %s', $object_id, $meta_key ) );
		if ( $exists ) {
			$wpdb->update( EM_META_TABLE, array( 'meta_value' => $meta_value ), array( 'object_id' => $object_id, 'meta_key' => $meta_key ) );
		} else {
			$wpdb->insert( EM_META_TABLE, array( 'object_id' => $object_id, 'meta_key' => $meta_key, 'meta_value' => $meta_value ) );
		}
	}

	/**
	 * Maps a WP taxonomy slug to the EM taxonomy `option_name` used as the prefix for term meta keys (`category-bgcolor`, `tag-image`, etc.).
	 */
	protected static function taxonomy_option_name( $taxonomy ) {
		if ( defined( 'EM_TAXONOMY_CATEGORY' ) && $taxonomy === EM_TAXONOMY_CATEGORY ) return 'category';
		if ( defined( 'EM_TAXONOMY_TAG' ) && $taxonomy === EM_TAXONOMY_TAG ) return 'tag';
		return null;
	}

	public static function delete_term( $taxonomy, $id ) {
		if ( !current_user_can( 'delete_event_categories' ) ) {
			return Utils::error( 'em_api_term_forbidden', __( 'You do not have permission to delete event terms.', 'events-manager' ), 403 );
		}
		$previous = static::get_term( $taxonomy, $id );
		if ( is_wp_error( $previous ) ) return $previous;
		$result = wp_delete_term( absint( $id ), $taxonomy );
		if ( is_wp_error( $result ) ) return $result;
		if ( !$result ) {
			return Utils::error( 'em_api_term_delete_failed', __( 'Term could not be deleted.', 'events-manager' ), 500 );
		}
		return array( 'deleted' => true, 'previous' => $previous );
	}

	public static function can_create_booking() {
		$allow_anonymous = em_get_option( 'dbem_bookings_anonymous' ) && apply_filters( 'em_api_allow_anonymous_booking_create', false );
		$allowed = is_user_logged_in() || $allow_anonymous;
		return apply_filters( 'em_api_can_create_booking', $allowed );
	}

	public static function can_manage_terms() {
		return current_user_can( 'edit_event_categories' );
	}

	protected static function can_read_event( $EM_Event, $context = 'view' ) {
		if ( $context === 'edit' ) {
			return $EM_Event->can_manage( 'edit_events', 'edit_others_events' );
		}
		if ( $EM_Event->is_published() && empty( $EM_Event->event_private ) ) {
			return true;
		}
		if ( $EM_Event->event_private && current_user_can( 'read_private_events' ) ) {
			return true;
		}
		return $EM_Event->can_manage( 'edit_events', 'edit_others_events' );
	}

	protected static function can_read_location( $EM_Location, $context = 'view' ) {
		if ( $context === 'edit' ) {
			return $EM_Location->can_manage( 'edit_locations', 'edit_others_locations' );
		}
		if ( $EM_Location->is_published() && empty( $EM_Location->location_private ) ) {
			return true;
		}
		if ( $EM_Location->location_private && current_user_can( 'read_private_locations' ) ) {
			return true;
		}
		return $EM_Location->can_manage( 'edit_locations', 'edit_others_locations' );
	}

	protected static function can_read_booking( $EM_Booking ) {
		if ( !$EM_Booking || !$EM_Booking->booking_id ) return false;
		if ( $EM_Booking->can_manage( 'manage_bookings', 'manage_others_bookings' ) ) return true;
		return is_user_logged_in() && absint( $EM_Booking->person_id ) === get_current_user_id();
	}

	protected static function prepare_event( $EM_Event, $context = 'view' ) {
		$can_manage = $context === 'edit' && $EM_Event->can_manage( 'edit_events', 'edit_others_events' );
		$api = $EM_Event->to_api();
		$api = Utils::strip_private_owner_data( $api, $can_manage && $context === 'edit' );
		if ( $context !== 'edit' ) {
			unset( $api['blog_id'] );
		} else {
			$api['permissions'] = array(
				'edit' => $can_manage,
				'delete' => $EM_Event->can_manage( 'delete_events', 'delete_others_events' ),
			);
		}
		$api['featured_image'] = static::get_featured_image_for_post( $EM_Event->post_id ?? 0 );
		return apply_filters( 'em_api_prepare_event', $api, $EM_Event, $context );
	}

	protected static function prepare_location( $EM_Location, $context = 'view' ) {
		$can_manage = $context === 'edit' && $EM_Location->can_manage( 'edit_locations', 'edit_others_locations' );
		$api = $EM_Location->to_api();
		if ( !$can_manage || $context !== 'edit' ) {
			unset( $api['blog_id'] );
		} else {
			$api['permissions'] = array(
				'edit' => $can_manage,
				'delete' => $EM_Location->can_manage( 'delete_locations', 'delete_others_locations' ),
			);
		}
		$api['featured_image'] = static::get_featured_image_for_post( $EM_Location->post_id ?? 0 );
		return apply_filters( 'em_api_prepare_location', $api, $EM_Location, $context );
	}

	protected static function prepare_booking( $EM_Booking, $context = 'view' ) {
		$args = array( 'event' => $context !== 'embed' );
		$api = $EM_Booking->to_api( $args );
		if ( $context !== 'edit' && !current_user_can( 'manage_bookings' ) && !current_user_can( 'manage_others_bookings' ) ) {
			unset( $api['meta'] );
		}
		if ( $context === 'edit' ) {
			$api['permissions'] = array(
				'edit' => $EM_Booking->can_manage( 'manage_bookings', 'manage_others_bookings' ),
				'delete' => $EM_Booking->can_manage( 'manage_bookings', 'manage_others_bookings' ),
			);
		}
		return apply_filters( 'em_api_prepare_booking', $api, $EM_Booking, $context );
	}

	protected static function prepare_ticket( $EM_Ticket, $context = 'view' ) {
		$api = array(
			'id' => absint( $EM_Ticket->ticket_id ),
			'event_id' => absint( $EM_Ticket->event_id ),
			'name' => $EM_Ticket->name,
			'description' => $EM_Ticket->description,
			'status' => (bool) $EM_Ticket->status,
			'price' => (float) $EM_Ticket->get_price(),
			'spaces' => $EM_Ticket->get_spaces(),
			'available_spaces' => $EM_Ticket->get_available_spaces(),
			'booked_spaces' => $EM_Ticket->get_booked_spaces(),
			'reserved_spaces' => $EM_Ticket->get_reserved_spaces(),
			'pending_spaces' => $EM_Ticket->get_pending_spaces(),
			'min' => $EM_Ticket->min === null ? null : absint( $EM_Ticket->min ),
			'max' => $EM_Ticket->max === null ? null : absint( $EM_Ticket->max ),
			'required' => (bool) $EM_Ticket->required,
			'members' => (bool) $EM_Ticket->members,
			'members_roles' => array_values( (array) $EM_Ticket->members_roles ),
			'guests' => (bool) $EM_Ticket->guests,
			'start' => static::prepare_ticket_datetime( $EM_Ticket->ticket_start ),
			'end' => static::prepare_ticket_datetime( $EM_Ticket->ticket_end ),
			'order' => $EM_Ticket->ticket_order === null ? null : absint( $EM_Ticket->ticket_order ),
			'bookings_count' => $EM_Ticket->ticket_id ? absint( $EM_Ticket->get_bookings_count() ) : 0,
		);
		if ( $context === 'edit' ) {
			$api['meta'] = is_array( $EM_Ticket->ticket_meta ) ? $EM_Ticket->ticket_meta : array();
			$api['permissions'] = array(
				'edit' => $EM_Ticket->can_manage(),
				'delete' => $EM_Ticket->can_manage() && $EM_Ticket->get_bookings_count() === 0,
			);
		}
		return apply_filters( 'em_api_prepare_ticket', $api, $EM_Ticket, $context );
	}

	protected static function prepare_term( $term, $taxonomy ) {
		$class = $taxonomy === EM_TAXONOMY_TAG ? 'EM_Tag' : 'EM_Category';
		$EM_Term = class_exists( $class ) ? new $class( $term ) : false;
		$api = array(
			'id' => absint( $term->term_id ),
			'name' => $term->name,
			'slug' => $term->slug,
			'taxonomy' => $taxonomy,
			'description' => $term->description,
			'parent' => absint( $term->parent ),
			'count' => absint( $term->count ),
		);
		if ( $EM_Term ) {
			$api['color'] = $EM_Term->get_color();
			$api['image_url'] = $EM_Term->get_image_url();
			$api['url'] = $EM_Term->get_url();
			// Look up the attachment ID directly so consumers don't have to map URL → ID.
			$option_name = static::taxonomy_option_name( $taxonomy );
			$attachment_id = null;
			if ( $option_name ) {
				global $wpdb;
				$attachment_id = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM ' . EM_META_TABLE . ' WHERE object_id = %d AND meta_key = %s LIMIT 1', $term->term_id, $option_name . '-image-id' ) );
				$attachment_id = $attachment_id ? absint( $attachment_id ) : null;
			}
			$api['image'] = $attachment_id ? static::prepare_attachment( $attachment_id ) : null;
		}
		return apply_filters( 'em_api_prepare_term', $api, $term, $taxonomy );
	}

	protected static function get_ticket_object( $id ) {
		$EM_Ticket = \EM_Ticket::get( absint( $id ) );
		if ( !$EM_Ticket || empty( $EM_Ticket->ticket_id ) ) {
			return Utils::error( 'em_api_ticket_not_found', __( 'Ticket not found.', 'events-manager' ), 404 );
		}
		return $EM_Ticket;
	}

	/**
	 * Translate API-friendly flat time fields to the event_timeranges[0] shape EM core expects.
	 *
	 * EM core's EM_Event::get_post_meta() routes start/end times through the timeranges
	 * subsystem, not the flat event_start_time / event_end_time keys. Accept both shapes from
	 * API consumers; rewrite to the canonical nested form before with_request_data().
	 *
	 * Precedence: an explicit event_timeranges in $data wins. Flat fields fill in what's
	 * missing so a partial PATCH (e.g. just event_start_time) doesn't drop the other endpoint.
	 */
	protected static function translate_event_input( $data ) {
		$all_day    = $data['event_all_day'] ?? null;
		$start_time = $data['event_start_time'] ?? null;
		$end_time   = $data['event_end_time'] ?? null;
		if ( $all_day !== null || $start_time !== null || $end_time !== null ) {
			$tr = ( isset( $data['event_timeranges'][0] ) && is_array( $data['event_timeranges'][0] ) )
				? $data['event_timeranges'][0]
				: array();
			if ( Utils::is_truthy( $all_day ) ) {
				$tr['all_day'] = '1';
				unset( $tr['start'], $tr['end'] );
			} else {
				unset( $tr['all_day'] );
				if ( $start_time !== null ) $tr['start'] = $start_time;
				if ( $end_time !== null )   $tr['end']   = $end_time;
			}
			$data['event_timeranges'] = array( 0 => $tr ) + ( $data['event_timeranges'] ?? array() );
		}
		unset( $data['event_start_time'], $data['event_end_time'], $data['event_all_day'] );
		return $data;
	}

	/**
	 * Apply post_status from API payload to an EM CPT object, respecting publish capability.
	 * Used after EM_Event::get_post() / EM_Location::get_post() since force_status isn't
	 * part of the native $_POST contract.
	 */
	protected static function apply_force_status( $EM_Object, $data, $type ) {
		if ( empty( $data['post_status'] ) ) return;
		$status = Utils::sanitize_post_status( $data['post_status'] );
		if ( !$status ) return;
		$cap = $type === 'location' ? 'publish_locations' : 'publish_events';
		if ( current_user_can( $cap ) || $status !== 'publish' ) {
			$EM_Object->force_status = $status;
		}
	}

	/**
	 * EM_Tickets::get_post() skips $_POST['em_tickets'][0] because the EM admin form
	 * reserves row 0 for the JS template. API consumers shouldn't need to know that —
	 * if the payload uses 0-indexed sequential keys, shift everything up by 1.
	 */
	protected static function normalize_em_tickets_keys( $data ) {
		if ( !isset( $data['em_tickets'] ) || !is_array( $data['em_tickets'] ) ) {
			return $data;
		}
		$tickets = $data['em_tickets'];
		if ( array_key_exists( 0, $tickets ) || array_key_exists( '0', $tickets ) ) {
			$shifted = array();
			$i = 1;
			foreach ( $tickets as $row ) {
				$shifted[ $i++ ] = $row;
			}
			$data['em_tickets'] = $shifted;
		}
		return $data;
	}

	protected static function prepare_ticket_datetime( $value ) {
		if ( empty( $value ) || $value === '0000-00-00 00:00:00' ) {
			return null;
		}
		$timestamp = strtotime( $value );
		return $timestamp ? gmdate( 'c', $timestamp ) : $value;
	}

	protected static function save_event_terms( $EM_Event, $data ) {
		if ( empty( $EM_Event->post_id ) ) return;
		// Prefer event_categories / event_tags to match the live event form ($_POST contract).
		// Accept categories / tags as deprecated aliases.
		$categories = $data['event_categories'] ?? $data['categories'] ?? null;
		$tags       = $data['event_tags']       ?? $data['tags']       ?? null;
		if ( $categories !== null && taxonomy_exists( EM_TAXONOMY_CATEGORY ) && current_user_can( 'edit_events' ) ) {
			wp_set_object_terms( $EM_Event->post_id, array_map( 'absint', (array) $categories ), EM_TAXONOMY_CATEGORY );
		}
		if ( $tags !== null && taxonomy_exists( EM_TAXONOMY_TAG ) && current_user_can( 'edit_events' ) ) {
			wp_set_object_terms( $EM_Event->post_id, array_map( 'absint', (array) $tags ), EM_TAXONOMY_TAG );
		}
	}

	/**
	 * Prepare the $_REQUEST/$_POST data for EM_Booking::get_post().
	 *
	 * Field shape matches the live booking form ($_POST contract): flat user_name,
	 * user_email, dbem_phone, dbem_country at top level — no `person` wrapper. EM core
	 * and Pro add-ons read what they need from $_REQUEST directly (coupon_code, waitlist,
	 * donation_amount, etc.) so the payload passes through verbatim. We only gate
	 * admin-only fields (booking_tax_rate, person_id, booking_status, manual_booking*,
	 * payment_*) when the caller is not a booking manager.
	 */
	protected static function booking_request_data( $data, $EM_Booking ) {
		$request = $data;
		$request['event_id'] = $data['event_id'] ?? $data['event'] ?? $EM_Booking->event_id ?? null;
		// Flatten booking-form sub-objects so EM_Form / Pro forms read them from $_REQUEST.
		foreach ( array( 'booking', 'booking_fields', 'fields', 'registration' ) as $key ) {
			if ( !empty( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$request = array_merge( $request, $data[ $key ] );
			}
		}
		// Liberal input: agents sometimes prefix booking-form fields. EM core reads them by
		// bare fieldid, so strip a `booking_form_field_` prefix before they reach $_REQUEST.
		foreach ( $request as $key => $value ) {
			if ( strpos( (string) $key, 'booking_form_field_' ) === 0 ) {
				$bare = substr( $key, 19 );
				if ( $bare !== '' && !array_key_exists( $bare, $request ) ) {
					$request[ $bare ] = $value;
				}
				unset( $request[ $key ] );
			}
		}
		// Gate admin-only fields when the caller is not a booking manager.
		if ( !$EM_Booking->can_manage() ) {
			foreach ( array( 'booking_tax_rate', 'person_id', 'booking_status',
				'manual_booking', 'manual_booking_confirm', 'manual_booking_override',
				'payment_amount', 'payment_full' ) as $admin_field ) {
				unset( $request[ $admin_field ] );
			}
		}
		return static::apply_consent_to_request_data( $request, $data, 'booking' );
	}

	protected static function assign_booking_person( $EM_Booking, $data ) {
		$can_assign_person = current_user_can( 'manage_bookings' ) || current_user_can( 'manage_others_bookings' );
		if ( isset( $data['person_id'] ) && $can_assign_person ) {
			$EM_Booking->person_id = absint( $data['person_id'] );
		}
		if ( ( !$EM_Booking->person_id || $can_assign_person ) && !empty( $data['person'] ) && is_array( $data['person'] ) ) {
			if ( $can_assign_person || !is_user_logged_in() ) {
				$EM_Booking->person_id = 0;
				$EM_Booking->person = new \EM_Person( 0 );
			}
			$EM_Booking->booking_meta['registration'] = array_merge( $EM_Booking->booking_meta['registration'] ?? array(), array_filter( array(
				'user_name' => !empty( $data['person']['name'] ) ? sanitize_text_field( $data['person']['name'] ) : null,
				'user_email' => !empty( $data['person']['email'] ) ? sanitize_email( $data['person']['email'] ) : null,
				'dbem_phone' => !empty( $data['person']['phone'] ) ? sanitize_text_field( $data['person']['phone'] ) : null,
			) ) );
		}
	}

	protected static function apply_consent_to_request_data( $request, $data, $context ) {
		foreach ( static::get_consent_classes() as $class ) {
			$options = $class::$options;
			if ( empty( $options['param'] ) ) {
				continue;
			}
			if ( array_key_exists( $options['param'], $data ) ) {
				$request[ $options['param'] ] = Utils::is_truthy( $data[ $options['param'] ] ) ? 1 : 0;
			} elseif ( apply_filters( 'em_api_consent_default', true, $context, $class, $data ) ) {
				$request[ $options['param'] ] = 1;
			}
		}
		return $request;
	}

	protected static function apply_consent_to_cpt( $EM_Object, $data ) {
		$attributes = $EM_Object instanceof \EM_Event ? 'event_attributes' : ( $EM_Object instanceof \EM_Location ? 'location_attributes' : '' );
		if ( !$attributes ) {
			return;
		}
		foreach ( static::get_consent_classes() as $class ) {
			$options = $class::$options;
			if ( empty( $options['param'] ) || empty( $options['meta_key'] ) ) {
				continue;
			}
			if ( array_key_exists( $options['param'], $data ) ) {
				$consented = Utils::is_truthy( $data[ $options['param'] ] );
			} else {
				$consented = apply_filters( 'em_api_consent_default', true, $attributes === 'event_attributes' ? 'event' : 'location', $class, $data );
			}
			if ( $consented ) {
				$EM_Object->{$attributes}[ '_' . $options['meta_key'] ] = 1;
			}
		}
	}

	/**
	 * Uploads an image (or other media) to the WordPress media library and returns the attachment shape used everywhere else in this API. Accepts three input forms (the resolver below treats them as equivalent):
	 *
	 *   - `{ source_url: "https://..." }`                 → `media_sideload_image()`
	 *   - `{ filename, mime_type, content_base64 }`       → `wp_handle_sideload()` of decoded bytes
	 *   - `{ _files_file: $_FILES['file'] }`              → `wp_handle_upload()` (multipart)
	 *
	 * Optional inputs: `title`, `alt_text`, `caption`, `description`, `post_id` (attach to a post).
	 *
	 * @return array|\WP_Error Attachment info { id, url, mime_type, title, alt_text, width, height } or error.
	 */
	public static function upload_media( $data ) {
		$data = Utils::normalize_input( $data );
		if ( !current_user_can( 'upload_files' ) ) {
			return Utils::error( 'em_api_media_forbidden', __( 'You do not have permission to upload media.', 'events-manager' ), 403 );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = static::resolve_attachment_id( $data );
		if ( is_wp_error( $attachment_id ) ) return $attachment_id;
		// Optional post-upload metadata.
		$post_update = array( 'ID' => $attachment_id );
		if ( !empty( $data['title'] ) ) $post_update['post_title'] = sanitize_text_field( $data['title'] );
		if ( isset( $data['caption'] ) ) $post_update['post_excerpt'] = wp_kses_post( $data['caption'] );
		if ( isset( $data['description'] ) ) $post_update['post_content'] = wp_kses_post( $data['description'] );
		if ( !empty( $data['post_id'] ) ) $post_update['post_parent'] = absint( $data['post_id'] );
		if ( count( $post_update ) > 1 ) wp_update_post( $post_update );
		if ( isset( $data['alt_text'] ) ) update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $data['alt_text'] ) );
		return static::prepare_attachment( $attachment_id );
	}

	/**
	 * Resolves any of the supported media-input shapes to a real attachment ID, sideloading the bytes into the WP media library where needed.
	 *
	 * @return int|\WP_Error
	 */
	protected static function resolve_attachment_id( $data ) {
		// Existing ID — caller's done the work.
		if ( !empty( $data['id'] ) || !empty( $data['attachment_id'] ) ) {
			$id = absint( $data['id'] ?? $data['attachment_id'] );
			if ( !$id || get_post_type( $id ) !== 'attachment' ) {
				return Utils::error( 'em_api_media_invalid_id', __( 'Attachment ID not found.', 'events-manager' ), 404 );
			}
			return $id;
		}
		// Multipart upload from /media POST.
		if ( !empty( $data['_files_file'] ) ) {
			$file = $data['_files_file'];
			$overrides = array( 'test_form' => false, 'action' => 'em_api_media_upload' );
			// media_handle_upload expects $_FILES key — stage the file as $_FILES['em_api_media'].
			$_FILES['em_api_media'] = $file;
			$attachment_id = media_handle_upload( 'em_api_media', !empty( $data['post_id'] ) ? absint( $data['post_id'] ) : 0, array(), $overrides );
			unset( $_FILES['em_api_media'] );
			if ( is_wp_error( $attachment_id ) ) return $attachment_id;
			return $attachment_id;
		}
		// URL sideload.
		if ( !empty( $data['source_url'] ) ) {
			$source_url = esc_url_raw( $data['source_url'] );
			if ( !$source_url || !wp_http_validate_url( $source_url ) ) {
				return Utils::error( 'em_api_media_invalid_url', __( 'Invalid source URL.', 'events-manager' ), 400 );
			}
			$post_id = !empty( $data['post_id'] ) ? absint( $data['post_id'] ) : 0;
			$desc = !empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : null;
			$attachment_id = media_sideload_image( $source_url, $post_id, $desc, 'id' );
			if ( is_wp_error( $attachment_id ) ) return $attachment_id;
			return absint( $attachment_id );
		}
		// Base64 inline upload.
		if ( !empty( $data['content_base64'] ) ) {
			$filename = !empty( $data['filename'] ) ? sanitize_file_name( $data['filename'] ) : 'upload-' . wp_unique_id( 'em-' ) . '.bin';
			$bytes = base64_decode( $data['content_base64'], true );
			if ( $bytes === false || $bytes === '' ) {
				return Utils::error( 'em_api_media_invalid_base64', __( 'content_base64 is not valid base64 data.', 'events-manager' ), 400 );
			}
			$tmp_file = wp_tempnam( $filename );
			if ( !$tmp_file || file_put_contents( $tmp_file, $bytes ) === false ) {
				return Utils::error( 'em_api_media_tempfile_failed', __( 'Failed to write upload to temporary file.', 'events-manager' ), 500 );
			}
			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $tmp_file,
				'error'    => 0,
				'size'     => filesize( $tmp_file ),
			);
			if ( !empty( $data['mime_type'] ) ) $file_array['type'] = sanitize_text_field( $data['mime_type'] );
			$post_id = !empty( $data['post_id'] ) ? absint( $data['post_id'] ) : 0;
			$desc = !empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : null;
			$attachment_id = media_handle_sideload( $file_array, $post_id, $desc );
			if ( file_exists( $tmp_file ) ) @unlink( $tmp_file );
			if ( is_wp_error( $attachment_id ) ) return $attachment_id;
			return absint( $attachment_id );
		}
		return Utils::error( 'em_api_media_missing_source', __( 'Provide one of `id`, `source_url`, `content_base64`, or a multipart `file` upload.', 'events-manager' ), 400 );
	}

	/**
	 * Returns the canonical API shape for an attachment, used by upload_media and by the polymorphic featured-image / term-image resolvers on the read side.
	 */
	public static function prepare_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( !$attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) return null;
		$meta = wp_get_attachment_metadata( $attachment_id );
		$url = wp_get_attachment_url( $attachment_id );
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$post = get_post( $attachment_id );
		$api = array(
			'id'        => $attachment_id,
			'url'       => $url ?: null,
			'mime_type' => $post ? $post->post_mime_type : null,
			'title'     => $post ? $post->post_title : null,
			'alt_text'  => $alt ?: null,
			'width'     => isset( $meta['width'] ) ? absint( $meta['width'] ) : null,
			'height'    => isset( $meta['height'] ) ? absint( $meta['height'] ) : null,
		);
		return apply_filters( 'em_api_prepare_attachment', $api, $attachment_id );
	}

	/**
	 * Polymorphic featured-image / term-image resolver. Accepts the same shapes as upload_media plus a bare integer (attachment ID) or string (URL — sideloaded). Returns an attachment ID, null to clear, or a WP_Error. Used by event/location featured_image and term image inputs to keep one mental model for "give me an image" across every resource.
	 */
	public static function resolve_image_input( $input, $post_id = 0 ) {
		if ( $input === null || $input === '' || $input === false ) return null; // explicit clear
		if ( is_numeric( $input ) ) {
			return static::resolve_attachment_id( array( 'id' => $input ) );
		}
		if ( is_string( $input ) ) {
			return static::resolve_attachment_id( array( 'source_url' => $input, 'post_id' => $post_id ) );
		}
		if ( is_array( $input ) ) {
			if ( $post_id && empty( $input['post_id'] ) ) $input['post_id'] = $post_id;
			return static::resolve_attachment_id( $input );
		}
		return Utils::error( 'em_api_image_invalid', __( 'Image input must be an attachment ID, URL, or object with source_url / content_base64 / file.', 'events-manager' ), 400 );
	}

	protected static function get_consent_classes() {
		$classes = array_filter( array(
			class_exists( '\EM\Consent\Consent' ) ? '\EM\Consent\Consent' : null,
			class_exists( '\EM\Consent\Privacy' ) ? '\EM\Consent\Privacy' : null,
			class_exists( '\EM\Consent\Comms' ) ? '\EM\Consent\Comms' : null,
		) );
		return apply_filters( 'em_api_consent_classes', $classes );
	}
}
