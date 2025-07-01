document.addEventListener('DOMContentLoaded', function() {

	// load event recurrence data
	document.querySelectorAll('.em-recurrence-sets').forEach( function( recurrenceSets ) {
		recurrenceSets.querySelector('.em-recurrence-set[data-type="include"]:first-child').dataset.primary = "1"; // set primary recurrence flag for JS (CSS uses selector instead)
		document.dispatchEvent( new CustomEvent('em_event_editor_recurrences', { detail: { recurrenceSets : recurrenceSets } } ) );
	});

	// Event Status Warning
	document.querySelectorAll('select[name="event_active_status"]').forEach(select => {
		select.addEventListener('change', function (event) {
			if ( select.value === '0' && !confirm( EM.event_cancellations.warning.replace(/\\n/g, '\n') ) ) {
				event.preventDefault();
			}
		});
	});

	// Handle the recurring/repeating event selection and initialize showing/hiding relevant recurring sections
	document.querySelectorAll( '.event_type' ).forEach( eventType => {
		const form = eventType.closest( 'form' );
		eventType.addEventListener( 'change', function () {
			// When set to recurring or repeating, sync the main event data to primary recurrence set
			if ( handleRecurring() ) {
				let eventDateTimes = form.querySelector('.event-form-when');
				let eventDatePicker = eventDateTimes.querySelector('.em-datepicker.em-event-dates');
				let selectedDates;
				if ( eventDatePicker.classList.contains('em-datepicker-until') ) {
					selectedDates = [
						eventDatePicker.querySelector('.em-date-input-start.flatpickr-input')._flatpickr.selectedDates[0],
						eventDatePicker.querySelector('.em-date-input-end.flatpickr-input')._flatpickr.selectedDates[0]
					];
				} else {
					selectedDates = eventDatePicker.querySelector('.em-date-input.flatpickr-input')._flatpickr.selectedDates;
				}
				let eventTimeRange = eventDateTimes.querySelector('.event-times.em-time-range');
				// we need to get jQuery elements to handle the timepicker
				let eventStartTime = eventTimeRange.querySelector('.em-time-input.em-time-start');
				let eventEndTime = eventTimeRange.querySelector('.em-time-input.em-time-end');
				let eventAllDay = eventTimeRange.querySelector('input.em-time-all-day');
				let timezone = eventDateTimes.querySelector('select.event_timezone')?.value;
				let eventStatus = eventDateTimes.querySelector('select.event_active_status')?.value;
				let setDateTimes = false;

				// here, we're copying over all the regular event date/time/timezone data into the primary recurrence so there's some UI continuity in case user started adding dates before selecting recurring type
				let recurrenceSet = form.querySelector('.em-recurrence-set[data-primary="1"]');
				if ( recurrenceSet ) {
					// copy over the times first - if not the datepicker triggers a change loop and sets the 12am times back onto the event
					let timeRange = recurrenceSet.querySelector( '.em-recurrence-advanced .em-time-range' );
					if ( timeRange ) {
						let recurrenceStartTime = timeRange.querySelector( '.em-time-input.em-time-start' );
						let recurrenceEndTime = timeRange.querySelector( '.em-time-input.em-time-end' );
						let recurrenceAllDay = timeRange.querySelector( '.em-time-all-day' );
						if ( recurrenceStartTime ) {
							recurrenceStartTime.value = eventStartTime?.value;
						}
						if ( recurrenceEndTime ) {
							recurrenceEndTime.value = eventEndTime?.value;
						}
						if ( recurrenceAllDay ) {
							recurrenceAllDay.checked = eventAllDay?.checked;
						}
					}
					// get equivalent datepicker, timepicker and timezone
					if ( recurrenceSet.querySelector('select.recurrence_freq')?.value === 'on' ) {
						// we set the selected dates to those we selected in the datepicker, for now
						let recurrenceDatePicker = recurrenceSet.querySelector('.em-on-selector .em-date-input.flatpickr-input')?._flatpickr
						if ( !recurrenceDatePicker?.selectedDates ) {
							recurrenceDatePicker?.setDate( selectedDates, true );
							setDateTimes = true; // setting date triggers the setDateTimes event already
						}
					} else {
						// get the regular date range
						recurrenceSet.querySelectorAll('.em-recurrence-advanced .em-datepicker .em-date-input.flatpickr-input').forEach( input => {
							// add if we don't have dates set already
							if ( !input._flatpickr?.selectedDates.length ) {
								if (input.closest('.em-datepicker-until')) {
									input._flatpickr.setDate( input.classList.contains('em-date-input-start') ? selectedDates[0] : selectedDates[1], true );
									setDateTimes = true; // setting date triggers the setDateTimes event already
								} else if (input.closest('.em-datepicker-range')) {
									input._flatpickr.setDate( selectedDates, true );
									setDateTimes = true; // setting date triggers the setDateTimes event already
								}
							}
						});
					}
					// copy over the timezone
					[ 'select.recurrence_timezone', 'select.recurrence_status' ].forEach( selector => {
						let select = recurrenceSet.querySelector( selector );
						let value = selector === 'select.recurrence_timezone' ? timezone : eventStatus;
						if ( value && select ) {
							if ( select.selectize ) {
								select.selectize.setValue( value, true );
							} else {
								select.value = value;
							}
						}
					});
					// trigger a recurrence summary refresh
					if ( !setDateTimes ) {
						recurrenceSet.closest( '.em-recurrence-sets' ).dispatchEvent( new CustomEvent( 'setDateTimes' ) );
					}
				}
			}
		});
		/**
		 * Handles toggling of recurring event settings for a form element.
		 * Determines if the event is recurring based on the specified event type and updates the form element's classes accordingly.
		 */
		let handleRecurring = function() {
			// check now if it's recurring as well
			let isRecurring = false;
			if ( form ) {
				// it's recurring/repeating if the checkbox is checked, we add is-recurring regardless
				isRecurring = eventType.type === 'checkbox' ? eventType.checked : eventType.value !== 'single';
				form.classList.toggle( 'em-is-recurring', isRecurring );
				if ( eventType.type === 'checkbox' ) {
					// checkboxes are now only for recurring
					form.classList.toggle( 'em-type-recurring', eventType.checked );
				}
				// remove the em-type- classes and add the current selected type, defaulting to single
				form.classList.remove( ...[ ...form.classList ].filter( className => className.startsWith( 'em-type-' ) ) );
				form.classList.add( 'em-type-' + eventType.value );
			}
			return isRecurring;
		}
		handleRecurring();
	});


	// Add click handler for recurrence conversion links
	document.querySelectorAll( '.em-convert-recurrence-link' ).forEach( link => {
		link.addEventListener( 'click', function ( e ) {
			if ( !confirm( EM.convert_recurring_warning ) ) {
				e.preventDefault();
				return false;
			}
			let nonce = this.getAttribute( 'data-nonce' );
			if ( nonce ) {
				this.href = this.href.replace( 'nonce=x', 'nonce=' + nonce );
			}
		} );
	} );

	document.dispatchEvent( new CustomEvent('em_event_editor_loaded') );
});