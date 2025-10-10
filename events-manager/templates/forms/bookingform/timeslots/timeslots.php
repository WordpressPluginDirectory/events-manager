<?php
/**
 * This template will display bookings for a reurring event, by showing a list or calendar
 */
/* @var EM_Event $EM_Event */
/* @var bool $id */
$id = $id ?? $EM_Event->event_id;
$scope = $scope ?? $EM_Event->event_start_date;
$timezone = $timezone ?? $EM_Event->event_timezone;
$Timeslots = $EM_Event->get_timeranges()->get_timeslots();
?>
<div id="em-booking-timeslots-<?php echo $id; ?>" class="em-booking-recurrences em-booking-timeslots" data-date="<?php echo esc_attr($scope); ?>">
	<?php
		if ( !empty($Timeslots) ) {
			if( $EM_Event->get_option('dbem_bookings_timeslots_timezone_picker', false) && ( $EM_Event->get_option('dbem_timezone_enabled') || $EM_Event->event_timezone !== $EM_Event->get_option('timezone_string') ) ): ?>
				<p class="em-timezone">
					<label for="recurrence-timezone-<?php echo $id; ?>"><span class="em-icon em-icon-map"></span>&nbsp;&nbsp;<?php esc_html_e('Timezone', 'events-manager'); ?></label>
					<select id="recurrence-timezone-<?php echo $id; ?>" name="recurrence_timezone" class="em-selectize recurrence_timezone">
						<?php echo wp_timezone_choice( $EM_Event->get_timezone()->getValue(), get_user_locale() ); ?>
					</select>
				</p>
			<?php endif; ?>
			<div class="em-booking-timeslots-list">
				<?php
					foreach ( $Timeslots as $Timeslot ) {
						$EM_Event = $Timeslot->get_event();
						$template_vars = $EM_Event->get_bookings()->get_booking_vars();
						if ( $Timeslot->timeslot_status !== 0 ) {
							em_locate_template( 'forms/bookingform/timeslots/timeslot.php', true, $template_vars );
						}
					}
				?>
			</div>
			<?php
		} else {
			?>
			<div class="no-recurrences">
				<?php esc_html_e('No upcoming dates/times.', 'events-manager'); ?>
			</div>
			<?php
		}
	?>
</div>