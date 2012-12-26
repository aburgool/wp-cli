<?php

namespace WP_CLI\Utils;

function load_cli_tools() {
	$vendor_paths = array(
		WP_CLI_ROOT . '../../../../../vendor',  // part of a larger project
		WP_CLI_ROOT . '../../vendor',           // top-level project
	);

	$found = false;

	foreach ( $vendor_paths as $vendor_path ) {
		if ( file_exists( $vendor_path . '/autoload.php' ) ) {
			require $vendor_path . '/autoload.php';
			include $vendor_path . '/wp-cli/php-cli-tools/lib/cli/cli.php';
			$found = true;
			break;
		}
	}

	if ( !$found ) {
		include WP_CLI_ROOT . '../php-cli-tools/lib/cli/cli.php';
		\cli\register_autoload();
	}
}

function register_autoload() {
	spl_autoload_register( function($class) {
		// Only attempt to load classes in our namespace
		if ( 0 !== strpos( $class, 'WP_CLI\\' ) ) {
			return;
		}

		$base = WP_CLI_ROOT . 'classes/';

		$class = str_replace( 'WP_CLI\\', '', $class );

		$path = $base . str_replace( '\\', DIRECTORY_SEPARATOR, $class ) . '.php';

		if ( is_file( $path ) ) {
			require_once $path;
		}
	} );
}

/**
 * Splits $argv into positional and associative arguments.
 *
 * @param string
 * @return array
 */
function parse_args( $arguments ) {
	$regular_args = array();
	$assoc_args = array();

	foreach ( $arguments as $arg ) {
		if ( preg_match( '|^--([^=]+)$|', $arg, $matches ) ) {
			$assoc_args[ $matches[1] ] = true;
		} elseif ( preg_match( '|^--([^=]+)=(.+)|', $arg, $matches ) ) {
			$assoc_args[ $matches[1] ] = $matches[2];
		} else {
			$regular_args[] = $arg;
		}
	}

	return array( $regular_args, $assoc_args );
}

/**
 * Splits $argv into positional and associative arguments.
 *
 * @param string
 * @return array
 */
function split_assoc( &$assoc_args, $special_keys ) {
	$assoc_special = array();

	foreach ( $special_keys as $key ) {
		if ( isset( $assoc_args[ $key ] ) ) {
			$assoc_special[ $key ] = $assoc_args[ $key ];
			unset( $assoc_args[ $key ] );
		}
	}

	return $assoc_special;
}

function set_wp_root( $assoc_args ) {
	if ( !empty( $assoc_args['path'] ) ) {
		define( 'WP_ROOT', rtrim( $assoc_args['path'], '/' ) . '/' );
	} else {
		define( 'WP_ROOT', getcwd() . '/' );
	}
}

function set_url( $assoc_args ) {
	if ( isset( $assoc_args['url'] ) ) {
		$blog = $assoc_args['url'];
	} elseif ( isset( $assoc_args['blog'] ) ) {
		$blog = $assoc_args['blog'];
		if ( true === $blog ) {
			\WP_CLI::line( 'usage: wp --blog=example.com' );
		}
	} elseif ( is_readable( WP_ROOT . 'wp-cli-blog' ) ) {
		$blog = trim( file_get_contents( WP_ROOT . 'wp-cli-blog' ) );
	} elseif ( $wp_config_path = locate_wp_config() ) {
		// Try to find the blog parameter in the wp-config file
		$wp_config_file = file_get_contents( $wp_config_path );
		$hit = array();
		if ( preg_match_all( "#.*define\s*\(\s*(['|\"]{1})(.+)(['|\"]{1})\s*,\s*(['|\"]{1})(.+)(['|\"]{1})\s*\)\s*;#iU", $wp_config_file, $matches ) ) {
			foreach ( $matches[2] as $def_key => $def_name ) {
				if ( 'DOMAIN_CURRENT_SITE' == $def_name )
					$hit['domain'] = $matches[5][$def_key];
				if ( 'PATH_CURRENT_SITE' == $def_name )
					$hit['path'] = $matches[5][$def_key];
			}
		}

		if ( !empty( $hit ) && isset( $hit['domain'] ) )
			$blog = $hit['domain'];
		if ( !empty( $hit ) && isset( $hit['path'] ) )
			$blog .= $hit['path'];
	}

	if ( isset( $blog ) ) {
		set_url_params( $blog );
	}
}

/**
 * Sets the appropriate $_SERVER keys based on a given string
 *
 * @param string $url The URL
 */
