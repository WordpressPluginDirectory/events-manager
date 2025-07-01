document.addEventListener('em_event_editor_recurrences', function( e ) {
	let recurrenceSets = e.detail.recurrenceSets;

	document.addEventListener('em_setup_ui_elements', function(e) {
		// clean up template of UI elements so they can be rebuilt when cloned
		if ( e.detail.container === document ) {
			let template = recurrenceSets.querySelector('.em-recurrence-set-template');
			em_unsetup_ui_elements( template );
		}
		recurrenceSets.dispatchEvent( new CustomEvent('setAdvancedDefaults') );
		recurrenceSets.dispatchEvent( new CustomEvent('setDateTimes') );
	});

	// track selectize changes
	// Add change handlers for selectize dropdowns in first recurrence set
	document.addEventListener('em_setup_ui_elements', function( e ) {
		if ( e.detail.container === document ) {
			// Get the first recurrence set
			let firstRecurrenceSet = recurrenceSets.querySelector('.em-recurrence-type-include .em-recurrence-set:first-child');

			// Map of recurrence field selectors to event field selectors
			const fieldMappings = {
				'.em-recurrence-timezone select': 'select[name="event_timezone"]',
				'.em-recurrence-status select': 'select[name="event_active_status"]'
			};

			// Handle each field type
			Object.entries(fieldMappings).forEach( function([recurrenceSelector, eventSelector]) {
				let recurrenceField = firstRecurrenceSet.querySelector(recurrenceSelector);

				// Find the corresponding event field
				let eventFormWhen = recurrenceSets.closest('form').querySelector('.event-form-when');
				let eventField = eventFormWhen?.querySelector(eventSelector);

				if ( recurrenceField && eventField ) {
					// Set up change handler using selectize API if available
					if (recurrenceField.selectize) {
						// For selectize fields
						recurrenceField.selectize.on('change', function (value) {
							if (eventField.selectize) {
								// If event field is also selectize
								eventField.selectize.setValue(value, true);
							} else {
								// If event field is a regular select
								eventField.value = value;
								eventField.dispatchEvent(new Event('change', {bubbles: true}));
							}
						});
					} else {
						// Fallback for regular select fields
						recurrenceField.addEventListener('change', function () {
							if (eventField.selectize) {
								eventField.selectize.setValue(recurrenceField.value, true);
							} else {
								eventField.value = recurrenceField.value;
								eventField.dispatchEvent(new Event('change', {bubbles: true}));
							}
						});
					}

					// Also handle initial sync (if recurrence field already has a value)
					let initialValue = recurrenceField.selectize ? recurrenceField.selectize.getValue() : recurrenceField.value;
					if (initialValue) {
						if (eventField.selectize) {
							eventField.selectize.setValue(initialValue, true);
						} else {
							eventField.value = initialValue;
						}
					}
				}
			});
		}
	});
});