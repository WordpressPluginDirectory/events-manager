document.addEventListener('em_event_editor_recurrences', function( e ) {
	let recurrenceSets = e.detail.recurrenceSets;

	// shortcuts for functions in recurrences/ui-functions.js
	let updateRecurrenceSummary = ( recurrenceSet ) => recurrenceSet.dispatchEvent( new CustomEvent('updateRecurrenceSummary', { bubbles: true }) );
	let updateSetsCount = () => recurrenceSets.dispatchEvent( new CustomEvent('updateSetsCount') );
	let updateRecurrenceOrder = () => recurrenceSets.dispatchEvent( new CustomEvent('updateRecurrenceOrder') );


	/* ------------------------------------------------------------
	 Add/Remove Recurrnces
	 ------------------------------------------------------------ */

	// ADD NEW RECURRENCE RULE
	let addRecurrence = function ( recurrenceType ) {
		let recurrenceTypeSets = recurrenceSets.querySelector('.em-recurrence-type-' + recurrenceType + ' .em-recurrence-type-sets');

		// Count existing recurrence sets to determine the new index.
		let index = recurrenceTypeSets.querySelectorAll('.em-recurrence-set').length + 1;

		// Clone the template (deep clone).
		let recurrenceSet = recurrenceSets.querySelector('.em-recurrence-set-template').cloneNode(true);

		// Remove the 'hidden' class and template-specific class; add the active class.
		recurrenceSet.classList.remove('em-recurrence-set-template', 'hidden');
		recurrenceSet.classList.add('em-recurrence-set', 'new-recurrence-set');
		recurrenceSet.querySelector('.em-recurrence-set-type').value = recurrenceType;
		recurrenceSet.dataset.type = recurrenceType;
		recurrenceSet.dataset.index = index;

		// Replace all occurrences of "[N%]" with the new index.
		recurrenceSet.innerHTML = recurrenceSet.innerHTML.replace(/T%/g, `${recurrenceType}`);
		recurrenceSet.innerHTML = recurrenceSet.innerHTML.replace(/N%/g, `${index}`);

		if ( recurrenceType === 'exclude' ) {
			recurrenceSet.querySelectorAll('.em-recurrence-advanced .only-include-type').forEach(el => el.remove() );
		}
		if ( recurrenceType === 'include' ) {
			recurrenceSet.querySelectorAll('.em-recurrence-advanced .only-exclude-type').forEach(el => el.remove() );
		}

		// anything more before we append
		let result = { recurrenceSet: recurrenceSet, index: index, success: true };
		recurrenceTypeSets.dispatchEvent( new CustomEvent('beforeAddRecurrence', { detail: result  }) );

		if ( result.success ) {
			// Insert the new recurrence set above the template.
			recurrenceTypeSets.append(recurrenceSet);
			em_setup_ui_elements( recurrenceSet );
			// run all checks
			updateSetsCount();
			emRecurrenceEditor.updateIntervalDescriptor( recurrenceSet );
			emRecurrenceEditor.updateIntervalSelectors( recurrenceSet );
			emRecurrenceEditor.updateDurationDescriptor( recurrenceSet );
			updateRecurrenceSummary( recurrenceSet );
		}

		return recurrenceSet;
	}
	// add include recurrence
	recurrenceSets.querySelectorAll('.em-add-recurrence-set[data-type="include"]').forEach( function( addButton ){
		addButton.addEventListener( 'click', () => addRecurrence('include') );
	});
	// set up listner to add recurrences, exclude and include, the exclude trigger is in reschedule.js
	recurrenceSets.querySelectorAll('.em-recurrence-type').forEach( function( recurrenceSetsType ){
		recurrenceSetsType.addEventListener( 'addRecurrence', function( e ) {
			e.detail.recurrenceSet = addRecurrence( recurrenceSetsType.dataset.type );
		});
	});

	// REMOVE A RECURRENCE RULE
	recurrenceSets.addEventListener('click', function ( e ) {
		if ( e.target.matches('.em-recurrence-set-action-remove') ) {
			let removeButton = e.target;
			e.preventDefault();

			// Locate the recurrence set container for this remove action.
			let recurrenceSet = removeButton.closest('.em-recurrence-set');

			// Find the hidden delete field (an input whose name contains "[delete]").
			let setId = recurrenceSet.querySelector('input.em-recurrence-set-id');
			let deleteField = recurrenceSet.querySelector('input.em-recurrence-set-delete-field');
			if (setId && deleteField) {
				// Transfer the data-nonce value to the hidden input's value.
				deleteField.value = deleteField.getAttribute('data-nonce');

				// Move the delete field out of the recurrence set into the recurrence container.
				recurrenceSets.appendChild(deleteField);
				recurrenceSets.appendChild(setId);
			}

			// Remove the entire recurrence set from the DOM.
			recurrenceSet.remove();
			// redo all checks
			updateSetsCount();
			updateRecurrenceOrder();
		}
	});
});