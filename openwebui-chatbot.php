<?php
declare(strict_types=1);
/**
 * Plugin Name: OpenWebUI Chatbot
 * Description: A comprehensive chatbot plugin using the OpenWebUI API with IP banning, nonce security, Markdown formatting, persistent conversation history, advanced admin logs (with CSV & JSON export & bulk management), API response caching, extended REST & GraphQL endpoints, Gutenberg block & widget integration, WP-CLI commands, fallback API endpoints with exponential backoff, admin error notifications, multilingual support, sentiment analysis integration, external logging integration, custom avatar support, REST endpoints for updating logs & feedback, spam filtering, and enhanced performance features.
 * Version: 1.2
 * Author: YTM Solutions
 * Text Domain: ollama-chatbot
 *
 * @package Ollama_Chatbot
 */
namespace Ollama\Chatbot;

// Define constants outside the class for global access
define('OLLAMA_CHATBOT_VERSION', '1.2');
define('OLLAMA_CHATBOT_TEXT_DOMAIN', 'ollama-chatbot');
define('OLLAMA_CHATBOT_NONCE_ACTION', 'ollama_chatbot_nonce');

// Fix missing constants if not defined.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'COOKIEPATH' ) ) {
	define( 'COOKIEPATH', '/' );
}
if ( ! defined( 'COOKIE_DOMAIN' ) ) {
	define( 'COOKIE_DOMAIN', '' );
}

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class Chatbot {

	/** Plugin version */
	public const VERSION = '1.2';

	/** Nonce action name */
	public const NONCE_ACTION = 'ollama_chatbot_nonce';

	/** Default maximum allowed requests per IP per hour */
	private const REQUEST_LIMIT = 100;

	/** Plugin text domain */
	public const TEXT_DOMAIN = 'ollama-chatbot';

	/** Conversation history expiration (in seconds) for transients */
	private const HISTORY_EXPIRATION = DAY_IN_SECONDS;

	/** Conversation log retention (in days) for persistent DB logs */
	private const LOG_RETENTION_DAYS = 30;

	/**
	 * Enable API response caching. Override via filter.
	 *
	 * @var bool
	 */
	private static $cache_enabled = true;

	/**
	 * Cache expiration (in seconds) for API responses.
	 *
	 * @var int
	 */
	private static $cache_expiration = 300; // 5 minutes

	/**
	 * Singleton instance.
	 *
	 * @var Chatbot|null
	 */
	private static ?Chatbot $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Chatbot
	 */
	public static function get_instance(): Chatbot {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->define_constants();
		$this->init_hooks();
		$this->load_textdomain();
		$this->register_wp_cli_commands();
		$this->register_gutenberg_block();
		$this->register_widget();
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing.
	 */
	public function __wakeup() {
		throw new \Exception( __( 'Cannot unserialize a singleton.', self::TEXT_DOMAIN ) );
	}

	/* ========================================================================== PLUGIN BOOTSTRAP & INITIALIZATION ========================================================================== */

	/**
	 * Define essential plugin constants.
	 */
	private function define_constants(): void {
		if ( ! defined( 'OLLAMA_CHATBOT_DIR' ) ) {
			define( 'OLLAMA_CHATBOT_DIR', plugin_dir_path( __FILE__ ) );
		}
		if ( ! defined( 'OLLAMA_CHATBOT_URL' ) ) {
			define( 'OLLAMA_CHATBOT_URL', plugins_url( '', __FILE__ ) );
		}
		if ( ! defined( 'OLLAMA_CHATBOT_VERSION' ) ) {
			define( 'OLLAMA_CHATBOT_VERSION', self::VERSION );
		}
	}

	/**
	 * Initialize hooks, actions, and filters.
	 */
	private function init_hooks(): void {
		$this->debug_log( __( 'Chatbot Plugin Loaded', self::TEXT_DOMAIN ) );

		// Enqueue assets.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		// Admin settings & logs.
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_settings_link' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_menu', [ $this, 'add_logs_page' ] );
		add_action( 'admin_menu', [ $this, 'add_export_logs_page' ] );

		// Chatbot shortcode.
		add_shortcode( 'ollama_chatbot', [ $this, 'render_shortcode' ] );

		// AJAX handlers.
		add_action( 'admin_post_ollama_handle_chat_request', [ $this, 'handle_chat_request' ] );
		add_action( 'admin_post_nopriv_ollama_handle_chat_request', [ $this, 'handle_chat_request' ] );

		// REST API endpoints.
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_log_routes' ] );
		// Additional REST endpoints.
		add_action( 'rest_api_init', [ $this, 'register_rest_update_log_route' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_feedback_route' ] );

		// Optional GraphQL integration.
		add_action( 'init', function() {
			if ( function_exists( 'register_graphql_field' ) ) {
				register_graphql_field( 'RootQuery', 'chatbotLog', [
					'type'        => 'String',
					'description' => __( 'Get chatbot log as JSON.', self::TEXT_DOMAIN ),
					'args'        => [
						'id' => [
							'type' => 'String',
						],
					],
					'resolve'     => function( $root, $args, $context, $info ) {
						global $wpdb;
						$table = $wpdb->prefix . 'ollama_chatbot_logs';
						$id = $args['id'] ?? '';
						if ( empty( $id ) ) {
							return null;
						}
						$log = $wpdb->get_var( $wpdb->prepare( "SELECT chat_history FROM {$table} WHERE conversation_id = %s", $id ) );
						return $log ? $log : null;
					},
				] );
			}
		} );

		// Scheduled events.
		add_action( 'ollama_hourly_event', [ $this, 'clear_banned_ips' ] );
		if ( ! wp_next_scheduled( 'ollama_cleanup_conversation_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'ollama_cleanup_conversation_logs' );
		}
		add_action( 'ollama_cleanup_conversation_logs', [ $this, 'cleanup_conversation_logs' ] );

		// Additional initialization.
		do_action( 'ollama_chatbot_init' );

		// Create DB table on activation.
		register_activation_hook( __FILE__, [ __CLASS__, 'create_conversation_table' ] );
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);
	}

	/* ========================================================================== ADMIN & FRONT-END FUNCTIONALITY ========================================================================== */

	/**
	 * Enqueue front-end styles and scripts.
	 */
	public function enqueue_scripts(): void {
		wp_enqueue_style(
			'ollama-chatbot-style',
			OLLAMA_CHATBOT_URL . '/css/style.css',
			[],
			self::VERSION
		);
		wp_enqueue_script(
			'axios',
			'https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js',
			[],
			null,
			true
		);
		wp_enqueue_script(
			'markdown-it',
			'https://cdn.jsdelivr.net/npm/markdown-it@12.3.2/dist/markdown-it.min.js',
			[],
			null,
			true
		);
		wp_enqueue_script(
			'ollama-chatbot-script',
			OLLAMA_CHATBOT_URL . '/js/ollama-chatbot-script.js',
			[ 'jquery', 'axios', 'markdown-it' ],
			self::VERSION,
			true
		);
		wp_localize_script(
			'ollama-chatbot-script',
			'ollamaChatbotVars',
			[
				'endpoint' => get_option( 'ollama_endpoint' ),
				'apiKey'   => get_option( 'ollama_api_key' ),
				'model'    => get_option( 'ollama_model' ),
				'prompt'   => get_option( 'ollama_prompt' ),
				'ajaxUrl'  => admin_url( 'admin-post.php' ),
				'restUrl'  => esc_url_raw( rest_url( 'ollama-chatbot/v1/chat' ) ),
				'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
			]
		);
	}

	/**
	 * Add a settings link to the plugins list.
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=ollama-chatbot-settings' ) ),
			esc_html__( 'Settings', self::TEXT_DOMAIN )
		);
		$links[] = $settings_link;
		return $links;
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {
		$group = 'ollama-chatbot-settings-group';
		register_setting( $group, 'ollama_endpoint' );
		register_setting( $group, 'ollama_api_key' );
		register_setting( $group, 'ollama_model' );
		register_setting( $group, 'ollama_prompt' );
		register_setting( $group, 'ollama_log_retention_days' );
		register_setting( $group, 'ollama_debug_mode' );
	}

	/**
	 * Add the settings page to the admin menu.
	 */
	public function add_settings_page(): void {
		add_options_page(
			esc_html__( 'Ollama Chatbot Settings', self::TEXT_DOMAIN ),
			esc_html__( 'Ollama Chatbot', self::TEXT_DOMAIN ),
			'manage_options',
			'ollama-chatbot-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		include OLLAMA_CHATBOT_DIR . '/includes/settings-page.php';
	}

	/**
	 * Add a new admin menu for conversation logs.
	 */
	public function add_logs_page(): void {
		add_menu_page(
			esc_html__( 'Chatbot Logs', self::TEXT_DOMAIN ),
			esc_html__( 'Chatbot Logs', self::TEXT_DOMAIN ),
			'manage_options',
			'ollama-chatbot-logs',
			[ $this, 'render_logs_page' ],
			'dashicons-format-chat',
			85
		);
	}

	/**
	 * Handle bulk deletion of logs.
	 */
	public function handle_bulk_delete_logs(): void {
		if ( ! check_admin_referer( 'bulk_delete_logs', 'bulk_delete_nonce' ) ) {
			wp_die( __( 'Nonce verification failed.', self::TEXT_DOMAIN ) );
		}
		if ( isset( $_POST['log_ids'] ) && is_array( $_POST['log_ids'] ) ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'ollama_chatbot_logs';
			foreach ( $_POST['log_ids'] as $conversation_id ) {
				$wpdb->delete( $table_name, [ 'conversation_id' => sanitize_text_field( $conversation_id ) ] );
			}
		}
		wp_redirect( admin_url( 'admin.php?page=ollama-chatbot-logs' ) );
		exit;
	}

	/**
	 * Render the conversation logs admin page.
	 */
	public function render_logs_page(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ollama_chatbot_logs';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 20;
		$offset = ( $page - 1 ) * $per_page;
		$where = $search ? $wpdb->prepare( "WHERE conversation_id LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' ) : '';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} $where" );
		$logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} $where ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		$total_pages = ceil( $total / $per_page );
		?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Chatbot Conversation Logs', self::TEXT_DOMAIN ); ?></h1>
            <form method="get">
                <input type="hidden" name="page" value="ollama-chatbot-logs" />
                <p class="search-box">
                    <label class="screen-reader-text" for="log-search-input"><?php esc_html_e( 'Search Logs', self::TEXT_DOMAIN ); ?></label>
                    <input type="search" id="log-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" />
                    <input type="submit" value="<?php esc_attr_e( 'Search Logs', self::TEXT_DOMAIN ); ?>" class="button" />
                </p>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bulk_delete_logs', 'bulk_delete_nonce' ); ?>
                <input type="hidden" name="action" value="bulk_delete_logs">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                    <tr>
                        <th><input type="checkbox" id="bulk-delete-select-all"></th>
                        <th><?php esc_html_e( 'Conversation ID', self::TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'User ID', self::TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'User IP', self::TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'Log Data', self::TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'Date', self::TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'Actions', self::TEXT_DOMAIN ); ?></th>
                    </tr>
                    </thead>
                    <tbody>
					<?php if ( $logs ) : ?>
						<?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><input type="checkbox" name="log_ids[]" value="<?php echo esc_attr( $log['conversation_id'] ); ?>"></td>
                                <td><?php echo esc_html( $log['conversation_id'] ); ?></td>
                                <td><?php echo esc_html( $log['user_id'] ?: 'Guest' ); ?></td>
                                <td><?php echo esc_html( $log['user_ip'] ); ?></td>
                                <td><pre><?php echo wp_kses_post( $log['chat_history'] ); ?></pre></td>
                                <td><?php echo esc_html( $log['created_at'] ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'delete_log', 'id' => $log['conversation_id'] ], admin_url( 'admin-post.php' ) ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this log?', self::TEXT_DOMAIN ); ?>');"><?php esc_html_e( 'Delete', self::TEXT_DOMAIN ); ?></a>
                                </td>
                            </tr>
						<?php endforeach; ?>
					<?php else : ?>
                        <tr>
                            <td colspan="7"><?php esc_html_e( 'No logs found.', self::TEXT_DOMAIN ); ?></td>
                        </tr>
					<?php endif; ?>
                    </tbody>
                </table>
                <div class="tablenav">
                    <div class="alignleft actions">
                        <select name="bulk_action">
                            <option value="-1"><?php esc_html_e( 'Bulk Actions', self::TEXT_DOMAIN ); ?></option>
                            <option value="delete"><?php esc_html_e( 'Delete', self::TEXT_DOMAIN ); ?></option>
                        </select>
                        <input type="submit" value="<?php esc_attr_e( 'Apply', self::TEXT_DOMAIN ); ?>" class="button action">
                    </div>
                    <div class="tablenav-pages">
						<?php echo paginate_links( [
							'base' => add_query_arg( 'paged', '%#%' ),
							'format' => '',
							'total' => $total_pages,
							'current' => $page,
						] ); ?>
                    </div>
                </div>
            </form>
        </div>
		<?php
	}

	/**
	 * Add an admin submenu page for exporting logs.
	 */
	public function add_export_logs_page(): void {
		add_submenu_page(
			'ollama-chatbot-logs',
			esc_html__( 'Export Logs', self::TEXT_DOMAIN ),
			esc_html__( 'Export Logs', self::TEXT_DOMAIN ),
			'manage_options',
			'ollama-chatbot-export-logs',
			[ $this, 'export_logs_page' ]
		);
	}

	/**
	 * Render the export logs page and handle CSV/JSON export.
	 */
	public function export_logs_page(): void {
		if ( isset( $_POST['export_logs'] ) && check_admin_referer( 'export_chatbot_logs', 'export_nonce' ) ) {
			$this->export_logs_as_csv();
		}
		?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Export Chatbot Logs', self::TEXT_DOMAIN ); ?></h1>
            <form method="post">
				<?php wp_nonce_field( 'export_chatbot_logs', 'export_nonce' ); ?>
                <p><?php esc_html_e( 'Click the button below to export all conversation logs as a CSV file. Use the filter "ollama_chatbot_export_format" to export as JSON.', self::TEXT_DOMAIN ); ?></p>
                <input type="submit" name="export_logs" class="button button-primary" value="<?php esc_attr_e( 'Export Logs', self::TEXT_DOMAIN ); ?>">
            </form>
        </div>
		<?php
		exit;
	}

	/**
	 * Export conversation logs as CSV or JSON.
	 */
	private function export_logs_as_csv(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ollama_chatbot_logs';
		$logs = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY created_at DESC", ARRAY_A );
		$export_format = apply_filters( 'ollama_chatbot_export_format', 'csv' );
		if ( 'json' === $export_format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=chatbot_logs.json' );
			echo wp_json_encode( $logs, JSON_PRETTY_PRINT );
		} else {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=chatbot_logs.csv' );
			$output = fopen( 'php://output', 'w' );
			fputcsv( $output, [ 'Conversation ID', 'User ID', 'User IP', 'Chat History', 'Created At' ] );
			foreach ( $logs as $log ) {
				fputcsv( $output, [
					$log['conversation_id'],
					$log['user_id'] ?: 'Guest',
					$log['user_ip'],
					$log['chat_history'],
					$log['created_at'],
				] );
			}
			fclose( $output );
		}
		exit;
	}

	/* ========================================================================== CONVERSATION HISTORY FUNCTIONS ========================================================================== */

	/**
	 * Retrieve the conversation ID.
	 *
	 * Checks POST then cookie; if absent, generates a new ID.
	 *
	 * @return string Conversation ID.
	 */
	private function get_conversation_id(): string {
		$conversation_id = $this->get_post_param( 'conversation_id' );
		if ( ! empty( $conversation_id ) ) {
			return $conversation_id;
		}
		if ( ! empty( $_COOKIE['ollama_chat_id'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['ollama_chat_id'] ) );
		}
		$conversation_id = uniqid( 'ollama_', true );
		setcookie( 'ollama_chat_id', $conversation_id, time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
		return $conversation_id;
	}

	/**
	 * Get conversation history.
	 *
	 * @param string $conversation_id Conversation ID.
	 * @return array Conversation history.
	 */
	private function get_conversation_history( string $conversation_id ): array {
		$key = 'ollama_conversation_history_' . $conversation_id;
		$history = get_transient( $key );
		return is_array( $history ) ? $history : [];
	}

	/**
	 * Update conversation history.
	 *
	 * @param string $conversation_id Conversation ID.
	 * @param array  $history         History array.
	 */
	private function update_conversation_history( string $conversation_id, array $history ): void {
		// Limit history to last 50 messages
		if ( count( $history ) > 50 ) {
			$history = array_slice( $history, -50 );
		}
		$key = 'ollama_conversation_history_' . $conversation_id;
		set_transient( $key, $history, apply_filters( 'ollama_chatbot_history_expiration', self::HISTORY_EXPIRATION ) );
		$this->log_conversation( $conversation_id, $history );
	}

	/**
	 * Clear conversation history.
	 *
	 * @param string $conversation_id Conversation ID.
	 */
	private function clear_conversation_history( string $conversation_id ): void {
		delete_transient( 'ollama_conversation_history_' . $conversation_id );
		$this->delete_conversation_log( $conversation_id );
	}

	/**
	 * Log conversation in the custom DB table.
	 *
	 * @param string $conversation_id Conversation ID.
	 * @param array  $history         History array.
	 */
	private function log_conversation( string $conversation_id, array $history ): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ollama_chatbot_logs';
		$user_id = get_current_user_id();
		$data = [
			'conversation_id' => $conversation_id,
			'user_id'         => $user_id,
			'user_ip'         => $this->get_client_ip(),
			'chat_history'    => maybe_serialize( $history ),
			'created_at'      => current_time( 'mysql' ),
		];
		$wpdb->replace( $table_name, $data );
	}

	/**
	 * Delete a conversation log.
	 *
	 * @param string $conversation_id Conversation ID.
	 */
	private function delete_conversation_log( string $conversation_id ): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ollama_chatbot_logs';
		$wpdb->delete( $table_name, [ 'conversation_id' => $conversation_id ] );
	}

	/* ========================================================================== CHAT REQUEST HANDLING ========================================================================== */

	/**
	 * Validate admin-post requests.
	 */
	private function validate_admin_request(): void {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			$this->send_json_response( [ 'message' => __( 'Invalid request method.', self::TEXT_DOMAIN ) ], 405 );
		}
		$nonce = $_REQUEST['nonce'] ?? '';
		$nonce = sanitize_text_field( wp_unslash( $nonce ) );
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->send_json_response( [ 'message' => __( 'Nonce verification failed.', self::TEXT_DOMAIN ) ], 403 );
		}
	}

	/**
	 * Fetch and sanitize a POST parameter.
	 *
	 * @param string $key Parameter key.
	 * @return string Sanitized value.
	 */
	private function get_post_param( string $key ): string {
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Get the client IP.
	 *
	 * @return string Client IP.
	 */
	private function get_client_ip(): string {
		$ip_keys = [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ips = array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) );
				foreach ( $ips as $ip ) {
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						return $ip;
					}
				}
			}
		}
		return 'unknown';
	}

	/**
	 * Check if an IP is banned.
	 *
	 * @param string $ip Client IP.
	 * @return bool True if banned.
	 */
	private function is_ip_banned( string $ip ): bool {
		$banned_ips = (array) get_option( 'ollama_banned_ips', [] );
		return apply_filters( 'ollama_chatbot_is_ip_banned', in_array( $ip, $banned_ips, true ), $ip );
	}

	/**
	 * Retrieve request limit.
	 *
	 * @return int Limit.
	 */
	private function get_request_limit(): int {
		return (int) apply_filters( 'ollama_chatbot_request_limit', self::REQUEST_LIMIT );
	}

	/**
	 * Increment request count and enforce rate limiting.
	 *
	 * @param string $ip Client IP.
	 * @return array Error if limit exceeded.
	 */
	private function check_rate_limit( string $ip ): array {
		$transient_key = 'ollama_request_count_' . $ip;

		// Attempt to retrieve the count from the object cache
		$request_count = wp_cache_get( $transient_key, 'ollama_chatbot' );
		if ( false === $request_count ) {
			// If not found, initialize count to 1
			$request_count = 1;
			wp_cache_set( $transient_key, $request_count, 'ollama_chatbot', HOUR_IN_SECONDS );
		} else {
			// Atomically increment the count
			$request_count = wp_cache_incr( $transient_key, 1, 'ollama_chatbot' );
		}

		// Synchronize with transients for fallback persistence
		set_transient( $transient_key, $request_count, HOUR_IN_SECONDS );

		if ( $request_count >= $this->get_request_limit() ) {
			$banned_ips = (array) get_option( 'ollama_banned_ips', [] );
			$banned_ips[] = $ip;
			update_option( 'ollama_banned_ips', $banned_ips );
			$error_message = apply_filters(
				'ollama_chatbot_rate_limit_error',
				__( 'Your IP has been temporarily blocked due to excessive requests.', self::TEXT_DOMAIN ),
				$ip
			);
			$this->send_admin_notification(
				__( 'Chatbot Rate Limit Exceeded', self::TEXT_DOMAIN ),
				sprintf( __( 'The IP %s has been blocked due to too many requests.', self::TEXT_DOMAIN ), $ip )
			);
			if ( get_option( 'ollama_debug_mode' ) ) {
				$this->send_admin_notification(
					__( 'Debug: Rate Limit Exceeded', self::TEXT_DOMAIN ),
					sprintf( 'Debug Info: IP %s reached limit at %s', $ip, current_time( 'mysql' ) )
				);
			}
			return [ 'error' => $error_message ];
		}
		return [];
	}

	/**
	 * Retrieve and validate API settings.
	 *
	 * @return array API settings or error.
	 */
	private function get_api_settings(): array {
		$prompt   = trim( (string) get_option( 'ollama_prompt', '' ) );
		$endpoint = trim( (string) get_option( 'ollama_endpoint', '' ) );
		$endpoint = esc_url_raw( $endpoint );
		$api_key  = trim( (string) get_option( 'ollama_api_key', '' ) );
		$model    = trim( (string) get_option( 'ollama_model', '' ) );
		if ( empty( $prompt ) || empty( $endpoint ) || empty( $api_key ) || empty( $model ) ) {
			return [ 'error' => __( 'One or more required API settings are missing.', self::TEXT_DOMAIN ) ];
		}
		if ( ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
			return [ 'error' => __( 'Invalid API endpoint URL.', self::TEXT_DOMAIN ) ];
		}
		return [
			'prompt'   => $prompt,
			'endpoint' => apply_filters( 'ollama_chatbot_api_endpoint', $endpoint ),
			'api_key'  => $api_key,
			'model'    => $model,
		];
	}

	/**
	 * Retrieve a filtered API timeout.
	 *
	 * @return int Timeout in seconds.
	 */
	private function get_api_timeout(): int {
		return (int) apply_filters( 'ollama_chatbot_api_timeout', 15 );
	}

	/**
	 * Process a chat request with history and caching.
	 *
	 * @param string $user_message User's message.
	 * @param string $ip           Client IP.
	 * @return array Response or error.
	 */
	private function process_chat_request_logic( string $user_message, string $ip ): array {
		// Apply spam filtering.
		$user_message = apply_filters( 'ollama_chatbot_filter_user_input', $user_message );

		if ( $this->is_ip_banned( $ip ) ) {
			return [ 'error' => __( 'Your IP has been temporarily blocked due to excessive requests.', self::TEXT_DOMAIN ) ];
		}
		$rate_limit = $this->check_rate_limit( $ip );
		if ( ! empty( $rate_limit ) ) {
			return $rate_limit;
		}
		$user_message = trim( $user_message );
		if ( empty( $user_message ) ) {
			return [ 'error' => __( 'User message is empty.', self::TEXT_DOMAIN ) ];
		}
		$conversation_id = $this->get_conversation_id();
		$history = $this->get_conversation_history( $conversation_id );

		// Special commands.
		if ( strtolower( $user_message ) === 'reset' ) {
			$this->clear_conversation_history( $conversation_id );
			return [
				'userMessage'      => '<div class="ollama-chat-message ollama-user"><strong>' . esc_html__( 'You', self::TEXT_DOMAIN ) . ':</strong> ' . esc_html( $user_message ) . '</div>',
				'assistantMessage' => '<div class="ollama-chat-message ollama-assistant"><strong>' . esc_html__( 'Assistant', self::TEXT_DOMAIN ) . ':</strong> ' . esc_html__( 'Conversation reset.', self::TEXT_DOMAIN ) . '</div>',
				'conversation_id'  => $conversation_id,
			];
		}
		if ( strtolower( $user_message ) === 'history' ) {
			$formatted_history = '';
			foreach ( $history as $msg ) {
				$role = ucfirst( $msg['role'] );
				$formatted_history .= sprintf(
					'<div class="ollama-chat-message ollama-%s"><strong>%s:</strong> %s</div>',
					esc_attr( $msg['role'] ),
					esc_html( $role ),
					wp_kses_post( $msg['content'] )
				);
			}
			return [
				'userMessage'      => '',
				'assistantMessage' => $formatted_history,
				'conversation_id'  => $conversation_id,
			];
		}

		// Append user message.
		$history[] = [ 'role' => 'user', 'content' => $user_message ];

		// Caching.
		$cache_enabled = apply_filters( 'ollama_chatbot_cache_enabled', self::$cache_enabled );
		if ( $cache_enabled ) {
			$cache_key = 'ollama_api_cache_' . $conversation_id . '_' . md5( serialize( $history ) );
			$cached_response = wp_cache_get( $cache_key, 'ollama_chatbot' );
			if ( false === $cached_response ) {
				$cached_response = get_transient( $cache_key );
			} else {
				$this->debug_log( sprintf( 'Using object cache API response for conversation %s', $conversation_id ) );
				return $cached_response;
			}
			if ( false !== $cached_response ) {
				$this->debug_log( sprintf( 'Using transient cache API response for conversation %s', $conversation_id ) );
				return $cached_response;
			}
		}

		// Build API payload.
		$api_payload = [
			'model'    => '',
			'messages' => $history,
		];
		$settings = $this->get_api_settings();
		if ( isset( $settings['error'] ) ) {
			return [ 'error' => $settings['error'] ];
		}
		if ( empty( $api_payload['model'] ) ) {
			$api_payload['model'] = $settings['model'];
		}
		if ( empty( $history ) ) {
			$history[] = [ 'role' => 'system', 'content' => $settings['prompt'] ];
		}
		do_action( 'ollama_chatbot_before_api_call', $api_payload, $ip );

		// API call with exponential backoff.
		$start_time = microtime( true );
		$attempt = 0;
		$max_attempts = 3;
		$response = null;
		while ( $attempt < $max_attempts ) {
			try {
				$response = wp_remote_post( $settings['endpoint'], [
					'headers' => [
						'Authorization' => 'Bearer ' . $settings['api_key'],
						'Content-Type'  => 'application/json',
					],
					'body'    => wp_json_encode( $api_payload ),
					'timeout' => $this->get_api_timeout(),
				] );
				break;
			} catch ( \Exception $e ) {
				$this->debug_log( sprintf( 'API call attempt %d failed for IP %s: %s', $attempt + 1, $ip, $e->getMessage() ) );
				do_action( 'ollama_chatbot_external_log', sprintf( 'Attempt %d: %s', $attempt + 1, $e->getMessage() ) );
				$backoff = pow( 2, $attempt );
				sleep( $backoff );
				$attempt++;
			}
		}
		$api_duration = microtime( true ) - $start_time;
		$this->debug_log( sprintf( 'API call for IP %s took %.3f seconds', $ip, $api_duration ) );
		if ( is_wp_error( $response ) ) {
			$error_msg = sprintf( 'API error for IP %s: %s', $ip, $response->get_error_message() );
			$this->debug_log( $error_msg );
			return [ 'error' => sprintf( __( 'Error calling OpenWebUI API: %s', self::TEXT_DOMAIN ), $response->get_error_message() ) ];
		}
		$status_code   = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$this->debug_log( sprintf( 'API response for IP %s: %s', $ip, $response_body ) );
		if ( $status_code !== 200 ) {
			$this->debug_log( sprintf( 'API returned status code %d for IP %s', $status_code, $ip ) );
			return [ 'error' => __( 'Unexpected response from OpenWebUI API.', self::TEXT_DOMAIN ) ];
		}
		$response_data = json_decode( $response_body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->debug_log( 'JSON decode error: ' . json_last_error_msg() );
			return [ 'error' => __( 'Invalid response from API.', self::TEXT_DOMAIN ) ];
		}
		do_action( 'ollama_chatbot_after_api_call', $response_data, $ip );
		if ( isset( $response_data['choices'][0]['message']['content'] ) ) {
			$assistant_message = $response_data['choices'][0]['message']['content'];
			$history[] = [ 'role' => 'assistant', 'content' => $assistant_message ];
			$this->update_conversation_history( $conversation_id, $history );
			// Custom avatars via filters.
			$user_avatar = apply_filters( 'ollama_chatbot_user_avatar', '', get_locale() );
			$assistant_avatar = apply_filters( 'ollama_chatbot_assistant_avatar', '', get_locale() );

			$formatted_user_message = sprintf(
				'<div class="ollama-chat-message ollama-user">%s<strong>%s:</strong> %s</div>',
				$user_avatar ? '<img src="' . esc_url( $user_avatar ) . '" alt="User Avatar" class="ollama-avatar" /> ' : '',
				esc_html__( 'You', self::TEXT_DOMAIN ),
				esc_html( $user_message )
			);
			$formatted_assistant_message = sprintf(
				'<div class="ollama-chat-message ollama-assistant">%s<strong>%s:</strong> %s</div>',
				$assistant_avatar ? '<img src="' . esc_url( $assistant_avatar ) . '" alt="Assistant Avatar" class="ollama-avatar" /> ' : '',
				esc_html__( 'Assistant', self::TEXT_DOMAIN ),
				wp_kses_post( $assistant_message )
			);
			$response_final = apply_filters( 'ollama_chatbot_response', [
				'userMessage'      => $formatted_user_message,
				'assistantMessage' => $formatted_assistant_message,
				'conversation_id'  => $conversation_id,
				'apiResponseTime'  => $api_duration,
			], $response_data );
			if ( $cache_enabled ) {
				set_transient( $cache_key, $response_final, self::$cache_expiration );
				wp_cache_set( $cache_key, $response_final, 'ollama_chatbot', self::$cache_expiration );
			}
			do_action( 'ollama_chatbot_after_response', $conversation_id, $response_data );
			do_action( 'ollama_chatbot_after_sentiment_analysis', $conversation_id, $response_final );
			do_action( 'ollama_chatbot_monitoring_handler', $conversation_id, $api_duration );
			do_action( 'ollama_chatbot_analytics', $conversation_id, [
				'message_count' => count( $history ),
				'conversation_duration' => $api_duration,
			] );
			return $response_final;
		} else {
			$this->debug_log( 'Unexpected API response: ' . print_r( $response_data, true ) );
			return [ 'error' => __( 'Unexpected response from OpenWebUI API.', self::TEXT_DOMAIN ) ];
		}
	}

	/**
	 * Send a JSON response and exit.
	 *
	 * @param array $data   Response data.
	 * @param int   $status HTTP status code.
	 */
	private function send_json_response( array $data, int $status = 200 ): void {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			wp_send_json( $data, $status );
		} else {
			if ( $status >= 400 ) {
				wp_send_json_error( $data, $status );
			} else {
				wp_send_json_success( $data, $status );
			}
		}
		exit;
	}

	/**
	 * Handle legacy admin-post chat requests.
	 */
	public function handle_chat_request(): void {
		$this->validate_admin_request();
		$ip = $this->get_client_ip();
		$user_message = $this->get_post_param( 'userMessage' );
		$result = $this->process_chat_request_logic( $user_message, $ip );
		if ( isset( $result['error'] ) ) {
			$this->send_json_response( [ 'message' => $result['error'] ], 400 );
		} else {
			$this->send_json_response( $result, 200 );
		}
	}

	/**
	 * Register REST API route for chat requests.
	 */
	public function register_rest_routes(): void {
		register_rest_route( 'ollama-chatbot/v1', '/chat', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_chat_request_rest' ],
			'permission_callback' => [ $this, 'rest_permission_check' ],
		] );
	}

	/**
	 * REST API permission check.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public function rest_permission_check( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Nonce verification failed.', self::TEXT_DOMAIN ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Handle REST API chat requests.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response JSON response.
	 */
	public function handle_chat_request_rest( \WP_REST_Request $request ): \WP_REST_Response {
		$user_message = sanitize_textarea_field( wp_unslash( (string)$request->get_param( 'userMessage' ) ) );
		$ip = $this->get_client_ip();
		$result = $this->process_chat_request_logic( $user_message, $ip );
		if ( isset( $result['error'] ) ) {
			return new \WP_REST_Response( [ 'message' => $result['error'] ], 400 );
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/* ========================================================================== EXTENDED REST API: Conversation Log Endpoints ========================================================================== */

	/**
	 * Register REST API route for conversation log retrieval.
	 */
	public function register_rest_log_routes(): void {
		register_rest_route( 'ollama-chatbot/v1', '/logs/(?P<id>[\w\-\.]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_conversation_log_rest' ],
			'permission_callback' => [ $this, 'rest_permission_check' ],
		] );
	}

	/**
	 * REST API endpoint: Get conversation log by ID.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response JSON response.
	 */
	public function get_conversation_log_rest( \WP_REST_Request $request ): \WP_REST_Response {
		$conversation_id = sanitize_text_field( $request->get_param( 'id' ) );
		if ( empty( $conversation_id ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Conversation ID required.', self::TEXT_DOMAIN ) ], 400 );
		}
		global $wpdb;
		$table_name = $wpdb->prefix . 'ollama_chatbot_logs';
		$log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE conversation_id = %s", $conversation_id ), ARRAY_A );
		if ( $log ) {
			return new \WP_REST_Response( $log, 200 );
		}
		return new \WP_REST_Response( [ 'message' => __( 'Log not found.', self::TEXT_DOMAIN ) ], 404 );
	}

	/**
	 * Register REST API route for updating conversation logs.
	 */
	public function register_rest_update_log_route(): void {
		register_rest_route( 'ollama-chatbot/v1', '/update-log', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'update_conversation_log_rest' ],
			'permission_callback' => [ $this, 'rest_permission_check' ],
		] );
	}

	/**
	 * REST API endpoint: Update conversation log.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response JSON response.
	 */
	public function update_conversation_log_rest( \WP_REST_Request $request ): \WP_REST_Response {
		$conversation_id = sanitize_text_field( $request->get_param( 'conversation_id' ) );
		$new_history = $request->get_param( 'history' );
		if ( empty( $conversation_id ) || empty( $new_history ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Conversation ID and new history are required.', self::TEXT_DOMAIN ) ], 400 );
		}
		$this->update_conversation_history( $conversation_id, $new_history );
		return new \WP_REST_Response( [ 'message' => __( 'Conversation log updated.', self::TEXT_DOMAIN ) ], 200 );
	}

	/**
	 * Register REST API route for user feedback.
	 */
	public function register_rest_feedback_route(): void {
		register_rest_route( 'ollama-chatbot/v1', '/feedback', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_feedback' ],
			'permission_callback' => [ $this, 'rest_permission_check' ],
		] );
	}

	/**
	 * REST API endpoint: Handle user feedback for a conversation.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response JSON response.
	 */
	public function handle_feedback( \WP_REST_Request $request ): \WP_REST_Response {
		$conversation_id = sanitize_text_field( $request->get_param( 'conversation_id' ) );
		$feedback = sanitize_text_field( $request->get_param( 'feedback' ) );
		$rating = (int)$request->get_param( 'rating' );
		if ( empty( $conversation_id ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Conversation ID is required.', self::TEXT_DOMAIN ) ], 400 );
		}
		do_action( 'ollama_chatbot_feedback', $conversation_id, $feedback, $rating );
		return new \WP_REST_Response( [ 'message' => __( 'Feedback received.', self::TEXT_DOMAIN ) ], 200 );
	}

	/* ========================================================================== SCHEDULED TASKS & DEBUGGING ========================================================================== */

	/**
	 * Clear banned IPs.
	 */
	public function clear_banned_ips(): void {
		update_option( 'ollama_banned_ips', [] );
	}

	/**
	 * Scheduled cleanup: Delete logs older than retention period.
	 */
	public function cleanup_conversation_logs(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ollama_chatbot_logs';
		$days = (int) apply_filters( 'ollama_chatbot_log_retention_days', self::LOG_RETENTION_DAYS );
		$cutoff = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE created_at < %s", $cutoff ) );
		$this->debug_log( sprintf( __( "Cleaned up logs older than %d days.", self::TEXT_DOMAIN ), $days ) );
	}

	/**
	 * Write debug logs.
	 *
	 * @param string $message Log message.
	 */
	private function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[Ollama Chatbot] %s | User Agent: %s', $message, $_SERVER['HTTP_USER_AGENT'] ?? 'unknown' ) );
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				$log_file = WP_CONTENT_DIR . '/debug-ollama-chatbot.log';
				if ( is_writable( dirname( $log_file ) ) ) {
					$formatted_message = sprintf( "[%s] %s\n", date( 'Y-m-d H:i:s' ), $message );
					file_put_contents( $log_file, $formatted_message, FILE_APPEND );
				} else {
					error_log( $message );
				}
			}
		}
	}

	/* ========================================================================== WP-CLI COMMANDS, GUTENBERG BLOCK & WIDGET ========================================================================== */

	/**
	 * Register WP-CLI commands.
	 */
	private function register_wp_cli_commands(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ollama-chatbot', function ( $args, $assoc_args ) {
				\WP_CLI::line( __( 'Listing recent conversation logs:', self::TEXT_DOMAIN ) );
				global $wpdb;
				$table_name = $wpdb->prefix . 'ollama_chatbot_logs';
				$logs = $wpdb->get_results( "SELECT conversation_id, created_at FROM {$table_name} ORDER BY created_at DESC LIMIT 10", ARRAY_A );
				foreach ( $logs as $log ) {
					\WP_CLI::line( sprintf( "%s - %s", $log['conversation_id'], $log['created_at'] ) );
				}
			}, [
				'shortdesc' => __( 'List recent chatbot conversation logs.', self::TEXT_DOMAIN ),
			] );
			\WP_CLI::add_command( 'ollama-chatbot-flush-cache', function () {
				delete_transient( 'ollama_api_cache_*' );
				wp_cache_flush();
				\WP_CLI::success( __( 'API cache flushed.', self::TEXT_DOMAIN ) );
			}, [
				'shortdesc' => __( 'Flush API response cache.', self::TEXT_DOMAIN ),
			] );
			\WP_CLI::add_command( 'ollama-chatbot-list-banned-ips', function () {
				$banned_ips = get_option( 'ollama_banned_ips', [] );
				if ( empty( $banned_ips ) ) {
					\WP_CLI::success( __( 'No banned IPs.', self::TEXT_DOMAIN ) );
				} else {
					\WP_CLI::line( __( 'Banned IP Addresses:', self::TEXT_DOMAIN ) );
					foreach ( $banned_ips as $ip ) {
						\WP_CLI::line( $ip );
					}
				}
			}, [
				'shortdesc' => __( 'List all banned IP addresses.', self::TEXT_DOMAIN ),
			] );
		}
	}

	/**
	 * Register Gutenberg block.
	 */
	private function register_gutenberg_block(): void {
		add_action( 'init', function () {
			if ( function_exists( 'register_block_type' ) ) {
				register_block_type( 'ollama/chatbot', [
					'editor_script'   => 'ollama-chatbot-script',
					'render_callback' => [ $this, 'render_shortcode' ],
				] );
			}
		} );
	}

	/**
	 * Register legacy widget.
	 */
	private function register_widget(): void {
		add_action( 'widgets_init', function () {
			// Since the widget class is defined inline (see below), register it directly.
			register_widget( \Ollama\Chatbot\Widget\Chatbot_Widget::class );
		} );
	}

	/* ========================================================================== PLUGIN ACTIVATION, DEACTIVATION & UNINSTALL ========================================================================== */

	/**
	 * Plugin activation: Schedule events, create DB table, set defaults.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( 'ollama_hourly_event' ) ) {
			wp_schedule_event( time(), 'hourly', 'ollama_hourly_event' );
		}
		if ( ! wp_next_scheduled( 'ollama_cleanup_conversation_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'ollama_cleanup_conversation_logs' );
		}
		$default_options = [
			'ollama_endpoint' => '',
			'ollama_api_key'  => '',
			'ollama_model'    => '',
			'ollama_prompt'   => '',
		];
		foreach ( $default_options as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
		self::create_conversation_table();
	}

	/**
	 * Plugin deactivation: Clear scheduled events.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'ollama_hourly_event' );
		wp_clear_scheduled_hook( 'ollama_cleanup_conversation_logs' );
	}

	/**
	 * Plugin uninstall: Remove options, transients, and drop DB table.
	 */
	public static function uninstall(): void {
		// Delete options
		delete_option( 'ollama_endpoint' );
		delete_option( 'ollama_api_key' );
		delete_option( 'ollama_model' );
		delete_option( 'ollama_prompt' );
		delete_option( 'ollama_banned_ips' );

		// Remove transients with a matching pattern.
		global $wpdb;
		$transients = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_ollama_%'" );
		foreach ( $transients as $transient ) {
			$key = str_replace( '_transient_', '', $transient );
			delete_transient( $key );
		}

		// Drop custom database table using dynamic table prefix.
		$table_name = $wpdb->prefix . 'ollama_chatbot_logs';
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		// Clear object cache.
		wp_cache_flush();
	}

	/**
	 * Create custom DB table for conversation logs.
	 */
	public static function create_conversation_table(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ollama_chatbot_logs';
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table_name} (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			conversation_id varchar(255) NOT NULL,
			user_id bigint(20) DEFAULT 0,
			user_ip varchar(100) NOT NULL,
			chat_history longtext NOT NULL,
			created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY conversation_id (conversation_id)
		) $charset_collate;";
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	/**
	 * Send admin notification email.
	 *
	 * @param string $subject Email subject.
	 * @param string $message Email body.
	 */
	private function send_admin_notification( string $subject, string $message ): void {
		$admin_email = apply_filters( 'ollama_chatbot_admin_email', get_option( 'admin_email' ) );
		wp_mail( $admin_email, $subject, $message );
	}

	/* ========================================================================== SHORTCODE CALLBACK ========================================================================== */

	/**
	 * Render the chatbot shortcode.
	 *
	 * This method outputs the complete HTML, CSS, and JavaScript for the enhanced chat interface.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts = [] ): string {
		// Merge default attributes with user-defined ones.
		$atts = shortcode_atts(
			[
				'title' => 'Chatbot',
			],
			$atts,
			'ollama_chatbot'
		);

		ob_start();
		?>
        <style>
            :root {
                --chat-bg: #f9f9f9;
                --chat-border: #ddd;
                --user-color: #0073aa;
                --assistant-color: #444;
                --error-color: #d63638;
                --dark-bg: #1a1a1a;
                --dark-text: #eee;
                --btn-bg: #0073aa;
                --btn-hover-bg: #005177;
                --btn-text: #fff;
                --highlight-color: #fffae6;
                --shadow-color: rgba(0, 0, 0, 0.1);
                --input-bg: #fff;
                --input-border: #ddd;
                --font-base: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            }

            *, *::before, *::after {
                box-sizing: border-box;
            }

            #ollama-chatbot-container {
                font-family: var(--font-base);
                line-height: 1.5;
                padding: 1.5rem;
                border-radius: 8px;
                box-shadow: 0 2px 8px var(--shadow-color);
                max-width: 1200px;
                margin: 0 auto;
            }

            #ollama-chatbot-container.light {
                background: var(--chat-bg);
                color: #333;
            }

            #ollama-chatbot-container.dark {
                background: var(--dark-bg);
                color: var(--dark-text);
                --input-bg: #2d2d2d;
                --input-border: #404040;
                --chat-border: #404040;
            }

            #ollama-chat-log {
                border: 1px solid var(--chat-border);
                border-radius: 6px;
                padding: 1rem;
                max-height: 500px;
                overflow-y: auto;
                background: inherit;
                color: inherit;
                margin-bottom: 1rem;
                scroll-behavior: smooth;
            }

            .ollama-chat-message {
                margin-bottom: 1rem;
                display: flex;
                align-items: flex-start;
                position: relative;
                transition: all 0.3s ease;
                padding: 0.75rem;
                border-radius: 6px;
            }

            .ollama-chat-message.new {
                background-color: var(--highlight-color);
            }

            .ollama-avatar {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                margin-right: 1rem;
                border: 2px solid var(--chat-border);
            }

            .ollama-message-content {
                flex: 1;
                line-height: 1.6;
            }

            .ollama-timestamp {
                font-size: 0.8rem;
                color: #777;
                margin-right: 0.5rem;
            }

            .ollama-user .ollama-message-content {
                color: var(--user-color);
            }

            .ollama-assistant .ollama-message-content {
                color: var(--assistant-color);
            }

            .ollama-error .ollama-message-content {
                color: var(--error-color);
                background: rgba(214, 54, 56, 0.1);
                padding: 0.75rem;
                border-radius: 4px;
            }

            .ollama-edit,
            .ollama-delete,
            .ollama-reaction {
                font-size: 0.9rem;
                margin-left: 0.5rem;
                cursor: pointer;
                color: #666;
                transition: color 0.2s ease;
            }

            .ollama-edit:hover,
            .ollama-delete:hover,
            .ollama-reaction:hover {
                color: var(--btn-bg);
            }

            #ollama-chatbot-container button {
                margin: 0.25rem;
                padding: 0.5rem 1rem;
                cursor: pointer;
                background: var(--btn-bg);
                color: var(--btn-text);
                border: none;
                border-radius: 4px;
                font-size: 0.9rem;
                transition: all 0.2s ease;
            }

            #ollama-chatbot-container button:hover {
                background: var(--btn-hover-bg);
                transform: translateY(-1px);
            }

            #ollama-chatbot-container button:active {
                transform: translateY(0);
            }

            #ollama-chatbot-container button:focus {
                outline: 2px solid var(--btn-bg);
                outline-offset: 2px;
            }

            #ollama-emoji-picker {
                display: none;
                border: 1px solid var(--chat-border);
                padding: 0.75rem;
                background: var(--input-bg);
                position: absolute;
                z-index: 1000;
                border-radius: 6px;
                box-shadow: 0 2px 8px var(--shadow-color);
            }

            #ollama-emoji-picker span {
                cursor: pointer;
                padding: 0.25rem;
                font-size: 1.2rem;
                transition: transform 0.2s ease;
                display: inline-block;
            }

            #ollama-emoji-picker span:hover {
                transform: scale(1.2);
            }

            #ollama-search {
                width: 100%;
                max-width: 400px;
                margin-bottom: 1rem;
                padding: 0.75rem;
                border: 1px solid var(--input-border);
                border-radius: 4px;
                background: var(--input-bg);
                color: inherit;
            }

            #ollama-user-message {
                width: 100%;
                padding: 0.75rem;
                border: 1px solid var(--input-border);
                border-radius: 4px;
                background: var(--input-bg);
                color: inherit;
                margin-bottom: 0.5rem;
                resize: vertical;
            }

            #ollama-notification-banner {
                display: none;
                background: var(--highlight-color);
                padding: 0.75rem;
                border-radius: 4px;
                margin-bottom: 1rem;
                text-align: center;
            }

            @media (max-width: 768px) {
                #ollama-chatbot-container {
                    padding: 1rem;
                }

                #ollama-chat-log {
                    max-height: 400px;
                }

                .ollama-avatar {
                    width: 32px;
                    height: 32px;
                }

                #ollama-chatbot-container button {
                    width: 100%;
                    margin: 0.25rem 0;
                }
            }
        </style>

        <div id="ollama-chatbot-container" class="light" role="region" aria-label="Chatbot Interface" style="font-family: sans-serif; padding: 10px;">
			<?php if ( ! empty( $atts['title'] ) ) : ?>
                <h2><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>

            <!-- Controls -->
            <div style="margin-bottom: 10px;">
                <button type="button" id="ollama-toggle-dark" aria-label="Toggle Dark Mode">Toggle Dark Mode</button>
                <button type="button" id="ollama-download-log" aria-label="Download Chat Log">Download Chat Log</button>
                <button type="button" id="ollama-start-voice" aria-label="Start Voice Input">🎤 Voice Input</button>
                <button type="button" id="ollama-reset-convo" aria-label="Reset Conversation">Reset Conversation</button>
                <button type="button" id="ollama-scroll-bottom" aria-label="Scroll to Bottom">Scroll to Bottom</button>
                <button type="button" id="ollama-emoji-btn" aria-label="Emoji Picker">😊 Emoji</button>
                <button type="button" id="ollama-save-chat" aria-label="Save Chat">Save Chat</button>
            </div>

            <!-- Notification banner -->
            <div id="ollama-notification-banner" aria-live="assertive">New message received!</div>

            <!-- Search box for chat log -->
            <input type="text" id="ollama-search" placeholder="Search messages..." aria-label="Search Chat Log">

            <!-- Chat log container (ARIA live region for accessibility) -->
            <div id="ollama-chat-log" aria-live="polite"></div>

            <!-- Typing indicator -->
            <div id="ollama-typing" style="display:none; font-style: italic;">Assistant is typing...</div>

            <!-- Loading indicator -->
            <div id="ollama-loading" style="display:none; text-align: center;">Loading...</div>

            <!-- Chat form -->
            <form id="ollama-chat-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-label="Send Message">
                <input type="hidden" name="action" value="ollama_handle_chat_request">
                <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
                <!-- Use a textarea for multi-line input with auto-save draft -->
                <textarea id="ollama-user-message" name="userMessage" placeholder="Type your message..." style="width: 70%; height: 60px;" aria-required="true"></textarea>
                <button type="submit">Send</button>
                <button type="button" id="ollama-clear-chat" aria-label="Clear Chat">Clear Chat</button>
            </form>

            <!-- Emoji picker panel -->
            <div id="ollama-emoji-picker">
				<?php
				$emojis = ['😊', '😂', '👍', '❤️', '😢', '😮', '😡', '🙌', '🎉', '🤔'];
				foreach ( $emojis as $emoji ) {
					echo '<span class="ollama-emoji">' . $emoji . '</span> ';
				}
				?>
            </div>

            <!-- Optional audio element for notification sound -->
            <audio id="ollama-notification-sound" style="display:none;">
                <source src="<?php echo esc_url( plugins_url( 'notification.mp3', __FILE__ ) ); ?>" type="audio/mpeg">
            </audio>

            <script>
                (function($) {
                    $(document).ready(function() {
                        // --- Feature: Auto-Save Draft ---
                        function loadDraft() {
                            var draft = localStorage.getItem('ollamaDraft');
                            if (draft) {
                                $('#ollama-user-message').val(draft);
                            }
                        }

                        function saveDraft() {
                            localStorage.setItem('ollamaDraft', $('#ollama-user-message').val());
                        }

                        $('#ollama-user-message').on('input', saveDraft);
                        loadDraft();

                        // --- Feature: Chat Log Search ---
                        $('#ollama-search').on('keyup', function() {
                            var term = $(this).val().toLowerCase();
                            $('#ollama-chat-log .ollama-chat-message').each(function() {
                                var text = $(this).text().toLowerCase();
                                $(this).toggle(text.indexOf(term) > -1);
                            });
                        });

                        // --- Feature: File Attachment Support ---
                        var attachmentInput = $('<input type="file" accept="image/*" style="display:none;">');
                        $('body').append(attachmentInput);
                        $('<button type="button" id="ollama-attach" aria-label="Attach Image">Attach Image</button>')
                            .insertAfter('#ollama-clear-chat')
                            .on('click', function() {
                                attachmentInput.click();
                            });
                        attachmentInput.on('change', function() {
                            var file = this.files[0];
                            if (file) {
                                var reader = new FileReader();
                                reader.onload = function(e) {
                                    appendMessage(
                                        '#ollama-chat-log',
                                        '<div class="ollama-chat-message ollama-user">' +
                                        '<img class="ollama-avatar" src="<?php echo esc_url( plugins_url( "user.png", __FILE__ ) ); ?>" alt="User Avatar">' +
                                        '<div class="ollama-message-content">' +
                                        '<span class="ollama-timestamp">' + getCurrentTimestamp() + '</span> ' +
                                        '<strong>You:</strong> <img src="' + e.target.result + '" alt="Attachment" style="max-width:200px; display:block;">' +
                                        '</div>' +
                                        '</div>'
                                    );
                                };
                                reader.readAsDataURL(file);
                            }
                        });

                        // --- Feature: Language Selector for TTS/Voice ---
                        var langSelector = $('<select id="ollama-lang" aria-label="Select Language"></select>');
                        var languages = {
                            'en-US': 'English (US)',
                            'es-ES': 'Spanish',
                            'fr-FR': 'French',
                            'de-DE': 'German'
                        };
                        $.each(languages, function(code, label) {
                            langSelector.append('<option value="' + code + '">' + label + '</option>');
                        });
                        langSelector.insertAfter('#ollama-start-voice');

                        // --- Feature: Send Sound Effect ---
                        function playSendSound() {
                            if (window.AudioContext) {
                                var ctx = new AudioContext();
                                var oscillator = ctx.createOscillator();
                                oscillator.type = 'sine';
                                oscillator.frequency.setValueAtTime(440, ctx.currentTime);
                                oscillator.connect(ctx.destination);
                                oscillator.start();
                                oscillator.stop(ctx.currentTime + 0.1);
                            }
                        }

                        // --- Feature: Reaction System ---
                        function addReactions($msg) {
                            var reactions = $(
                                '<div class="ollama-reactions">' +
                                '<span class="ollama-reaction" data-reaction="👍">👍</span>' +
                                '<span class="ollama-reaction" data-reaction="👎">👎</span>' +
                                '</div>'
                            );
                            $msg.append(reactions);
                        }

                        $('#ollama-chat-log').on('click', '.ollama-reaction', function() {
                            var reaction = $(this).data('reaction');
                            $(this).closest('.ollama-chat-message').append('<span class="reaction-result">' + reaction + '</span>');
                        });

                        // --- Feature: Highlight New Message ---
                        function highlightNewMessage($msg) {
                            $msg.addClass('new');
                            setTimeout(function() {
                                $msg.removeClass('new');
                            }, 2000);
                        }

                        // --- Feature: Keyboard Shortcuts ---
                        $(document).on('keydown', function(e) {
                            if (e.ctrlKey && e.key === 'd') {
                                $('#ollama-toggle-dark').click();
                            }
                            if (e.ctrlKey && e.key === 'l') {
                                $('#ollama-clear-chat').click();
                            }
                        });

                        // --- Feature: Animated Scroll with Highlight ---
                        function scrollToNew($msg) {
                            scrollChatLog();
                            highlightNewMessage($msg);
                        }

                        // Focus on textarea.
                        $('#ollama-user-message').focus();

                        // Helper function to get current timestamp.
                        function getCurrentTimestamp() {
                            var now = new Date();
                            return now.toLocaleDateString() + ' ' + now.toLocaleTimeString();
                        }

                        // Function to smoothly scroll the chat log.
                        function scrollChatLog() {
                            var chatLog = $('#ollama-chat-log');
                            chatLog.animate({ scrollTop: chatLog[0].scrollHeight }, 300);
                        }

                        // Function to append a message with fadeIn animation.
                        function appendMessage(selector, messageHtml) {
                            var message = $(messageHtml).hide();
                            $(selector).append(message);
                            message.fadeIn(400, function() {
                                localStorage.setItem('ollamaChatLog', $('#ollama-chat-log').html());
                            });
                            scrollChatLog();
                            highlightNewMessage(message);
                        }

                        // Function to auto-dismiss error messages after 5 seconds.
                        function autoDismissErrors() {
                            setTimeout(function() {
                                $('.ollama-error').fadeOut(400, function() {
                                    $(this).remove();
                                });
                            }, 5000);
                        }

                        // Store last user message for possible retry.
                        var lastUserMessage = '';

                        // Function to play notification sound.
                        function playNotificationSound() {
                            var sound = $('#ollama-notification-sound')[0];
                            if (sound) {
                                sound.play();
                            }
                        }

                        // Function for TTS of assistant messages.
                        function speakMessage(message) {
                            if ('speechSynthesis' in window) {
                                var utterance = new SpeechSynthesisUtterance(message);
                                var selectedLang = $('#ollama-lang').val();
                                utterance.lang = selectedLang;
                                speechSynthesis.speak(utterance);
                            }
                        }

                        // Toggle dark mode.
                        $('#ollama-toggle-dark').on('click', function() {
                            $('#ollama-chatbot-container').toggleClass('dark light');
                        });

                        // Download chat log as a text file.
                        $('#ollama-download-log').on('click', function() {
                            var chatContent = $('#ollama-chat-log').text();
                            var blob = new Blob([chatContent], { type: 'text/plain;charset=utf-8' });
                            var url = URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = 'chat-log-' + new Date().toISOString() + '.txt';
                            a.click();
                            URL.revokeObjectURL(url);
                        });

                        // Reset conversation: clear chat log and localStorage draft.
                        $('#ollama-reset-convo').on('click', function() {
                            if (confirm('Reset the conversation? This will clear the chat log and reset your conversation.')) {
                                $('#ollama-chat-log').empty();
                                localStorage.removeItem('ollamaChatLog');
                                localStorage.removeItem('ollamaDraft');
                            }
                        });

                        // Scroll to bottom button.
                        $('#ollama-scroll-bottom').on('click', function() {
                            scrollChatLog();
                        });

                        // Emoji picker toggle.
                        $('#ollama-emoji-btn').on('click', function(e) {
                            e.stopPropagation();
                            $('#ollama-emoji-picker').toggle();
                        });

                        $('#ollama-emoji-picker').on('click', '.ollama-emoji', function() {
                            var emoji = $(this).text();
                            $('#ollama-user-message').val($('#ollama-user-message').val() + emoji).focus();
                            $('#ollama-emoji-picker').hide();
                        });

                        $(document).on('click', function() {
                            $('#ollama-emoji-picker').hide();
                        });

                        // Dummy Save Chat to Server.
                        $('#ollama-save-chat').on('click', function() {
                            var chatContent = $('#ollama-chat-log').html();
                            $.post(
                                ollamaChatbotVars.ajaxUrl,
                                {
                                    action: 'ollama_save_chat',
                                    nonce: ollamaChatbotVars.nonce,
                                    chatLog: chatContent
                                },
                                function(response) {
                                    alert(response.success ? "Chat saved to server." : "Failed to save chat.");
                                }
                            );
                        });

                        // Voice input using Web Speech API.
                        var recognizing = false;
                        var recognition;
                        if ('webkitSpeechRecognition' in window) {
                            recognition = new webkitSpeechRecognition();
                            recognition.continuous = false;
                            recognition.interimResults = false;
                            recognition.lang = 'en-US';
                            recognition.onresult = function(event) {
                                var transcript = event.results[0][0].transcript;
                                $('#ollama-user-message').val(transcript);
                                $('#ollama-chat-form').submit();
                            };
                            recognition.onerror = function(event) {
                                console.log('Speech recognition error', event);
                            };
                            recognition.onend = function() {
                                recognizing = false;
                                $('#ollama-start-voice').text('🎤 Voice Input');
                            };
                        }

                        $('#ollama-start-voice').on('click', function() {
                            if (recognizing) {
                                recognition.stop();
                                return;
                            }
                            if (recognition) {
                                recognizing = true;
                                $('#ollama-start-voice').text('Stop Voice');
                                recognition.start();
                            } else {
                                alert('Voice recognition not supported in this browser.');
                            }
                        });

                        // Handle Enter/Shift+Enter in the textarea.
                        $('#ollama-user-message').on('keydown', function(e) {
                            if (e.keyCode === 13 && !e.shiftKey) {
                                e.preventDefault();
                                $('#ollama-chat-form').submit();
                            }
                        });

                        // Function to render markdown (for assistant messages).
                        function renderMarkdown(text) {
                            if (typeof markdownit !== 'undefined') {
                                var md = markdownit();
                                return md.render(text);
                            }
                            return text;
                        }

                        // Handler for chat form submission.
                        $('#ollama-chat-form').on('submit', function(e) {
                            e.preventDefault();
                            var userMessage = $('#ollama-user-message').val().trim();
                            if (!userMessage) {
                                return;
                            }
                            lastUserMessage = userMessage;
                            playSendSound();

                            // Append user's message with avatar and timestamp.
                            appendMessage(
                                '#ollama-chat-log',
                                '<div class="ollama-chat-message ollama-user">' +
                                '<img class="ollama-avatar" src="<?php echo esc_url( plugins_url( "user.png", __FILE__ ) ); ?>" alt="User Avatar">' +
                                '<div class="ollama-message-content">' +
                                '<span class="ollama-timestamp">' + getCurrentTimestamp() + '</span> ' +
                                '<strong>You:</strong> ' + $('<div>').text(userMessage).html() +
                                ' <span class="ollama-edit" title="Edit Message">✎</span>' +
                                ' <span class="ollama-delete" title="Delete Message">×</span>' +
                                ' <span class="ollama-reaction" title="React">👍</span>' +
                                '</div>' +
                                '</div>'
                            );

                            $('#ollama-user-message').val('').focus();
                            $('#ollama-typing').show();
                            $('#ollama-loading').show();

                            $.post(
                                ollamaChatbotVars.ajaxUrl,
                                {
                                    action: 'ollama_handle_chat_request',
                                    nonce: ollamaChatbotVars.nonce,
                                    userMessage: userMessage
                                },
                                function(response) {
                                    $('#ollama-loading').hide();
                                    $('#ollama-typing').hide();
                                    if (response.success) {
                                        var data = response.data;
                                        appendMessage(
                                            '#ollama-chat-log',
                                            '<div class="ollama-chat-message ollama-assistant">' +
                                            '<img class="ollama-avatar" src="<?php echo esc_url( plugins_url( "assistant.png", __FILE__ ) ); ?>" alt="Assistant Avatar">' +
                                            '<div class="ollama-message-content">' +
                                            '<span class="ollama-timestamp">' + getCurrentTimestamp() + '</span> ' +
                                            '<strong>Assistant:</strong> ' + renderMarkdown(data.assistantMessage) +
                                            '</div>' +
                                            '</div>'
                                        );
                                        playNotificationSound();
                                        speakMessage(data.assistantMessage);
                                        $('#ollama-notification-banner').fadeIn(300).delay(2000).fadeOut(300);
                                    } else {
                                        var errorMsg = response.data.message || 'Error processing request.';
                                        var errorHtml = '<div class="ollama-chat-message ollama-error">' +
                                            '<div class="ollama-message-content">' +
                                            '<span class="ollama-timestamp">' + getCurrentTimestamp() + '</span> ' +
                                            '<strong>Error:</strong> ' + errorMsg +
                                            ' <button type="button" class="ollama-retry">Retry</button>' +
                                            '</div>' +
                                            '</div>';
                                        appendMessage('#ollama-chat-log', errorHtml);
                                        autoDismissErrors();
                                    }
                                }
                            );
                        });

                        // Delegate click event for retry button.
                        $('#ollama-chat-log').on('click', '.ollama-retry', function() {
                            $(this).closest('.ollama-error').remove();
                            $('#ollama-user-message').val(lastUserMessage).focus();
                            $('#ollama-chat-form').submit();
                        });

                        // Delegate click events for edit and delete icons on user messages.
                        $('#ollama-chat-log').on('click', '.ollama-edit', function() {
                            var $msgContent = $(this).closest('.ollama-message-content');
                            var currentText = $msgContent.text().replace(/^You:\s*/, '');
                            var newText = prompt("Edit your message:", currentText);
                            if (newText !== null) {
                                $msgContent.html(
                                    '<span class="ollama-timestamp">' + getCurrentTimestamp() + '</span> ' +
                                    '<strong>You:</strong> ' + $('<div>').text(newText).html() +
                                    ' <span class="ollama-edit" title="Edit Message">✎</span>' +
                                    ' <span class="ollama-delete" title="Delete Message">×</span>' +
                                    ' <span class="ollama-reaction" title="React">👍</span>'
                                );
                                localStorage.setItem('ollamaChatLog', $('#ollama-chat-log').html());
                            }
                        });

                        $('#ollama-chat-log').on('click', '.ollama-delete', function() {
                            if (confirm("Delete this message?")) {
                                $(this).closest('.ollama-chat-message').remove();
                                localStorage.setItem('ollamaChatLog', $('#ollama-chat-log').html());
                            }
                        });

                        // Handler for clear chat button.
                        $('#ollama-clear-chat').on('click', function() {
                            if (confirm('Are you sure you want to clear the chat log?')) {
                                $('#ollama-chat-log').empty();
                                localStorage.removeItem('ollamaChatLog');
                            }
                        });
                    });
                })(jQuery);
            </script>
        </div>
		<?php
		return ob_get_clean();
	}
}

