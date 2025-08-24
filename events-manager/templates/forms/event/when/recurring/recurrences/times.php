<?php
// The following are included in the scope of this recurrence time range picker
/* @var int $id */
/* @var int $type */
/* @var int $i */
/* @var EM\Recurrences\Recurrence_Set $Recurrence_Set */
$hours_format = em_get_option('dbem_time_24h') ? 'G:i':'g:i A';
$start_time_formatted = $Recurrence_Set->start->valid ? $Recurrence_Set->start->format($hours_format) : '';
$end_time_formatted = $Recurrence_Set->end->valid ? $Recurrence_Set->end->format($hours_format) : '';
$placeholder_start = $start_time_formatted ?: esc_attr__('Start Time', 'events-manager');
$placeholder_end = $end_time_formatted ?: esc_attr__('End Time', 'events-manager');
$start = $Recurrence_Set->recurrence_start_time;
$end = $Recurrence_Set->recurrence_end_time;
$undo_start = $start ? $start_time_formatted : '';
$undo_end = $end ? $end_time_formatted : '';
?>
<div class="em-recurrence-times em-time-range">
	<fieldset class="inline">
		<legend data-nostyle><?php _e('Times','events-manager'); ?></legend>
		<label class="inline" data-nostyle>
			<span class="screen-reader-text"><?php esc_html_e('Start Time', 'events-manager'); ?></span>
			<input class="em-time-input em-time-start inline recurrence_start_time" type="text" size="8" maxlength="8" name="recurrences[<?php echo esc_attr($type); ?>][<?php echo esc_attr($i); ?>][recurrence_start_time]" value="<?php echo $start; ?>" placeholder="<?php echo $placeholder_start; ?>" data-placeholder="<?php esc_html_e('Start Time', 'events-manager'); ?>" data-undo="<?php echo esc_attr($undo_start); ?>" >
		</label>
		<?php _e('to','events-manager'); ?>
		<label class="inline" data-nostyle>
			<span class="screen-reader-text"><?php esc_html_e('End Time', 'events-manager'); ?></span>
			<input class="em-time-input em-time-end inline recurrence_end_time" type="text" size="8" maxlength="8" name="recurrences[<?php echo esc_attr($type); ?>][<?php echo esc_attr($i); ?>][recurrence_end_time]" value="<?php echo $end; ?>" placeholder="<?php echo $placeholder_end; ?>" data-placeholder="<?php esc_html_e('End Time', 'events-manager'); ?>" data-undo="<?php echo esc_attr($undo_end); ?>" >
		</label>
	</fieldset>
	<label class="inline" data-nostyle>
		<span><?php esc_html_e('All day','events-manager'); ?></span>
		<input type="checkbox" class="em-time-all-day recurrence_time_allday" name="recurrences[<?php echo esc_attr($type); ?>][<?php echo esc_attr($i); ?>][recurrence_time_allday]" value="1" <?php echo $Recurrence_Set->recurrence_all_day ? 'checked="checked"' : ''; ?> data-undo="<?php echo $Recurrence_Set->recurrence_all_day ? 1:0; ?>" >
	</label>
</div>