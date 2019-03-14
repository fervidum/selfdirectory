<?php
require_once dirname( __FILE__ ) . '/class-selfdirectory.php';
add_action( 'selfd_register', 'example_register_self_direcory' );
function example_register_self_direcory() {
	selfd( 'scheme://domain/file.json' );
}
