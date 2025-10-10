<?php
// admin modal notices
class EM_Admin_Modals {
	
	public static $output_js = false;
	
	public static function init() {
		add_filter('admin_enqueue_scripts', 'EM_Admin_Modals::admin_enqueue_scripts', 100);
		add_filter('wp_ajax_em-admin-popup-modal', 'EM_Admin_Modals::ajax');
		add_filter('em_admin_notice_review-nudge_message', 'EM_Admin_Modals::review_notice');
		//add_filter('em_admin_notice_newsletter-signup_message', 'EM_Admin_Modals::newsletter_notice');
		add_filter( 'em_admin_notice_promo-popup_message', 'EM_Admin_Modals::promo_notice' );
		add_filter( 'em_admin_notice_expired-reminder_message', 'EM_Admin_Modals::expired_reminder_notice' );
		add_filter( 'em_admin_notice_expiry-reminder_message', 'EM_Admin_Modals::expiry_reminder_notice' );
	}
	
	public static function admin_enqueue_scripts(){
		if( !current_user_can('update_plugins') ) return;
		// show modal
		$data = is_multisite() ? get_site_option('dbem_data') : em_get_option('dbem_data');
		if( !empty($data['admin-modals']) ){
			$show_plugin_pages = !empty($_REQUEST['post_type']) && \EM\Archetypes::is_valid_cpt( $_REQUEST['post_type'] );
			$show_network_admin = is_network_admin() && !empty($_REQUEST['page']) && preg_match('/^events\-manager\-/', $_REQUEST['page']);
			// show review nudge
			if( !empty($data['admin-modals']['review-nudge']) && $data['admin-modals']['review-nudge'] < time() ) {
				if( $show_plugin_pages || $show_network_admin ) {
					// check it hasn't been shown more than 3 times, if so revert it to a regular admin notice
					if( empty($data['admin-modals']['review-nudge-count']) ){
						$data['admin-modals']['review-nudge-count'] = 0;
					}
					if( $data['admin-modals']['review-nudge-count'] < 3 ) {
						// enqueue script and load popup action
						if ( ! wp_script_is( 'events-manager-admin' ) ) {
							EM_Scripts_and_Styles::admin_enqueue( true );
						}
						add_filter( 'admin_footer', 'EM_Admin_Modals::review_popup' );
						$data['admin-modals']['review-nudge-count']++;
						update_site_option('dbem_data', $data);
					}else{
						// move it into a regular admin notice and stop displaying
						unset($data['admin-modals']['review-nudge-count']);
						unset($data['admin-modals']['review-nudge']);
						update_site_option('dbem_data', $data);
						// notify user of new update
						$EM_Admin_Notice = new EM_Admin_Notice(array( 'name' => 'review-nudge', 'who' => 'admin', 'where' => 'plugin' ));
						EM_Admin_Notices::add($EM_Admin_Notice, is_multisite());
					}
				}
			}
			
			// show newsletter signup
			if( !empty($data['admin-modals']['newsletter-signup']) && $data['admin-modals']['newsletter-signup'] < time() ) {
				if( $show_plugin_pages || $show_network_admin ) {
					// check it hasn't been shown more than 3 times, if so revert it to a regular admin notice
					if( empty($data['admin-modals']['newsletter-signup-count']) ){
						$data['admin-modals']['newsletter-signup-count'] = 0;
					}
					if( $data['admin-modals']['newsletter-signup-count'] < 3 ) {
						// enqueue script and load popup action
						if ( ! wp_script_is( 'events-manager-admin' ) ) {
							EM_Scripts_and_Styles::admin_enqueue( true );
						}
						add_filter( 'admin_footer', 'EM_Admin_Modals::newsletter_popup' );
						$data['admin-modals']['newsletter-signup-count']++;
						update_site_option('dbem_data', $data);
					}else{
						// move it into a regular admin notice and stop displaying
						unset($data['admin-modals']['newsletter-signup-count']);
						unset($data['admin-modals']['newsletter-signup']);
						update_site_option('dbem_data', $data);
						// notify user of new update
						$EM_Admin_Notice = new EM_Admin_Notice(array( 'name' => 'newsletter-signup', 'who' => 'admin', 'where' => 'plugin' ));
						EM_Admin_Notices::add($EM_Admin_Notice, is_multisite());
					}
				}
			}
			
			// promo
			$pro_license_active = defined('EMP_VERSION');
			if( $pro_license_active ){
				$key = em_get_option('dbem_pro_api_key');
				$has_lifetime_already = $key && date('Y', $key['until'] ?? time() ) === '2125';
			}
			if( !empty($data['admin-modals']['promo-popup']) && empty($has_lifetime_already) ) {
				if( $data['admin-modals']['promo-popup'] && ($show_plugin_pages || $show_network_admin) ) {
					// enqueue script and load popup action
					if( empty($data['admin-modals']['promo-popup-count']) ){
						$data['admin-modals']['promo-popup-count'] = 0;
					}
					if( $data['admin-modals']['promo-popup-count'] < 1 ) {
						if( !wp_script_is('events-manager-admin') ) EM_Scripts_and_Styles::admin_enqueue(true);
						add_filter('admin_footer', 'EM_Admin_Modals::promo_popup');
						$data['admin-modals']['promo-popup-count']++;
						update_site_option('dbem_data', $data);
					}else{
						// move it into a regular admin notice and stop displaying
						unset($data['admin-modals']['promo-popup-count']);
						unset($data['admin-modals']['promo-popup']);
						update_site_option('dbem_data', $data);
						// notify user of new update
						$EM_Admin_Notice = new EM_Admin_Notice(array( 'name' => 'promo-popup', 'who' => 'admin', 'where' => 'plugin' ));
						EM_Admin_Notices::add($EM_Admin_Notice, is_multisite());
					}
				}
			}
		}
		
		// EM Pro License Expired Promo & Reminder
		$pro_license_active = defined('EMP_VERSION');
		$promo_time = 1729857600;
		if( $pro_license_active ){
			$key = em_get_option('dbem_pro_api_key');
			// add a promo for license
			$license_expired = empty($key['until']) || $key['until'] < time();
			if( $license_expired ) {
				if( time() < $promo_time && !EM_Options::get('license_expiry_promo') ) {
					EM_Options::set('license_expiry_promo', true);
					$EM_Admin_Notice = new EM_Admin_Notice(array( 'name' => 'expired-promo', 'who' => 'admin', 'where' => 'all' ));
					EM_Admin_Notices::add($EM_Admin_Notice, is_multisite());
				} elseif( time() > $promo_time ) {
					// promo over, remove data
					EM_Options::remove('license_expiry_promo');
					EM_Admin_Notices::remove('expired-promo');
					if( !EM_Options::get('license_expired_reminder') ) {
						EM_Options::set('license_expired_reminder', true);
						$EM_Admin_Notice = new EM_Admin_Notice(array( 'name' => 'expired-reminder', 'who' => 'admin', 'where' => 'all' ));
						EM_Admin_Notices::add($EM_Admin_Notice, is_multisite());
						// remove others
						if ( EM_Options::get('license_expiry_promo') ) {
							EM_Options::remove( 'license_expiry_promo' );
							EM_Admin_Notices::remove('expired-promo');
						}
						if ( EM_Options::get('license_expiry_reminder') ) {
							EM_Options::remove( 'license_expiry_reminder' );
							EM_Admin_Notices::remove('expiry-reminder');
						}
					}
				}
			}
			// add reminder for expiring
			if( !empty($key['until']) && !$license_expired ) {
				if( $key['until'] < strtotime('+14 days') ) {
					if( !EM_Options::get('license_expiry_reminder') ) {
						EM_Options::set('license_expiry_reminder', true);
						$EM_Admin_Notice = new EM_Admin_Notice(array( 'name' => 'expiry-reminder', 'who' => 'admin', 'where' => 'all' ));
						EM_Admin_Notices::add($EM_Admin_Notice, is_multisite());
						// reset others
						EM_Options::remove('license_expired_reminder');
						EM_Admin_Notices::remove('expired-reminder');
						EM_Options::remove('license_expiry_promo');
						EM_Admin_Notices::remove('expired-promo');
					}
				} else {
					// remove all
					if ( EM_Options::get('license_expiry_reminder') ) {
						EM_Options::remove('license_expiry_reminder');
						EM_Admin_Notices::remove('expiry-reminder');
					}
					if ( EM_Options::get('license_expiry_promo') ) {
						EM_Options::remove( 'license_expiry_promo' );
						EM_Admin_Notices::remove('expired-promo');
					}
					if ( EM_Options::get('license_expired_reminder') ) {
						EM_Options::remove( 'license_expired_reminder' );
						EM_Admin_Notices::remove('expired-reminder');
					}
				}
			}
		}
	}
	
