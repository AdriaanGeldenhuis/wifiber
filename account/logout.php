<?php
require_once __DIR__ . '/../auth/helpers.php';
logout();
header('Location: /account/login.php');
exit;