/* ========================================================================== PLUGIN HOOKS & INITIALIZATION ========================================================================== */

register_activation_hook( __FILE__, [ Chatbot::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Chatbot::class, 'deactivate' ] );
register_uninstall_hook( __FILE__, [ Chatbot::class, 'uninstall' ] );

Chatbot::get_instance();

/*
 * Inline widget class definition.
 */
namespace Ollama\Chatbot\Widget;

use WP_Widget;

class Chatbot_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'ollama_chatbot_widget', // Base ID
			__('Ollama Chatbot', 'ollama-chatbot'), // Name
			['description' => __('A chatbot widget powered by OpenWebUI.', 'ollama-chatbot')]
		);
	}

	/**
	 * Output the content of the widget.
	 */
	public function widget( $args, $instance ) {
		echo $args['before_widget'];
		echo '<div id="ollama-chatbot-widget">';
		// Render the chatbot via the shortcode.
		if ( function_exists( 'do_shortcode' ) ) {
			echo do_shortcode('[ollama_chatbot]');
		}
		echo '</div>';
		echo $args['after_widget'];
	}

	/**
	 * Widget form in admin.
	 */
	public function form( $instance ) {
		echo '<p>' . esc_html__( 'No settings for this widget.', 'ollama-chatbot' ) . '</p>';
	}

	/**
	 * Update widget options.
	 */
	public function update( $new_instance, $old_instance ) {
		return $new_instance;
	}
}