	public static function review_popup(){
		// check admin data and see if show data is still enabled
		?>
		<div class="em pixelbones em-modal <?php em_template_classes('search', 'search-advanced'); ?> em-admin-modal" id="em-review-nudge" data-nonce="<?php echo wp_create_nonce('em-review-nudge'); ?>">
			<div class="em-modal-popup">
				<header>
					<div class="em-modal-title"><?php esc_html_e('Enjoying Events Manager? Help Us Improve!', 'events-manager'); ?></div>
				</header>
				<div class="em-modal-content has-image">
					<div>
						<p><?php esc_html_e('Pardon the interruption... we hope you\'re enjoying Events Manager, and if so, we\'d really appreciate a positive review on the wordpress.org repository!', 'events-manager'); ?></p>
						<p><?php esc_html_e('Events Manager has been maintained, developed and supported for free since it was released in 2008, positive reviews are one that help us keep going.', 'events-manager'); ?></p>
						<p><?php esc_html_e('If you could spare a few minutes, we would appreciate it if you could please leave us a review.', 'events-manager'); ?></p>
					</div>
					<div class="image">
						<img src="<?php echo EM_DIR_URI . '/includes/images/star-halo.svg'; ?>" style="width:75%; opacity:0.7;">
						<img src="<?php echo EM_DIR_URI . '/includes/images/events-manager.svg'; ?>">
					</div>
				</div><!-- content -->
				<footer class="em-submit-section input">
					<div>
						<button class="button button-secondary dismiss-modal"><?php esc_html_e('Dismiss Message', 'events-manager'); ?></button>
					</div>
					<div>
						<a href="https://wordpress.org/support/plugin/events-manager/reviews/?filter=5#new-topic-0" class="button button-primary input" target="_blank" style="margin:10px auto; --accent-color:#429543; --accent-color-hover:#429543;">
							Leave a Review
							<img src="<?php echo EM_DIR_URI . '/includes/images/five-stars.svg'; ?>" style="max-height:10px; width:50px; margin-left:5px;">
						</a>
					</div>
				</footer>
			</div><!-- modal -->
		</div>
		<?php
		static::output_js();
	}
	