function set_url_params( $url ) {
	$url_parts = parse_url( $url );

	if ( !isset( $url_parts['scheme'] ) ) {
		$url_parts = parse_url( 'http://' . $url );
	}

	$f = function( $key ) use ( $url_parts ) {
		return isset( $url_parts[ $key ] ) ? $url_parts[ $key ] : '';
	};

	$_SERVER['HTTP_HOST'] = $f('host');
	$_SERVER['REQUEST_URI'] = $f('path') . ( isset( $url_parts['query'] ) ? '?' . $url_parts['query'] : '' );
	$_SERVER['REQUEST_URL'] = $f('path');
	$_SERVER['QUERY_STRING'] = $f('query');
	$_SERVER['SERVER_NAME'] = substr($_SERVER['HTTP_HOST'], 0, strrpos($_SERVER['HTTP_HOST'], '.'));
	$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.0';
	$_SERVER['HTTP_USER_AGENT'] = '';
	$_SERVER['REQUEST_METHOD'] = 'GET';
}

function locate_wp_config() {
	if ( file_exists( WP_ROOT . 'wp-config.php' ) ) {
		return WP_ROOT . 'wp-config.php';
	} elseif ( file_exists( WP_ROOT . '/../wp-config.php' ) && ! file_exists( WP_ROOT . '/../wp-settings.php' ) ) {
		return WP_ROOT . '/../wp-config.php';
	} else {
		return false;
	}
}

// Loads wp-config.php without loading the rest of WP
function load_wp_config() {
	define( 'ABSPATH', dirname(__FILE__) . '/' );

	if ( $wp_config_path = locate_wp_config() )
		require locate_wp_config();
	else
		\WP_CLI::error( 'No wp-config.php file.' );
}



// ---- AFTER WORDPRESS IS LOADED ---- //



// Handle --user parameter
function set_user( $assoc_args ) {
	if ( !isset( $assoc_args['user'] ) )
		return;

	$user = $assoc_args['user'];

	if ( is_numeric( $user ) ) {
		$user_id = (int) $user;
	} else {
		$user_id = (int) username_exists( $user );
	}

	if ( !$user_id || !wp_set_current_user( $user_id ) ) {
		\WP_CLI::error( sprintf( 'Could not get a user_id for this user: %s', var_export( $user, true ) ) );
	}
}

function set_wp_query() {
	if ( isset( $GLOBALS['wp_query'] ) && isset( $GLOBALS['wp'] ) ) {
		$GLOBALS['wp']->parse_request();
		$GLOBALS['wp_query']->query($GLOBALS['wp']->query_vars);
	}
}

function get_upgrader( $class ) {
	if ( !class_exists( '\WP_Upgrader' ) )
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	return new $class( new \WP_CLI\UpgraderSkin );
}

function parse_csv( $filepath, $has_headers = true ) {

	if ( false == ( $csv = fopen( $filepath, 'r' ) ) )
		\WP_CLI::error( sprintf( 'Could not open csv file: %s', $filepath ) );

	$parsed_data = array();
	$headers = array();
	while ( ( $row = fgetcsv( $csv, 10000, "," ) ) !== FALSE ) {
		if ( $has_headers ) {
			$headers = array_values( $row );
			$has_headers = false;
			continue;
		}
		$row_data = array();
		foreach( $row as $index => $cell_value ) {
			if ( ! empty( $headers[$index] ) )
				$index = $headers[$index];
			$row_data[$index] = $cell_value;
		}
		$parsed_data[] = $row_data;
	}
	return $parsed_data;
}

/**
 * Take a serialised array and unserialise it replacing elements as needed and
 * unserialising any subordinate arrays and performing the replace on those too.
 *
 * @source https://github.com/interconnectit/Search-Replace-DB
 *
 * @param string $from       String we're looking to replace.
 * @param string $to         What we want it to be replaced with
 * @param array  $data       Used to pass any subordinate arrays back to in.
 * @param bool   $serialised Does the array passed via $data need serialising.
 *
 * @return array	The original array with all elements replaced as needed.
 */
function recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false ) {

	// some unseriliased data cannot be re-serialised eg. SimpleXMLElements
	try {

		if ( is_string( $data ) && ( $unserialized = @unserialize( $data ) ) !== false ) {
			$data = recursive_unserialize_replace( $from, $to, $unserialized, true );
		}

		elseif ( is_array( $data ) ) {
			$_tmp = array( );
			foreach ( $data as $key => $value ) {
				$_tmp[ $key ] = recursive_unserialize_replace( $from, $to, $value, false );
			}

			$data = $_tmp;
			unset( $_tmp );
		}

		// Submitted by Tina Matter
		elseif ( is_object( $data ) ) {
			$dataClass = get_class( $data );
			$_tmp = new $dataClass( );
			foreach ( $data as $key => $value ) {
				$_tmp->$key = recursive_unserialize_replace( $from, $to, $value, false );
			}

			$data = $_tmp;
			unset( $_tmp );
		}

		else {
			if ( is_string( $data ) )
				$data = str_replace( $from, $to, $data );
		}

		if ( $serialised )
			return serialize( $data );

	} catch( Exception $error ) {

	}

	return $data;
}

