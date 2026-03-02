<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package CFR2OffLoad\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'CFR2_TESTING', true );

global $_test_options,
	$_test_post_meta,
	$_test_posts,
	$_test_attachment_urls,
	$_test_attachment_metadata,
	$_test_attachment_mime_types,
	$_test_attachment_files,
	$_test_current_user_caps,
	$_test_transients,
	$_test_remote_head_responses,
	$_test_registered_rest_routes,
	$_test_filters,
	$_test_actions,
	$_test_actions_count,
	$_test_current_time;

$_test_options                = array();
$_test_post_meta              = array();
$_test_posts                  = array();
$_test_attachment_urls        = array();
$_test_attachment_metadata    = array();
$_test_attachment_mime_types  = array();
$_test_attachment_files       = array();
$_test_current_user_caps      = array();
$_test_transients             = array();
$_test_remote_head_responses  = array();
$_test_registered_rest_routes = array();
$_test_filters                = array();
$_test_actions                = array();
$_test_actions_count          = array();
$_test_current_time           = strtotime( '2026-03-02 00:00:00' );

if ( ! function_exists( 'cfr2_test_reset_wp_state' ) ) {
	/**
	 * Reset mocked WordPress state between tests.
	 */
	function cfr2_test_reset_wp_state(): void {
		global $_test_options,
			$_test_post_meta,
			$_test_posts,
			$_test_attachment_urls,
			$_test_attachment_metadata,
			$_test_attachment_mime_types,
			$_test_attachment_files,
			$_test_current_user_caps,
			$_test_transients,
			$_test_remote_head_responses,
			$_test_registered_rest_routes,
			$_test_filters,
			$_test_actions,
			$_test_actions_count,
			$_test_current_time;

		$_test_options                = array();
		$_test_post_meta              = array();
		$_test_posts                  = array();
		$_test_attachment_urls        = array();
		$_test_attachment_metadata    = array();
		$_test_attachment_mime_types  = array();
		$_test_attachment_files       = array();
		$_test_current_user_caps      = array();
		$_test_transients             = array();
		$_test_remote_head_responses  = array();
		$_test_registered_rest_routes = array();
		$_test_filters                = array();
		$_test_actions                = array();
		$_test_actions_count          = array();
		$_test_current_time           = strtotime( '2026-03-02 00:00:00' );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		global $_test_options;
		return $_test_options[ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value ) {
		global $_test_options;
		$_test_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		global $_test_options;
		unset( $_test_options[ $option ] );
		return true;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		global $_test_posts;
		return $_test_posts[ (int) $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		global $_test_post_meta;
		$post_id = (int) $post_id;

		if ( ! isset( $_test_post_meta[ $post_id ] ) ) {
			return $single ? '' : array();
		}

		if ( '' === $key ) {
			return $_test_post_meta[ $post_id ];
		}

		$value = $_test_post_meta[ $post_id ][ $key ] ?? ( $single ? '' : array() );
		if ( $single ) {
			return $value;
		}
		return is_array( $value ) ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $meta_key, $meta_value ) {
		global $_test_post_meta;
		$post_id = (int) $post_id;
		if ( ! isset( $_test_post_meta[ $post_id ] ) ) {
			$_test_post_meta[ $post_id ] = array();
		}
		$_test_post_meta[ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $meta_key ) {
		global $_test_post_meta;
		$post_id = (int) $post_id;
		unset( $_test_post_meta[ $post_id ][ $meta_key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) {
		global $_test_attachment_urls;
		return $_test_attachment_urls[ (int) $attachment_id ] ?? null;
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $attachment_id ) {
		global $_test_attachment_metadata;
		return $_test_attachment_metadata[ (int) $attachment_id ] ?? array();
	}
}

if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $attachment_id ) {
		global $_test_attachment_mime_types;
		return $_test_attachment_mime_types[ (int) $attachment_id ] ?? '';
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id ) {
		global $_test_attachment_files;
		return $_test_attachment_files[ (int) $attachment_id ] ?? '';
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		global $_test_current_time;
		$timestamp = (int) $_test_current_time;

		if ( 'timestamp' === $type || 'U' === $type ) {
			return $timestamp;
		}
		if ( 'mysql' === $type ) {
			return gmdate( 'Y-m-d H:i:s', $timestamp );
		}
		return gmdate( $type, $timestamp );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		global $_test_transients;
		return array_key_exists( $transient, $_test_transients ) ? $_test_transients[ $transient ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		global $_test_transients;
		$_test_transients[ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		global $_test_transients;
		unset( $_test_transients[ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string ) {
		return strip_tags( $string );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $message;

		public function __construct( string $message = 'error' ) {
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'wp_remote_head' ) ) {
	function wp_remote_head( $url, $args = array() ) {
		global $_test_remote_head_responses;
		return $_test_remote_head_responses[ $url ] ?? array( 'response' => array( 'code' => 200 ) );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? 200;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return strip_tags( trim( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		global $_test_actions;
		if ( ! isset( $_test_actions[ $hook ] ) ) {
			$_test_actions[ $hook ] = array();
		}
		if ( ! isset( $_test_actions[ $hook ][ $priority ] ) ) {
			$_test_actions[ $hook ][ $priority ] = array();
		}
		$_test_actions[ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => (int) $accepted_args,
		);
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		global $_test_actions, $_test_actions_count;

		$_test_actions_count[ $hook ] = ( $_test_actions_count[ $hook ] ?? 0 ) + 1;

		if ( empty( $_test_actions[ $hook ] ) ) {
			return;
		}

		ksort( $_test_actions[ $hook ] );
		foreach ( $_test_actions[ $hook ] as $callbacks ) {
			foreach ( $callbacks as $item ) {
				$call_args = array_slice( $args, 0, $item['accepted_args'] );
				call_user_func_array( $item['callback'], $call_args );
			}
		}
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		global $_test_actions_count;
		return (int) ( $_test_actions_count[ $hook ] ?? 0 );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		global $_test_filters;
		if ( ! isset( $_test_filters[ $hook ] ) ) {
			$_test_filters[ $hook ] = array();
		}
		if ( ! isset( $_test_filters[ $hook ][ $priority ] ) ) {
			$_test_filters[ $hook ][ $priority ] = array();
		}
		$_test_filters[ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => (int) $accepted_args,
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		global $_test_filters;

		if ( empty( $_test_filters[ $hook ] ) ) {
			return $value;
		}

		ksort( $_test_filters[ $hook ] );
		foreach ( $_test_filters[ $hook ] as $callbacks ) {
			foreach ( $callbacks as $item ) {
				$all_args = array_merge( array( $value ), $args );
				$call_args = array_slice( $all_args, 0, $item['accepted_args'] );
				$value = call_user_func_array( $item['callback'], $call_args );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		global $_test_current_user_caps;
		return (bool) ( $_test_current_user_caps[ $capability ] ?? false );
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $group, $name, $args = array() ) {
		return true;
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function = '', $icon_url = '', $position = null ) {
		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
		global $_test_registered_rest_routes;
		$_test_registered_rest_routes[ "{$namespace}{$route}" ] = $args;
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		$args = (array) $args;
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries ) {
		return array();
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class {
		public string $prefix   = 'wp_';
		public string $postmeta = 'wp_postmeta';
		public string $posts    = 'wp_posts';
		public string $options  = 'wp_options';

		public function get_charset_collate() {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		public function prepare( $query, ...$args ) {
			return $query;
		}

		public function query( $query ) {
			return 1;
		}

		public function get_row( $query, $output = OBJECT ) {
			return null;
		}

		public function get_results( $query, $output = OBJECT ) {
			return array();
		}

		public function get_var( $query, $x = 0, $y = 0 ) {
			return 0;
		}

		public function get_col( $query, $x = 0 ) {
			return array();
		}

		public function insert( $table, $data, $format = null ) {
			return 1;
		}

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			return 1;
		}

		public function esc_like( $text ) {
			return addcslashes( $text, '_%\\' );
		}
	};
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/var/www/html/' );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public $post_type = 'attachment';
		public $post_title;
		public $post_content;

		public function __construct( $post = null ) {
			if ( $post ) {
				foreach ( get_object_vars( $post ) as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = array();

		public function __construct( $method = 'GET', $route = '' ) {
			if ( is_array( $method ) ) {
				$this->params = $method;
			}
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( $key, $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private int $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

define( 'CFR2_VERSION', '1.0.0' );
define( 'CFR2_FILE', dirname( __DIR__ ) . '/cloudflare-r2-offload-cdn.php' );
define( 'CFR2_PATH', dirname( __DIR__ ) . '/' );
define( 'CFR2_URL', 'http://example.com/wp-content/plugins/cloudflare-r2-offload-cdn/' );
define( 'CFR2_BASENAME', 'cloudflare-r2-offload-cdn/cloudflare-r2-offload-cdn.php' );