	public static function review_notice(){
		ob_start();
		?>
		<div style="display: grid; grid-template-columns: 80px auto; grid-gap: 20px;">
			<div style="align-self: center; text-align: center; padding-left: 10px;">
				<img src="<?php echo EM_DIR_URI . '/includes/images/star-halo.svg'; ?>" style="width:75%; opacity:0.7;">
				<img src="<?php echo EM_DIR_URI . '/includes/images/events-manager.svg'; ?>" style="width: 100%;">
			</div>
			<div>
				<p><?php esc_html_e('Pardon the interruption... we hope you\'re enjoying Events Manager, and if so, we\'d really appreciate a positive review on the wordpress.org repository!', 'events-manager'); ?></p>
				<p>
					<?php esc_html_e('Events Manager has been maintained, developed and supported for free since it was released in 2008, positive reviews are one that help us keep going.', 'events-manager'); ?>
					<?php esc_html_e('If you could spare a few minutes, we would appreciate it if you could please leave us a review.', 'events-manager'); ?>
				</p>
				<a href="https://wordpress.org/support/plugin/events-manager/reviews/?filter=5#new-topic-0" class="button button-primary input" target="_blank" style="margin:10px 10px 10px 0; --accent-color:#429543; --accent-color-hover:#429543;">
					Leave a Review
					<img src="<?php echo EM_DIR_URI . '/includes/images/five-stars.svg'; ?>" style="max-height:10px; width:50px; margin-left:5px;">
				</a>
				<a href="<?php echo esc_url( admin_url('admin-ajax.php?action=em_dismiss_admin_notice&notice=review-nudge&redirect=1' ) ); ?>" class="button button-secondary" style="margin:10px 0;"><?php esc_html_e('Dismiss', 'events-manager'); ?></a>
			</div>
		</div><!-- content -->
		<?php
		return ob_get_clean();
	}
	
