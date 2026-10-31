<?php
declare(strict_types=1);
$sessionPath = STORAGE_PATH . DIRECTORY_SEPARATOR . 'sessions';
if (!is_dir($sessionPath)) mkdir($sessionPath, 0700, true);
session_save_path($sessionPath);
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Strict']);
session_start();
if(!empty($_SESSION['authenticated'])&&time()-(int)($_SESSION['last_activity']??time())>1800)unset($_SESSION['authenticated'],$_SESSION['user_id']);
if(!empty($_SESSION['authenticated']))$_SESSION['last_activity']=time();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
