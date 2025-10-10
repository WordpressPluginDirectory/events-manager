<?php
/**
 * This template will display bookings for a reurring event, by showing a list or calendar
 */
/* @var EM_Event $EM_Event */
/* @var bool $id */
$id = $id ?? $EM_Event->event_id;
$scope = $scope ?? 'future';
$timezone = $timezone ?? $EM_Event->event_timezone;
$multiday = preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $scope ) ? false : 'has-dates';
if ( $multiday  ) {
	$title = esc_html( sprintf(__('Upcoming %s', 'events-manager'), esc_html__('Events', 'events-manager')) );
	$recurrences = EM_Events::get( [ 'recurrence' => $EM_Event->event_id, 'scope' => $scope, 'timezone_scope' => $timezone, 'limit' => $scope == 'future' ? 3 : false, 'timeslots' => $EM_Event->has_timeslots() ] );
} else {
	// output the date
	$title = date( em_get_date_format(), strtotime( $scope ) );
	if ( $EM_Event->is_recurring() ) {
		$recurrences = EM_Events::get( [ 'recurrence' => $EM_Event->event_id, 'scope' => $scope, 'timezone_scope' => $timezone, 'limit' => false, 'timeslots' => $EM_Event->has_timeslots() ] );
	} else {
		$recurrences = EM_Events::get( [ 'event' => $EM_Event->event_id, 'scope' => $scope, 'timezone_scope' => $timezone, 'limit' => false, 'timeslots' => $EM_Event->has_timeslots() ] );
	}
}
?>
<div id="em-booking-recurrences-<?php echo $id; ?>" class="em-booking-recurrences <?php echo $multiday; ?>" data-date="<?php echo esc_attr($scope); ?>">
	<?php if ( !empty($title) ) : ?>
	<h3><?php echo esc_html($title); ?></h3>
	<?php endif; ?>
	<?php
		if ( !empty($recurrences) ) {
			if( em_get_option('dbem_timezone_enabled') || $EM_Event->event_timezone !== em_get_option('timezone_string') ): ?>
			<p class="em-timezone">
				<label for="recurrence-timezone-<?php echo $id; ?>"><span class="em-icon em-icon-map"></span>&nbsp;&nbsp;<?php esc_html_e('Timezone', 'events-manager'); ?></label>
				<select id="recurrence-timezone-<?php echo $id; ?>" name="recurrence_timezone" class="em-selectize recurrence_timezone">
					<?php echo wp_timezone_choice( $EM_Event->get_timezone()->getValue(), get_user_locale() ); ?>
				</select>
			</p>
			<?php endif; ?>
			<?php
			foreach ( $recurrences as $EM_Event ) {
				$EM_Event->set_timezone($timezone, false);
				$template_vars = $EM_Event->get_bookings()->get_booking_vars();
				$template_vars['id'] = $id ?? $EM_Event->event_id;
				$template_vars['multiday'] = $multiday;
				$template_vars['scope'] = $scope;
				em_locate_template( 'forms/bookingform/recurring/booking-recurrence.php', true, $template_vars );
			}
			if ( $multiday ) {
				?>
				<p class="more-recurrenes">
					<?php esc_html_e('Find more dates from the calendar.', 'events-manager'); ?>
				</p>
				<?php
			}
		}
		if ( !$recurrences ) {
			?>
			<div class="no-recurrences">
				<?php esc_html_e('No upcoming dates/times.', 'events-manager'); ?>
			</div>
			<?php
		}
	?>
</div>