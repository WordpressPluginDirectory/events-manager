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
?>
<a href="#<?php echo $EM_Event->start()->getDate() . '@' . $EM_Event->start()->getTime(); ?>" class="em-booking-recurrence em-item em-button button-secondary" <?php if ( !$can_book ) echo 'disabled'; ?> data-event="<?php echo $event_id; ?>">
	<?php if ( !empty($multiday) ): ?>
	<div class="em-booking-recurrence-date">
		<span class="em-icon em-icon-calendar"></span><span><?php echo $EM_Event->output('#_EVENTDATES'); ?></span>
	</div>
	<?php endif; ?>
	<div class="em-booking-recurrence-time">
		<span class="em-icon em-icon-clock"></span><span><?php echo $EM_Event->output('#_EVENTTIMES'); ?></span>
	</div>
	<div class="em-booking-recurrence-spaces">
		<?php if( $already_booked && !em_get_option('dbem_bookings_double') ): //Double bookings not allowed ?>
			<?php do_action('em_booking_form_status_already_booked', $EM_Event); // do not delete ?>
		<?php elseif( !$EM_Event->event_rsvp ): //bookings not enabled ?>
			<?php do_action('em_booking_form_status_disabled', $EM_Event); // do not delete ?>
		<?php elseif( $EM_Event->event_active_status === 0 ): //event is cancelled ?>
			<?php do_action('em_booking_form_status_cancelled', $EM_Event); // do not delete ?>
		<?php elseif( $EM_Event->get_bookings()->get_available_spaces() <= 0 && !EM_Bookings::$disable_restrictions ): ?>
			<?php esc_html_e('Fully Booked') ?>
		<?php elseif( !$is_open ): //event has started ?>
			<?php do_action('em_booking_form_status_closed', $EM_Event); // do not delete ?>
		<?php else : ?>
			<span class=""></span>
			<?php
			$available_spaces = $EM_Event->get_bookings()->get_available_spaces();
			printf( _n('%d space', '%d spaces', $available_spaces, 'events-manager'), $available_spaces );
			?>
		<?php endif; ?>
	</div>
</a>