<?php
require_once dirname( __FILE__ ) . '/class-selfdirectory.php';
add_action( 'selfd_register', 'example_register_selfdirectory' );
function example_register_selfdirectory() {
	selfd( PLUGIN_FILE );
}
