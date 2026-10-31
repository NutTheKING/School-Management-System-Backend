<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/bootstrap.php';
require PROJECT_ROOT . '/private/Http/session.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
?><!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="description" content="WG System school management system"><title>WG SYSTEM</title><link rel="icon" type="image/png" href="assets/wg-logo.png"><link rel="stylesheet" href="styles.css?v=<?= rawurlencode((string)filemtime(PUBLIC_PATH.'/styles.css')) ?>"></head>
<body class="is-login">
<?php require PROJECT_ROOT . '/private/components/public/login.php'; ?>
<?php require PROJECT_ROOT . '/private/components/private/shell.php'; ?>
<script>window.NORTHSTAR={api:'api.php',csrf:<?= json_encode($_SESSION['csrf']) ?>,authenticated:<?= !empty($_SESSION['authenticated'])?'true':'false' ?>};</script><script src="assets/js/app-theme.js"></script><script src="app.js?v=<?= rawurlencode((string)filemtime(PUBLIC_PATH.'/app.js')) ?>"></script></body></html>
