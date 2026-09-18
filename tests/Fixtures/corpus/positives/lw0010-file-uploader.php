<?php die('LW-SCAN DEMO MALWARE - inert test fixture, does nothing'); ?>
<?php
// lw:0010 uploader - move_uploaded_file driven by $_FILES (suspicious)
move_uploaded_file($_FILES['file']['tmp_name'], $dest);
