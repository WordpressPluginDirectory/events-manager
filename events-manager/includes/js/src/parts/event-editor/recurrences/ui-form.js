
document.addEventListener('em_event_editor_recurrences', function( e ) {
	let recurrenceSets = e.detail.recurrenceSets;

	// Attach delegated listeners for interval/duration inputs and frequency select within this container
	recurrenceSets.addEventListener('keyup', function (e) {
		if ( e.target.matches('input.em-recurrence-interval') ) {
			emRecurrenceEditor.updateIntervalDescriptor( e.target.closest('.em-recurrence-set') );
		} else if (e.target.matches('input.em-recurrence-duration')) {
			emRecurrenceEditor.updateDurationDescriptor( e.target.closest('.em-recurrence-set') );
		}
	});

	// recurrency descriptors and selectors that change upon frequency changes
	recurrenceSets.addEventListener('change', function (e) {
		if (e.target.matches('select.em-recurrence-frequency')) {
			let recurrenceSet = e.target.closest('.em-recurrence-set');
			emRecurrenceEditor.updateIntervalDescriptor( recurrenceSet );
			emRecurrenceEditor.updateIntervalSelectors( recurrenceSet );
		}
	});
});

document.addEventListener('DOMContentLoaded', function() {
	//Event Editor
	// Recurrence Warnings
	document.querySelectorAll('form.em-event-admin-recurring').forEach(form => {
		form.addEventListener('submit', function (event) {
			let warning_text;
			let recreateInput = form.querySelector('input[name="event_recreate_tickets"]');

			if (recreateInput && recreateInput.value === "1") {
				warning_text = EM.event_recurrence_bookings;
			}

			if ( warning_text && !confirm(warning_text) ) {
				event.preventDefault();
			}
		});
	});

	//Buttons for recurrence warnings within event editor forms
	document.querySelectorAll('.em-reschedule-trigger, .em-reschedule-cancel').forEach(trigger => {
		trigger.addEventListener('click', e => {
			e.preventDefault();
			const el = e.currentTarget;
			const show = el.matches('.em-reschedule-trigger');
			el.closest('.em-recurrence-reschedule')?.querySelector(el.dataset.target)?.classList.toggle('reschedule-hidden', !show);
			el.parentElement.querySelectorAll('[data-nonce]').forEach( el => { el.disabled = !show } );
			el.parentElement.querySelectorAll('button').forEach( link => link.classList.remove('reschedule-hidden') );
			el.classList.add('reschedule-hidden');
		});
	});
});