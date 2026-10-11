<?php
/**
 * PHPStan stubs for the WordPress hook API used by Egress Eye.
 *
 * @package Egress_Eye
 */

/**
 * Register a filter callback.
 *
 * @param string   $hook_name     Filter name.
 * @param callable $callback      Callback to invoke.
 * @param int      $priority      Callback priority.
 * @param int      $accepted_args Number of accepted arguments.
 *
 * @return true
 */
function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {}

/**
 * Register an action callback.
 *
 * @param string   $hook_name     Action name.
 * @param callable $callback      Callback to invoke.
 * @param int      $priority      Callback priority.
 * @param int      $accepted_args Number of accepted arguments.
 *
 * @return true
 */
function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {}

/**
 * Remove a filter callback.
 *
 * @param string   $hook_name Filter name.
 * @param callable $callback  Callback to remove.
 * @param int      $priority Callback priority.
 *
 * @return bool
 */
function remove_filter( $hook_name, $callback, $priority = 10 ) {}

/**
 * Remove an action callback.
 *
 * @param string   $hook_name Action name.
 * @param callable $callback  Callback to remove.
 * @param int      $priority Callback priority.
 *
 * @return bool
 */
function remove_action( $hook_name, $callback, $priority = 10 ) {}

/**
 * Parse a URL through the WordPress URL parser.
 *
 * @param string $url Original URL.
 * @return array<string,mixed>|false
 */
function wp_parse_url( $url ) {}

/**
 * Translate an English source string.
 *
 * @param string $text   Source string.
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {}

/**
 * WordPress preemptive error result.
 */
class WP_Error {

	/**
	 * Construct the error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	public function __construct( $code = '', $message = '' ) {}

	/**
 * @return string|int
 */
	public function get_error_code() {}
}


/**
 * WordPress get_option boundary declaration.
 *
 * @param string $option Core argument.
 * @param mixed $default Core argument.
 * @return mixed
 */
function get_option( $option, $default = false ) {}

/**
 * WordPress update_option boundary declaration.
 *
 * @param string $option Core argument.
 * @param mixed $value Core argument.
 * @param bool|string|null $autoload Core argument.
 * @return bool
 */
function update_option( $option, $value, $autoload = null ) {}

/**
 * WordPress delete_option boundary declaration.
 *
 * @param string $option Core argument.
 * @return bool
 */
function delete_option( $option ) {}

/**
 * WordPress wp_cache_get boundary declaration.
 *
 * @param int|string $key Core argument.
 * @param string $group Core argument.
 * @param bool $force Core argument.
 * @param bool|null $found Core argument.
 * @return mixed
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {}

/**
 * WordPress wp_cache_set boundary declaration.
 *
 * @param int|string $key Core argument.
 * @param mixed $data Core argument.
 * @param string $group Core argument.
 * @param int $expire Core argument.
 * @return bool
 */
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {}

/**
 * WordPress wp_cache_delete boundary declaration.
 *
 * @param int|string $key Core argument.
 * @param string $group Core argument.
 * @return bool
 */
function wp_cache_delete( $key, $group = '' ) {}

/**
 * WordPress is_multisite boundary declaration.
 *
 * @return bool
 */
function is_multisite() {}

/**
 * WordPress esc_html__ boundary declaration.
 *
 * @param string $text Core argument.
 * @param string $domain Core argument.
 * @return string
 */
function esc_html__( $text, $domain = 'default' ) {}

/**
 * WordPress wp_die boundary declaration.
 *
 * @param mixed $message Core argument.
 * @param string|int $title Core argument.
 * @param array<string,mixed>|int|string $args Core argument.
 * @return void
 */
function wp_die( $message = '', $title = '', $args = array() ) {}

/**
 * WordPress register_activation_hook boundary declaration.
 *
 * @param string $file Core argument.
 * @param callable $callback Core argument.
 * @return void
 */
function register_activation_hook( $file, $callback ) {}

/**
 * WordPress wp_next_scheduled boundary declaration.
 *
 * @param string $hook Core argument.
 * @param array<int,mixed> $args Core argument.
 * @return int|false
 */
function wp_next_scheduled( $hook, $args = array() ) {}

/**
 * WordPress wp_schedule_event boundary declaration.
 *
 * @param int $timestamp Core argument.
 * @param string $recurrence Core argument.
 * @param string $hook Core argument.
 * @param array<int,mixed> $args Core argument.
 * @param bool $wp_error Core argument.
 * @return bool|WP_Error
 */
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {}

/**
 * WordPress wp_clear_scheduled_hook boundary declaration.
 *
 * @param string $hook Core argument.
 * @param array<int,mixed> $args Core argument.
 * @param bool $wp_error Core argument.
 * @return int|false|WP_Error
 */
function wp_clear_scheduled_hook( $hook, $args = array(), $wp_error = false ) {}

/**
 * WordPress dbDelta boundary declaration.
 *
 * @param string|array<int,string> $queries Core argument.
 * @param bool $execute Core argument.
 * @return array<string,string>
 */
function dbDelta( $queries = '', $execute = true ) {}

const ABSPATH = "/";
const ARRAY_A = "ARRAY_A";

/** Database API declarations; not loaded at runtime. */
class wpdb {
	/**
 * @var \mysqli|false
 */
	public $dbh;
	/**
 * @var string
 */
	public $prefix;
	/**
 * @var string
 */
	public $options;
	/**
 * @var string
 */
	public $usermeta;
	/**
 * @var string
 */
	public $last_error;
	/**
 * @var int
 */
	public $insert_id;

	/**
	 * Core suppress_errors declaration.
	 *
	 * @param bool $suppress Core argument.
	 * @return bool
	 */
	public function suppress_errors( $suppress = true ) {}