	public static function newsletter_popup(){
		// check admin data and see if show data is still enabled
		?>
		<div class="em pixelbones em-modal <?php em_template_classes('search', 'search-advanced'); ?> em-admin-modal" id="em-newsletter-signup" data-nonce="<?php echo wp_create_nonce('em-newsletter-signup'); ?>">
			<div class="em-modal-popup">
				<header>
					<a class="em-close-modal dismiss-modal" href="#"></a><!-- close modal -->
					<div class="em-modal-title"><?php esc_html_e('Subscribe to Our Newsletter', 'events-manager'); ?></div>
				</header>
				<div class="em-modal-content has-image">
					<div>
						<p><?php esc_html_e('Stay updated with the latest features, tips, and news about Events Manager!', 'events-manager'); ?></p>
						<p><?php esc_html_e('Subscribe to our newsletter to receive exclusive content, early access to new features, and helpful event management tips directly to your inbox.', 'events-manager'); ?></p>
						
						<form action="https://your-mailchimp-signup-url.com" method="post" id="mc-embedded-subscribe-form" name="mc-embedded-subscribe-form" class="validate" target="_blank">
							<div style="margin-bottom: 15px;">
								<label for="mce-EMAIL"><?php esc_html_e('Email Address', 'events-manager'); ?> <span class="asterisk">*</span></label>
								<input type="email" value="" name="EMAIL" class="required email" id="mce-EMAIL" style="width: 100%; padding: 8px; margin-top: 5px;">
							</div>
							<div style="margin-bottom: 15px;">
								<label for="mce-FNAME"><?php esc_html_e('First Name', 'events-manager'); ?></label>
								<input type="text" value="" name="FNAME" id="mce-FNAME" style="width: 100%; padding: 8px; margin-top: 5px;">
							</div>
							<!-- real people should not fill this in and expect good things - do not remove this or risk form bot signups-->
							<div style="position: absolute; left: -5000px;" aria-hidden="true"><input type="text" name="b_xxxxx_xxxxx" tabindex="-1" value=""></div>
							<div style="margin-top: 15px;">
								<input type="submit" value="<?php esc_attr_e('Subscribe', 'events-manager'); ?>" name="subscribe" id="mc-embedded-subscribe" class="button button-primary" style="--accent-color:#429543; --accent-color-hover:#429543;">
							</div>
						</form>
					</div>
					<div class="image">
						<img src="<?php echo EM_DIR_URI . '/includes/images/events-manager.svg'; ?>">
					</div>
				</div><!-- content -->
				<footer class="em-submit-section input">
					<div>
						<button class="button button-secondary dismiss-modal"><?php esc_html_e('No Thanks', 'events-manager'); ?></button>
					</div>
					<div>
						<a href="https://eventsmanagerpro.com/newsletter/" class="button button-secondary" target="_blank"><?php esc_html_e('Learn More', 'events-manager'); ?></a>
					</div>
				</footer>
			</div><!-- modal -->
		</div>
		<?php
		static::output_js();
	}
	
