<?php
/**
 * JP4WC_Order_Attempt_Limiter class.
 *
 * This class is responsible for limiting the number of order attempts to prevent abuse.
 *
 * @package jp4wc
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'JP4WC_Order_Attempt_Limiter' ) ) {
	/**
	 * Class JP4WC_Order_Attempt_Limiter
	 *
	 * This class limits the number of order attempts to prevent abuse.
	 */
	class JP4WC_Order_Attempt_Limiter {

		/**
		 * Singleton instance of this class
		 *
		 * @var JP4WC_Order_Attempt_Limiter
		 */
		private static $instance = null;

		/**
		 * Database table name for storing order attempts
		 *
		 * @var string
		 */
		private $table_name;

		/**
		 * Plugin version
		 *
		 * @var string
		 */
		private $version = '1.0.0';

		/**
		 * Plugin URL
		 *
		 * @var string
		 */
		private $plugin_url;

		/**
		 * Get singleton instance of the class
		 *
		 * @since 1.0.0
		 * @return JP4WC_Order_Attempt_Limiter Single instance of this class
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * JP4WC_Order_Attempt_Limiter constructor.
		 */
		private function __construct() {
			global $wpdb;
			$this->table_name = $wpdb->prefix . 'wc_order_attempts';

			// Hook into WordPress.
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

			// WooCommerce hooks.
			add_action( 'woocommerce_checkout_process', array( $this, 'check_order_attempts' ) );
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'record_order_attempt' ) );

			// AJAX handlers.
			add_action( 'wp_ajax_jp4wcoal_get_fingerprint', array( $this, 'ajax_get_fingerprint' ) );
			add_action( 'wp_ajax_nopriv_jp4wcoal_get_fingerprint', array( $this, 'ajax_get_fingerprint' ) );
		}

		/**
		 * Creates the database table for storing order attempts
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private function create_database_table() {
			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            fingerprint varchar(255) NOT NULL,
            attempt_time datetime DEFAULT CURRENT_TIMESTAMP,
            user_id bigint(20) DEFAULT 0,
            order_id bigint(20) DEFAULT 0,
            status varchar(50) DEFAULT 'attempted',
            PRIMARY KEY (id),
            KEY fingerprint (fingerprint),
            KEY attempt_time (attempt_time)
        ) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}

		/**
		 * Sets default options for the order attempt limiter
		 *
		 * @since 1.0.0
		 * @return void
		 */
		private function set_default_options() {
			add_option( 'jp4wcoal_max_attempts', 3 );
			add_option( 'jp4wcoal_lockout_duration', 60 ); // minutes.
			add_option( 'jp4wcoal_cleanup_interval', 24 ); // hours.
		}

		/**
		 * Enqueues scripts for the checkout page
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function enqueue_scripts() {
			if ( is_checkout() ) {
				// Enqueue FingerprintJS.
				wp_enqueue_script(
					'fingerprintjs',
					'https://cdn.jsdelivr.net/npm/@fingerprintjs/fingerprintjs@3/dist/fp.min.js',
					array(),
					'3.0.0',
					true
				);

				// Enqueue our script.
				wp_enqueue_script(
					'jp4wcoal-checkout',
					$this->plugin_url . 'assets/js/checkout.js',
					array( 'jquery', 'fingerprintjs' ),
					$this->version,
					true
				);

				// Localize script.
				wp_localize_script(
					'jp4wcoal-checkout',
					'jp4wcoal_ajax',
					array(
						'ajax_url' => admin_url( 'admin-ajax.php' ),
						'nonce'    => wp_create_nonce( 'jp4wcoal_fingerprint_nonce' ),
					)
				);
			}
		}

		/**
		 * Enqueues scripts and styles for the admin page
		 *
		 * @since 1.0.0
		 * @param string $hook The current admin page hook.
		 * @return void
		 */
		public function enqueue_admin_scripts( $hook ) {
			if ( 'woocommerce_page_wc-order-limiter' !== $hook ) {
				return;
			}

			wp_enqueue_style(
				'jp4wcoal-admin',
				$this->plugin_url . 'assets/css/admin.css',
				array(),
				$this->version
			);
		}

		/**
		 * Adds the Order Attempt Limiter submenu page to the WooCommerce admin menu
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function add_admin_menu() {
			add_submenu_page(
				'woocommerce',
				__( 'Order Attempt Limiter', 'woocommerce-for-paygent-payment-main' ),
				__( 'Order Limiter', 'woocommerce-for-paygent-payment-main' ),
				'manage_woocommerce',
				'wc-order-limiter',
				array( $this, 'admin_page' )
			);
		}

		/**
		 * Registers settings for the Order Attempt Limiter
		 *
		 * This function registers the plugin's settings with WordPress Settings API,
		 * including maximum attempts, lockout duration, and cleanup interval options.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function register_settings() {
			register_setting(
				'jp4wcoal_settings',
				'jp4wcoal_max_attempts',
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 3,
				)
			);

			register_setting(
				'jp4wcoal_settings',
				'jp4wcoal_lockout_duration',
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 60,
				)
			);

			register_setting(
				'jp4wcoal_settings',
				'jp4wcoal_cleanup_interval',
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 24,
				)
			);
		}

		/**
		 * Displays the Order Attempt Limiter admin page
		 *
		 * This function renders the admin interface for configuring order attempt limits,
		 * displaying statistics, and managing cleanup operations.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function admin_page() {
			?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<form action="options.php" method="post">
				<?php
				settings_fields( 'jp4wcoal_settings' );
				?>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="jp4wcoal_max_attempts">
								<?php esc_html_e( 'Maximum Order Attempts', 'woocommerce-for-paygent-payment-main' ); ?>
							</label>
						</th>
						<td>
							<input type="number" 
									id="jp4wcoal_max_attempts" 
									name="jp4wcoal_max_attempts" 
									value="<?php echo esc_attr( get_option( 'jp4wcoal_max_attempts', 3 ) ); ?>" 
									min="1" 
									max="10" />
							<p class="description">
								<?php esc_html_e( 'Maximum number of order attempts allowed before lockout.', 'woocommerce-for-paygent-payment-main' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="jp4wcoal_lockout_duration">
								<?php esc_html_e( 'Lockout Duration (minutes)', 'woocommerce-for-paygent-payment-main' ); ?>
							</label>
						</th>
						<td>
							<input type="number" 
									id="jp4wcoal_lockout_duration" 
									name="jp4wcoal_lockout_duration" 
									value="<?php echo esc_attr( get_option( 'jp4wcoal_lockout_duration', 60 ) ); ?>" 
									min="1" 
									max="1440" />
							<p class="description">
								<?php esc_html_e( 'How long users are locked out after exceeding maximum attempts.', 'woocommerce-for-paygent-payment-main' ); ?>
							</p>
						</td>
					</tr>
					
					<tr>
						<th scope="row">
							<label for="jp4wcoal_cleanup_interval">
								<?php esc_html_e( 'Cleanup Interval (hours)', 'woocommerce-for-paygent-payment-main' ); ?>
							</label>
						</th>
						<td>
							<input type="number" 
									id="jp4wcoal_cleanup_interval" 
									name="jp4wcoal_cleanup_interval" 
									value="<?php echo esc_attr( get_option( 'jp4wcoal_cleanup_interval', 24 ) ); ?>" 
									min="1" 
									max="168" />
							<p class="description">
								<?php esc_html_e( 'How often to clean up old attempt records from the database.', 'woocommerce-for-paygent-payment-main' ); ?>
							</p>
						</td>
					</tr>
				</table>
				
				<?php submit_button(); ?>
			</form>
			
			<hr />
			
			<h2><?php esc_html_e( 'Current Statistics', 'woocommerce-for-paygent-payment-main' ); ?></h2>
				<?php $this->display_statistics(); ?>
			
			<form method="post" action="">
				<?php wp_nonce_field( 'jp4wcoal_cleanup_action', 'jp4wcoal_cleanup_nonce' ); ?>
				<p>
					<button type="submit" name="jp4wcoal_cleanup_now" class="button">
						<?php esc_html_e( 'Clean Up Old Records Now', 'woocommerce-for-paygent-payment-main' ); ?>
					</button>
				</p>
			</form>
			
				<?php
				if ( isset( $_POST['jp4wcoal_cleanup_now'] ) && wp_verify_nonce( $_POST['jp4wcoal_cleanup_nonce'], 'jp4wcoal_cleanup_action' ) ) {
					$this->cleanup_old_attempts();
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Old records cleaned up successfully.', 'woocommerce-for-paygent-payment-main' ) . '</p></div>';
				}
				?>
		</div>
			<?php
		}

		private function display_statistics() {
			global $wpdb;

			$total_attempts  = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table_name ) );
			$locked_users    = $this->get_currently_locked_users_count();
			$recent_attempts = $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)', $this->table_name )
			);

			?>
		<table class="widefat">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Total Attempts Recorded:', 'woocommerce-for-paygent-payment-main' ); ?></strong></td>
					<td><?php echo esc_html( $total_attempts ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Currently Locked Users:', 'woocommerce-for-paygent-payment-main' ); ?></strong></td>
					<td><?php echo esc_html( $locked_users ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Attempts in Last 24 Hours:', 'woocommerce-for-paygent-payment-main' ); ?></strong></td>
					<td><?php echo esc_html( $recent_attempts ); ?></td>
				</tr>
			</tbody>
		</table>
			<?php
		}

		private function get_currently_locked_users_count() {
			global $wpdb;

			$max_attempts     = get_option( 'jp4wcoal_max_attempts', 3 );
			$lockout_duration = get_option( 'jp4wcoal_lockout_duration', 60 );

			$sql = "SELECT COUNT(DISTINCT fingerprint) FROM (
            SELECT fingerprint, COUNT(*) as attempt_count 
            FROM {$this->table_name} 
            WHERE attempt_time > DATE_SUB(NOW(), INTERVAL %d MINUTE)
            GROUP BY fingerprint
            HAVING attempt_count >= %d
        ) as locked_users";

			return $wpdb->get_var( $wpdb->prepare( $sql, $lockout_duration, $max_attempts ) );
		}

		public function ajax_get_fingerprint() {
			check_ajax_referer( 'jp4wcoal_fingerprint_nonce', 'nonce' );

			$fingerprint = isset( $_POST['fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['fingerprint'] ) ) : '';

			if ( empty( $fingerprint ) ) {
				wp_send_json_error( 'Invalid fingerprint' );
			}

			// Store fingerprint in session for later use.
			if ( ! session_id() ) {
				session_start();
			}
			$_SESSION['jp4wcoal_fingerprint'] = $fingerprint;

			wp_send_json_success();
		}

		public function check_order_attempts() {
			// Get fingerprint from session.
			if ( ! session_id() ) {
				session_start();
			}

			$fingerprint = isset( $_SESSION['jp4wcoal_fingerprint'] ) ? $_SESSION['jp4wcoal_fingerprint'] : '';

			if ( empty( $fingerprint ) ) {
				// Fallback to IP-based fingerprint if JavaScript fingerprint not available.
				$fingerprint = $this->get_ip_fingerprint();
			}

			if ( $this->is_user_locked( $fingerprint ) ) {
				$lockout_duration = get_option( 'jp4wcoal_lockout_duration', 60 );
				$error_message    = sprintf(
					__( 'You have exceeded the maximum number of order attempts. Please try again after %d minutes.', 'woocommerce-for-paygent-payment-main' ),
					$lockout_duration
				);
				wc_add_notice( $error_message, 'error' );
			}
		}

		public function record_order_attempt( $order_id ) {
			global $wpdb;

			// Get fingerprint.
			if ( ! session_id() ) {
				session_start();
			}

			$fingerprint = isset( $_SESSION['jp4wcoal_fingerprint'] ) ? $_SESSION['jp4wcoal_fingerprint'] : '';

			if ( empty( $fingerprint ) ) {
				$fingerprint = $this->get_ip_fingerprint();
			}

			$user_id = get_current_user_id();

			// Record the attempt.
			$wpdb->insert(
				$this->table_name,
				array(
					'fingerprint' => $fingerprint,
					'user_id'     => $user_id,
					'order_id'    => $order_id,
					'status'      => 'completed',
				),
				array( '%s', '%d', '%d', '%s' )
			);

			// Schedule cleanup if not already scheduled.
			if ( ! wp_next_scheduled( 'jp4wcoal_cleanup_cron' ) ) {
				$cleanup_interval = get_option( 'jp4wcoal_cleanup_interval', 24 ) * HOUR_IN_SECONDS;
				wp_schedule_event( time(), 'daily', 'jp4wcoal_cleanup_cron' );
			}
		}

		private function is_user_locked( $fingerprint ) {
			global $wpdb;

			$max_attempts     = get_option( 'jp4wcoal_max_attempts', 3 );
			$lockout_duration = get_option( 'jp4wcoal_lockout_duration', 60 );

			$attempts = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table_name} 
            WHERE fingerprint = %s 
            AND attempt_time > DATE_SUB(NOW(), INTERVAL %d MINUTE)",
					$fingerprint,
					$lockout_duration
				)
			);

			return $attempts >= $max_attempts;
		}

		/**
		 * Generate a fingerprint based on IP address and user agent
		 *
		 * This is used as a fallback when JavaScript fingerprinting is not available.
		 *
		 * @since 1.0.0
		 * @return string MD5 hash of IP and user agent
		 */
		private function get_ip_fingerprint() {
			// Fallback fingerprint based on IP and user agent.
			$ip         = $this->get_user_ip();
			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			return md5( $ip . $user_agent );
		}

		/**
		 * Get user's IP address
		 *
		 * Attempts to get the user's real IP address by checking various server variables.
		 * REMOTE_ADDR is checked first as it's the most reliable and cannot be spoofed.
		 * Other headers are used as fallback only.
		 *
		 * @since 1.0.0
		 * @return string Sanitized IP address
		 */
		private function get_user_ip() {
			if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			}
			return '';
		}

		public function cleanup_old_attempts() {
			global $wpdb;

			$cleanup_interval = get_option( 'jp4wcoal_cleanup_interval', 24 );

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table_name} 
            WHERE attempt_time < DATE_SUB(NOW(), INTERVAL %d HOUR)",
					$cleanup_interval
				)
			);
		}
	}
}
