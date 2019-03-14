<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SelfDirectory' ) ) {
	final class SelfDirectory {
		public $version = '1.0.0';

		protected static $_instance = null;

		public $sources = array();

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

			do_action( 'selfd_register' );
		}

		public function register( $source ) {
			$this->sources[] = $source;
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
	function selfd( $source ) {
		$instance = call_user_func( array( get_class( $GLOBALS['selfd'] ), 'instance' ) );

		call_user_func( array( $instance, 'register' ), $source );
	}
}