	public static function promo_popup(){
		// check admin data and see if show data is still enabled
		?>
		<div class="em pixelbones em-modal <?php em_template_classes('search', 'search-advanced'); ?> em-admin-modal" id="em-promo-popup" data-nonce="<?php echo wp_create_nonce('em-promo-popup'); ?>">
			<div class="em-modal-popup">
				<header>
					<a class="em-close-modal dismiss-modal" href="#"></a><!-- close modal -->
					<div class="em-modal-title">Limited Lifetime Offer</div>
				</header>
				<div class="em-modal-content has-image" style="--font-size:16px;">
					<div>
						<p>For the first time (and <em>possibly the only time</em>) ever, we are offering lifetime licenses.</p>
						<p>These are limited in supply, <em><strong>once sold out, they're gone</strong></em>.</p>
						<a href="https://em.cm/lifetime/" target="_blank">Claim your once-in-a-lifetime opportunity now!</a>
					</div>
					<div class="image">
						<img src="<?php echo EM_DIR_URI . '/includes/images/events-manager.svg'; ?>">
					</div>
				</div><!-- content -->
				<footer class="em-submit-section input">
					<div>
						<a href="https://em.cm/lifetime/" class="button button-primary input" target="_blank" style="margin:10px auto; --accent-color:#429543; --accent-color-hover:#429543;">More Info</a>
					</div>
					<div>
						<button class="button button-secondary dismiss-modal">Dismiss Notice</button>
					</div>
				</footer>
			</div><!-- modal -->
		</div>
		<?php
		static::output_js();
	}
	
	public static function newsletter_notice(){
		ob_start();
		?>
		<div style="display: grid; grid-template-columns: 80px auto; grid-gap: 20px; margin: 15px 0;">
			<div style="text-align: center;  align-self: center; padding-left: 10px; padding-top:10px;">
				<img src="<?php echo EM_DIR_URI . '/includes/images/events-manager.svg'; ?>" style="width: 100%;">
			</div>
			<div>
				<h3 style="margin: 0 0 5px; padding-bottom:0;"><?php esc_html_e('Subscribe to Our Newsletter', 'events-manager'); ?></h3>
				<p><?php esc_html_e('Stay updated with the latest features, tips, and news about Events Manager by subscribing to our newsletter!', 'events-manager'); ?></p>
				<div>
					<a href="https://eventsmanagerpro.com/newsletter/" class="button button-primary input" target="_blank" style="margin-right:10px; --accent-color:#429543; --accent-color-hover:#429543;"><?php esc_html_e('Subscribe Now', 'events-manager'); ?></a>
					<a href="<?php echo esc_url( admin_url('admin-ajax.php?action=em_dismiss_admin_notice&notice=newsletter-signup&redirect=1&nonce='. wp_create_nonce('em_dismiss_admin_noticenewsletter-signup'.get_current_user_id()) ) ); ?>" class="button button-secondary"><?php esc_html_e('Dismiss', 'events-manager'); ?></a>
				</div>
			</div>
		</div><!-- content -->
		<?php
		return ob_get_clean();
	}
	
	public static function promo_notice(){
		$key = em_get_option('dbem_pro_api_key');
		ob_start();
		if ( empty($key) || date('Y', $key['until'] ?? time() ) !== '2125' ) {
			?>
			<div style="display: grid; grid-template-columns: 80px auto; grid-gap: 20px; margin: 15px 0;">
				<div style="text-align: center;  align-self: start; padding-left: 10px; padding-top:10px;">
					<img src="<?php echo EM_DIR_URI . '/includes/images/events-manager.svg'; ?>" style="width: 100%;">
				</div>
				<div>
					<h3 style="margin: 0 0 5px; padding-bottom:0;">One-Time Lifetime Offer</h3>
					<p>For the first time (and <em>possibly the only time</em>) ever, we are offering lifetime licenses.</p>
					<p>These are limited in supply, <em><strong>once sold out, they're gone</strong></em>. Claim your once-in-a-lifetime opportunity now!</p>
					<div>
						<a href="https://em.cm/lifetime-n" class="button button-primary input" target="_blank" style="margin-right:10px; --accent-color:#429543; --accent-color-hover:#429543;">Read More!</a>
						<a href="<?php echo esc_url( admin_url('admin-ajax.php?action=em_dismiss_admin_notice&notice=promo-popup&redirect=1&nonce='. wp_create_nonce('em_dismiss_admin_noticepromo-popup'.get_current_user_id()) ) ); ?>" class="button button-secondary"><?php esc_html_e('Dismiss', 'events-manager'); ?></a>
					</div>
				</div>
			</div><!-- content -->
			<?php
		}
		return ob_get_clean();
	}
	
