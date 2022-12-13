<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SelfDirectory' ) ) {
	final class SelfDirectory {
		public $version = '1.0.1';

		protected static $_instance = null;

		public $files = array();

		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		public function __construct() {
			add_action( 'init', array( $this, 'init' ) );
		}

		public function init() {
			if ( true !== apply_filters( 'selfd_load', ( is_admin() && ! defined( 'DOING_AJAX' ) ) ) ) {
				return;
			}

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_plugins' ) );

			foreach ( array( 'plugin' ) as $context ) {
				add_filter( "extra_{$context}_headers", array( $this, 'directory_header' ) );
			}

			do_action( 'selfd_register' );
		}

		public function register( $file ) {
			$this->files[] = $file;
		}

		public function directory_header( $extra_headers ) {
			$extra_headers[] = 'Directory';
			return $extra_headers;
		}

		public static function get_plugin_source( $plugin_file ) {
			$directory = '';
			if ( file_exists( $plugin_file ) ) {
				$data = get_plugin_data( $plugin_file, false, false );

				$data['Directory'] = esc_url( $data['Directory'] );
				if ( $data['Directory'] ) {
					$directory  = untrailingslashit( $data['Directory'] );
					$directory .= '/wp.json';
				}
			}
			return $directory;
		}

		public function update_plugins( $value ) {
			foreach ( $this->files as $file ) {
				$plugin = get_plugin_data( $file, false, false );
				$source = self::get_plugin_source( $file );
				if ( ! $source ) {
					return $value;
				}

				$http_url = $source;
				$url      = $http_url;
				$ssl      = wp_http_supports( array( 'ssl' ) );
				if ( $ssl ) {
					$url = set_url_scheme( $url, 'https' );
				}
				$raw_response = wp_remote_get( $url );
				if ( $ssl && is_wp_error( $raw_response ) ) {
					$raw_response = wp_remote_get( $http_url );
				}
				$response_code = (int) wp_remote_retrieve_response_code( $raw_response );
				if ( is_wp_error( $raw_response ) || 200 !== $response_code ) {
					return $value;
				}

				$response = (object) json_decode( wp_remote_retrieve_body( $raw_response ), true );
				$latest   = (object) $response->latest;
				if ( version_compare( $plugin['Version'], $latest->version ) ) {
					$basename = plugin_basename( $file );

					$value->response[ $basename ] = (object) array(
						'slug'         => $response->slug,
						'new_version'  => $latest->version,
						'package'      => $latest->package,
						'requires'     => $latest->requires,
						'tested'       => $latest->tested,
						'requires_php' => $latest->requires_php,
					);
				}
			}
			return $value;
		}
	}

	if ( ! function_exists( 'load_self_directory' ) ) {
		function load_self_directory() {
			$GLOBALS['selfd'] = SelfDirectory::instance();
		}
	}

	if ( did_action( 'plugins_loaded' ) ) {
		load_self_directory();
	} else {
		add_action( 'plugins_loaded', 'load_self_directory' );
	}
}

if ( ! function_exists( 'selfd' ) ) {
	function selfd( $file ) {
		$instance = call_user_func( array( get_class( $GLOBALS['selfd'] ), 'instance' ) );

		call_user_func( array( $instance, 'register' ), $file );
	}
}
