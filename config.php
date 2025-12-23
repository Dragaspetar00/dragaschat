<?php
define('DB_HOST', '193.111.77.77:   ');
define('DB_NAME', 'dragaschat');
define('DB_USER', 'root');
define('DB_PASS', '');

define('MAIL_FROM', 'dragaspetar0@gmail.com');
define('MAIL_PASS', 'mdpv pacz xtih zamp');
define('MAIL_NAME', 'DragasChat');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

spl_autoload_register(function($class) {
    $prefix = 'PHPMailer\\PHPMailer\\';
    $base = __DIR__ . '/phpmailer/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $file = $base . substr($class, strlen($prefix)) . '.php';
    if (file_exists($file)) require $file;
});

if (!file_exists(__DIR__ . "/phpmailer/PHPMailer.php")) {
    @mkdir(__DIR__ . "/phpmailer", 0755, true);
    foreach (['PHPMailer.php', 'SMTP.php', 'Exception.php'] as $f) {
        @file_put_contents(__DIR__ . "/phpmailer/$f", 
            @file_get_contents("https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/$f"));
    }
}

function db() {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    return $pdo;
}

function genCode($len = 8) {
    $c = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $r = '';
    for ($i = 0; $i < $len; $i++) $r .= $c[random_int(0, strlen($c) - 1)];
    return $r;
}

function sendMail($to, $subject, $body) {
    try {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = 'smtp.gmail.com';
        $m->SMTPAuth = true;
        $m->Username = MAIL_FROM;
        $m->Password = MAIL_PASS;
        $m->SMTPSecure = 'tls';
        $m->Port = 587;
        $m->CharSet = 'UTF-8';
        $m->setFrom(MAIL_FROM, MAIL_NAME);
        $m->addAddress($to);
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body = $body;
        $m->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function jsonOut($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function clean($s) {
    return htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8');
}