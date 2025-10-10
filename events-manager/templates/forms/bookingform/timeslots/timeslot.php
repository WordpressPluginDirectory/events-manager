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
/* @var mixed $scope */
/* @var mixed $scope */
$can_book = $EM_Event->get_bookings()->is_open();
$event_id = $EM_Event->event_id;
if ( $can_book ) {
	?>
	<a href="#<?php echo $EM_Event->start()->getDate() . '@' . $EM_Event->start()->getTime(); ?>" class="em-booking-recurrence em-booking-timeslot em-item em-button button-secondary" <?php if ( !$can_book ) echo 'disabled'; ?> data-event="<?php echo $event_id; ?>">
		<?php echo $EM_Event->output('#_12HSTARTTIME'); ?>
	</a>
	<?php
}