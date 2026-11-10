<?php
// logout.php
require 'config.php';
session_start_safe();
session_destroy();
header('Location: index.php');
exit;
