<?php
// ---------------- PHPMailer ----------------
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class PHPMailerAutoload {
    public static function loader($class) {
        $prefix = 'PHPMailer\\PHPMailer\\';
        $base_dir = __DIR__ . '/phpmailer/';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
        $relative_class = substr($class, strlen($prefix));
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) require $file;
    }
}
spl_autoload_register('PHPMailerAutoload::loader');

// ---------------------------------------------

if (!file_exists(__DIR__ . "/phpmailer")) {
    mkdir("phpmailer");
    // PHPMailer minimal dosyaları ekliyoruz
    file_put_contents("phpmailer/PHPMailer.php", file_get_contents("https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/PHPMailer.php"));
    file_put_contents("phpmailer/SMTP.php", file_get_contents("https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/SMTP.php"));
    file_put_contents("phpmailer/Exception.php", file_get_contents("https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/Exception.php"));
}

// -------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $from = trim($_POST["from"]);
    $pass = trim($_POST["pass"]);
    $to   = trim($_POST["to"]);
    $subject = trim($_POST["subject"]);
    $message = trim($_POST["message"]);

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $from;
        $mail->Password   = $pass;
        $mail->SMTPSecure = 'tls';     // ZORUNLU
        $mail->Port       = 587;       // ZORUNLU

        $mail->setFrom($from, "Drawo");
        $mail->addAddress($to);

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
        $result = "Mail başarıyla gönderildi!";
    } catch (Exception $e) {
        $result = "Hata: " . $mail->ErrorInfo;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SMTP Mail Panel</title>
</head>
<body style="font-family: Arial; max-width:500px; margin:40px auto;">
    <h2>Mail Gönderme Paneli</h2>

    <?php if (!empty($result)) echo "<p><b>$result</b></p>"; ?>

    <form method="post">
        <label>Gmail adresiniz:</label><br>
        <input type="email" name="from" required style="width:100%; padding:8px;" placeholder="dragaspetar0@gmail.com"><br><br>

        <label>Uygulama şifresi (16 haneli):</label><br>
        <input type="text" name="pass" required style="width:100%; padding:8px;" placeholder="mdpv pacz xtih zamp" itemprop=""><br><br>

        <label>Alıcı adres:</label><br>
        <input type="email" name="to" required style="width:100%; padding:8px;"><br><br>

        <label>Konu:</label><br>
        <input type="text" name="subject" required style="width:100%; padding:8px;"><br><br>

        <label>Mesaj:</label><br>
        <textarea name="message" rows="6" style="width:100%; padding:8px;"></textarea><br><br>

        <button type="submit" style="padding:10px 20px;">Gönder</button>
    </form>
</body>
</html>
