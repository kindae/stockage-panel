<?php
require __DIR__ . '/inc/bootstrap.php';
if (needs_setup()) redirect('setup.php');
redirect(current_user() ? 'files.php' : 'login.php');
