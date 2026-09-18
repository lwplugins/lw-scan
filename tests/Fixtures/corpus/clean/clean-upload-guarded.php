<?php
// must NOT match 0010 - uses a sanitized var, not $_FILES directly
move_uploaded_file($tmpName, $dest);
