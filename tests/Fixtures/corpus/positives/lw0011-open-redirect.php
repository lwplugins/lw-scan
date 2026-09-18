<?php die('LW-SCAN DEMO MALWARE - inert test fixture, does nothing'); ?>
<?php
// lw:0011 redirect - header(Location) from request input (suspicious)
header('Location: ' . $_GET['url']);