	public static function expired_reminder_notice(){
		ob_start();
		?>
		<div style="display: grid; grid-template-columns: 80px auto; grid-gap: 20px;">
			<div style="text-align: center; padding-left: 10px; padding-top:10px;">
				<img src="<?php echo EM_DIR_URI . '/includes/images/events-manager.svg'; ?>" style="width: 100%;">
			</div>
			<div>
				<h3>Events Manager Pro - License Expired</h3>
				<p>Your Pro license has expired, meaning you will not have access to our latest updates and Pro support. Please renew your license to get access to the latest features and our Pro support.</p>
				<p>We are regularly adding new features, don't miss out and renew now!</p>
				<a href="https://eventsmanagerpro.com/gopro/?utm_source=events-manager&utm_medium=plugin-notice&utm_campaign=plugins" class="button button-primary input" target="_blank" style="margin-right:10px; --accent-color:#429543; --accent-color-hover:#429543;">Renew Now!</a>
			</div>
		</div><!-- content -->
		<?php
		return ob_get_clean();
	}
	
	public static function expiry_reminder_notice(){
		ob_start();
		$key = em_get_option('dbem_pro_api_key');
		$expiry_date = date('Y-m-d', $key['until']);
		?>
		<div style="display: grid; grid-template-columns: 80px auto; grid-gap: 20px;">
			<div style="text-align: center; padding-left: 10px; padding-top:10px;">
				<img src="<?php echo EM_DIR_URI . '/includes/images/events-manager.svg'; ?>" style="width: 100%;">
			</div>
			<div>
				<h3>Events Manager Pro - Your License is Expiring Soon...</h3>
				<p>Your Pro license is expiring on <?php echo $expiry_date; ?>. By renewing on time, you maintain your current plan pricing and conditions.</p>
				<p>Renew now to maintain access to our latest updates and Pro support. We hope you're finding the plugin useful and we look forward to providing you with more exciting new features!</p>
				<a href="https://eventsmanagerpro.com/gopro/?utm_source=events-manager&utm_medium=plugin-notice&utm_campaign=plugins" class="button button-primary input" target="_blank" style="margin-right:10px; --accent-color:#429543; --accent-color-hover:#429543;">Renew Now!</a>
			</div>
		</div><!-- content -->
		<?php
		return ob_get_clean();
	}
	
	public static function output_js(){
		if( !static::$output_js ){
			?>
			<script>
				jQuery(document).ready(function($){
					$('.em-admin-modal').each( function(){
						let modal = $(this);
						let ignore_event = false;
						openModal( modal );
						modal.on('em_modal_close', function(){
							// send AJAX to close
							if( ignore_event ) return false;
							$.post( EM.ajaxurl, { action : 'em-admin-popup-modal', 'dismiss':'close', 'modal':modal.attr('id'), 'nonce': modal.attr('data-nonce') });
						});
						modal.find('button.dismiss-modal').on('click', function(){
							// send AJAX to close
							ignore_event = true;
							closeModal(modal);
							$.post( EM.ajaxurl, { action : 'em-admin-popup-modal', 'dismiss':'button', 'modal':modal.attr('id'), 'nonce':modal.attr('data-nonce') });
						});
					});
				});
			</script>
			<?php
			static::$output_js = true;
		}
	}
	
	public static function ajax(){
		if( !empty($_REQUEST['modal']) && wp_verify_nonce($_REQUEST['nonce'], $_REQUEST['modal']) ){
			$action = sanitize_key( preg_replace('/^em\-/', '', $_REQUEST['modal']) );
			$data = is_multisite() ? get_site_option('dbem_data') : em_get_option('dbem_data');
			if( $_REQUEST['dismiss'] == 'button' || ($data['admin-modals'][$action] ?? 0) === 2 ) {
				// disable the modal so it's not shown again
				unset($data['admin-modals'][$action]);
				if( !empty($data['admin-modals'][$action.'-count']) ) unset($data['admin-modals'][$action.'-count']);
				is_multisite() ? update_site_option('dbem_data', $data) : update_option('dbem_data', $data);
			}else{
				// limit popup to EM pages only
				$data['admin-modals'][$action] = 2;
				is_multisite() ? update_site_option('dbem_data', $data) : update_option('dbem_data', $data);
			}
		}
	}
}
EM_Admin_Modals::init();