<?php
/**
 * This template will display bookings for a reurring event, by showing a list or calendar
 */
/* @var EM_Event $EM_Event */
/* @var EM_Booking $EM_Booking booking intent */
/* @var bool $tickets_count */
/* @var bool $available_tickets_count */
/* @var bool $can_book */
/* @var bool $is_open whether there are any available tickets right now */
/* @var bool $is_free */
/* @var bool $show_tickets */
/* @var bool $id */
/* @var bool $already_booked */
$id = $EM_Event->event_id;
$Timeslots = $EM_Event->get_timeranges()->get_timeslots();
$time_format = esc_attr( $EM_Event->get_option('dbem_time_format') );
$scope = $EM_Event->start()->getDate();
?>
<?php if( $already_booked && !em_get_option('dbem_bookings_double') ): //Double bookings not allowed ?>
	<?php do_action('em_booking_form_status_already_booked', $EM_Event); // do not delete ?>
<?php elseif( !$EM_Event->event_rsvp ): //bookings not enabled ?>
	<?php do_action('em_booking_form_status_disabled', $EM_Event); // do not delete ?>
<?php elseif( $EM_Event->event_active_status === 0 ): //event is cancelled ?>
	<?php do_action('em_booking_form_status_cancelled', $EM_Event); // do not delete ?>
<?php elseif( $EM_Event->get_bookings()->get_available_spaces() <= 0 && !EM_Bookings::$disable_restrictions ): ?>
	<?php do_action('em_booking_form_status_full', $EM_Event); // do not delete ?>
<?php elseif( !$is_open ): //event has started ?>
	<?php do_action('em_booking_form_status_closed', $EM_Event); // do not delete ?>
<?php else: ?>
<section class="em em-event-booking-form em-booking-recurring em-booking-event-timeslots" data-event="<?php echo $id; ?>">
	<?php if( $EM_Event->get_option('dbem_bookings_header_timeslots') ): ?>
		<h3 class="em-booking-section-title em-booking-form-timeslots-title"><?php echo esc_html( $EM_Event->get_option('dbem_bookings_header_timeslots') ); ?></h3>
	<?php endif; ?>
	<?php if ( $EM_Event->get_option('dbem_event_timeslots_picker', 'buttons') === 'select' ) : ?>
		<div class="em-booking-recurrence-picker em-booking-timeslot-picker mode-<?php echo esc_attr( $EM_Event->get_option('dbem_recurrence_picker' ) ); ?>">
			<select class="em-selectize" name="booking_recurrence_selection">
				<option value="0"><?php esc_html_e('Select a time', 'events-manager'); ?></option>
				<?php foreach( $Timeslots as $Timeslot ) : ?>
					<?php if ( $Timeslot->timeslot_status === 0 ) continue; ?>
					<option value="<?php echo $id . ':' . absint( $Timeslot->timeslot_id ); ?>"><?php echo $Timeslot->start->i18n( $time_format ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	<?php else: ?>
		<div class="em-booking-recurrence-picker em-booking-timeslot-picker mode-<?php echo esc_attr( $EM_Event->get_option('dbem_timeslots_picker', 'buttons' ) ); ?>" data-nonce="<?php echo wp_create_nonce('booking_recurrences'); ?>">
			<?php include em_locate_template( 'forms/bookingform/timeslots/timeslots.php' ); ?>
		</div>
		<?php include em_locate_template( 'forms/bookingform/timeslots/timeslots-skeleton.php' ); ?>
	<?php endif; ?>

	<div id="em-booking-timeslots-form-<?php echo $id; ?>" class="em-booking-recurrence-form em-booking-timeslot-form" data-nonce="<?php echo wp_create_nonce('booking_form'); ?>">
		<!-- booking form will go here -->
	</div>
	<?php include em_locate_template( 'forms/bookingform/summary-skeleton.php' ); ?>
</section>
<?php endif; ?>