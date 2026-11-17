<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    redirect('/' . $_SESSION['user_role'] . '/index.php');
} else {
    redirect('/auth/login.php');
}