	/**
	 * Core get_charset_collate declaration.
	 *
	 * @return string
	 */
	public function get_charset_collate() {}

	/**
	 * Core prepare declaration.
	 *
	 * @param string $query Core argument.
	 * @param mixed $args Core variadic argument.
	 * @return string|null
	 */
	public function prepare( $query, ...$args ) {}

	/**
	 * Core query declaration.
	 *
	 * @param string|null $query Core argument.
	 * @return int|bool
	 */
	public function query( $query ) {}

	/**
	 * Core get_var declaration.
	 *
	 * @param string|null $query Core argument.
	 * @param int $x Core argument.
	 * @param int $y Core argument.
	 * @return string|null
	 */
	public function get_var( $query = null, $x = 0, $y = 0 ) {}

	/**
	 * Core get_row declaration.
	 *
	 * @param string|null $query Core argument.
	 * @param string $output Core argument.
	 * @param int $y Core argument.
	 * @return array<string,mixed>|object|null
	 */
	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {}

	/**
	 * Core get_results declaration.
	 *
	 * @param string|null $query Core argument.
	 * @param string $output Core argument.
	 * @return array<int,array<string,mixed>|object>|null
	 */
	public function get_results( $query = null, $output = 'OBJECT' ) {}
}

/**
 * WordPress write-once non-autoload notice boundary.
 *
 * @param string    $option   Option name.
 * @param mixed     $value    Option value.
 * @param string    $obsolete Unused core parameter.
 * @param bool|null $autoload Autoload policy.
 * @return bool
 */
function add_option( $option, $value = '', $obsolete = '', $autoload = null ) {}

/**
 * Normalize a WordPress path.
 *
 * @param string $path Path.
 * @return string
 */
function wp_normalize_path( $path ) {}
/**
 * Resolve the registered plugin basename.
 *
 * @param string $file File.
 * @return string
 */
function plugin_basename( $file ) {}
/**
 * @return string
 */
function get_theme_root() {}
const WP_PLUGIN_DIR = '/wp-content/plugins';
const WPMU_PLUGIN_DIR = '/wp-content/mu-plugins';


/**
 * @param string $hook
 * @return int
 */
function did_action( $hook ) {}
/**
 * @param string $hook
 * @param mixed ...$args
 * @return void
 */
function do_action( $hook, ...$args ) {}
/**
 * @param string $capability
 * @return bool
 */
function current_user_can( $capability ) {}
/**
 * @return int
 */
function get_current_user_id() {}
/**
 * @param string $path
 * @return string
 */
function admin_url( $path = '' ) {}
/**
 * @param string $path
 * @return string
 */
function home_url( $path = '' ) {}
/**
 * @param string $path
 * @return string
 */
function site_url( $path = '' ) {}
/**
 * @param string $text
 * @return string
 */
function esc_html( $text ) {}
/**
 * @param string $text
 * @return string
 */
function esc_attr( $text ) {}
/**
 * @param string $url
 * @return string
 */
function esc_url( $url ) {}
/**
 * @param string $text
 * @return string
 */
function sanitize_text_field( $text ) {}
/**
 * @param string $text
 * @return string
 */
function sanitize_key( $text ) {}
/**
 * @param string $text
 * @return string
 */
function wp_unslash( $text ) {}
/**
 * @param string $action
 * @param string $query_arg
 * @return int|false
 */
function check_admin_referer( $action = '-1', $query_arg = '_wpnonce' ) {}
/**
 * @param string $action
 * @param string $name
 * @param bool $referer
 * @param bool $display
 * @return string
 */
function wp_nonce_field( $action = '-1', $name = '_wpnonce', $referer = true, $display = true ) {}
/**
 * @param string $location
 * @param int $status
 * @return bool
 */
function wp_safe_redirect( $location, $status = 302 ) {}
/**
 * @param string $key
 * @param string $value
 * @param string $url
 * @return string
 */
function add_query_arg( $key, $value, $url ) {}
/**
 * @param int $user
 * @param string $key
 * @param mixed $value
 * @return int|bool
 */
function update_user_meta( $user, $key, $value ) {}
/**
 * @param int $user
 * @param string $key
 * @param bool $single
 * @return mixed
 */
function get_user_meta( $user, $key, $single = false ) {}
/**
 * @param int $user
 * @param string $key
 * @param mixed $value
 * @return bool
 */
function delete_user_meta( $user, $key, $value = '' ) {}
/**
 * @return bool
 */
function is_rtl() {}
/**
 * @param string $page
 * @param string $menu
 * @param string $cap
 * @param string $slug
 * @param callable $callback
 * @return string|false
 */
function add_management_page( $page, $menu, $cap, $slug, $callback ) {}
/**
 * @param string $handle
 * @param string $src
 * @param array<int,string> $deps
 * @param string $ver
 * @return void
 */
function wp_enqueue_style( $handle, $src, $deps = array(), $ver = '' ) {}
/**
 * @param string $path
 * @param string $plugin
 * @return string
 */
function plugins_url( $path, $plugin = '' ) {}
/**
 * @param string $domain
 * @param bool $deprecated
 * @param string $path
 * @return bool
 */
function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ) {}
/** Hook API execution object; declaration only. */
class WP_Hook {
	/**
 * @var array<int,array<string,array{function:callable,accepted_args:int}>>
 */
	public $callbacks;
	/**
 * @param string $hook
 * @param callable $callback
 * @param int $priority
 * @param int $accepted_args
 * @return void
 */
	public function add_filter( $hook, $callback, $priority, $accepted_args ) {}
	/**
 * @param string $hook
 * @param callable $callback
 * @param int $priority
 * @return bool
 */
	public function remove_filter( $hook, $callback, $priority ) {}
}
