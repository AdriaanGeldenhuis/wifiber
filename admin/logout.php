<?php
require_once __DIR__ . '/../auth/helpers.php';
logout();
header('Location: /admin/login.php');
exit;
