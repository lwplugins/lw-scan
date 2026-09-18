<?php die('LW-SCAN DEMO MALWARE - inert test fixture, does nothing'); ?>
<?php
// lw:0013 mailer - mail() recipient from request input (suspicious)
mail($_POST['to'], $subject, $body);
