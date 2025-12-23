<?php
session_start();
require 'config.php';

$db = db();
$user = null;

if (!empty($_SESSION['uid'])) {
    $st = $db->prepare("SELECT * FROM users WHERE id=?");
    $st->execute([$_SESSION['uid']]);
    $user = $st->fetch();
    if ($user) {
        $db->prepare("UPDATE users SET last_seen=NOW() WHERE id=?")->execute([$user['id']]);
    }
}

// ===== AJAX =====
if (!empty($_POST['act'])) {
    $act = $_POST['act'];
    
    // === KAYIT ===
    if ($act === 'register') {
        $u = clean($_POST['u'] ?? '');
        $e = trim($_POST['e'] ?? '');
        $p = $_POST['p'] ?? '';
        $em = $_POST['em'] ?? '😀';
        $pk = $_POST['pk'] ?? '';
        
        if (strlen($u) < 3 || strlen($u) > 20 || !preg_match('/^[a-zA-Z0-9_]+$/', $u)) {
            jsonOut(['err' => 'Kullanıcı adı 3-20 karakter, sadece harf/rakam/_']);
        }
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            jsonOut(['err' => 'Geçerli e-posta girin']);
        }
        if (strlen($p) < 6) {
            jsonOut(['err' => 'Şifre en az 6 karakter']);
        }
        
        // Kullanıcı adı/email kontrolü
        $st = $db->prepare("SELECT 1 FROM users WHERE username=? OR email=?");
        $st->execute([$u, $e]);
        if ($st->fetch()) jsonOut(['err' => 'Bu kullanıcı adı veya e-posta zaten kayıtlı']);
        
        // Pending kontrolü
        $st = $db->prepare("SELECT 1 FROM pending_users WHERE username=? OR email=?");
        $st->execute([$u, $e]);
        if ($st->fetch()) {
            $db->prepare("DELETE FROM pending_users WHERE username=? OR email=?")->execute([$u, $e]);
        }
        
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($p, PASSWORD_DEFAULT);
        
        $mail = "
        <div style='font-family:Arial;max-width:400px;margin:0 auto;padding:30px;'>
            <h2 style='color:#6366f1;'>⚡ DragasChat</h2>
            <p>Merhaba <b>$u</b>,</p>
            <p>Doğrulama kodunuz:</p>
            <div style='background:#f3f4f6;padding:20px;text-align:center;border-radius:12px;margin:20px 0;'>
                <span style='font-size:36px;font-weight:bold;letter-spacing:10px;color:#1f2937;' id='code'>$code</span>
            </div>
            <p style='color:#666;font-size:14px;'>Bu kod 15 dakika geçerlidir.</p>
        </div>";
        
        if (!sendMail($e, 'DragasChat Doğrulama', $mail)) {
            jsonOut(['err' => 'E-posta gönderilemedi']);
        }
        
        $st = $db->prepare("INSERT INTO pending_users (username,email,password,emoji,pubkey,verify_code) VALUES (?,?,?,?,?,?)");
        $st->execute([$u, $e, $hash, $em, $pk, $code]);
        
        $_SESSION['pending_id'] = $db->lastInsertId();
        $_SESSION['pending_email'] = $e;
        
        jsonOut(['ok' => 1, 'email' => $e]);
    }
    
    // === DOĞRULAMA ===
    if ($act === 'verify') {
        $code = trim($_POST['code'] ?? '');
        $pid = $_SESSION['pending_id'] ?? 0;
        
        if (!$pid) jsonOut(['err' => 'Oturum geçersiz']);
        
        $st = $db->prepare("SELECT * FROM pending_users WHERE id=? AND verify_code=?");
        $st->execute([$pid, $code]);
        $p = $st->fetch();
        
        if (!$p) jsonOut(['err' => 'Kod hatalı']);
        
        // 15 dakika kontrolü
        $created = strtotime($p['created_at']);
        if (time() - $created > 900) {
            $db->prepare("DELETE FROM pending_users WHERE id=?")->execute([$pid]);
            unset($_SESSION['pending_id'], $_SESSION['pending_email']);
            jsonOut(['err' => 'Kodun süresi doldu. Tekrar kayıt olun.']);
        }
        
        // Gerçek kullanıcıya taşı
        $friendCode = genCode(8);
        $st = $db->prepare("INSERT INTO users (username,email,password,friend_code,emoji,pubkey) VALUES (?,?,?,?,?,?)");
        $st->execute([$p['username'], $p['email'], $p['password'], $friendCode, $p['emoji'], $p['pubkey']]);
        $uid = $db->lastInsertId();
        
        // Pending sil
        $db->prepare("DELETE FROM pending_users WHERE id=?")->execute([$pid]);
        
        $_SESSION['uid'] = $uid;
        unset($_SESSION['pending_id'], $_SESSION['pending_email']);
        
        jsonOut(['ok' => 1, 'code' => $friendCode]);
    }
    
    // === TEKRAR GÖNDER ===
    if ($act === 'resend') {
        $pid = $_SESSION['pending_id'] ?? 0;
        if (!$pid) jsonOut(['err' => 'Oturum geçersiz']);
        
        $st = $db->prepare("SELECT * FROM pending_users WHERE id=?");
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p) jsonOut(['err' => 'Kayıt bulunamadı']);
        
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE pending_users SET verify_code=?, created_at=NOW() WHERE id=?")->execute([$code, $pid]);
        
        $mail = "
        <div style='font-family:Arial;max-width:400px;margin:0 auto;padding:30px;'>
            <h2 style='color:#6366f1;'>⚡ DragasChat</h2>
            <p>Yeni doğrulama kodunuz:</p>
            <div style='background:#f3f4f6;padding:20px;text-align:center;border-radius:12px;margin:20px 0;'>
                <span style='font-size:36px;font-weight:bold;letter-spacing:10px;'>$code</span>
            </div>
        </div>";
        
        if (!sendMail($p['email'], 'DragasChat Yeni Kod', $mail)) {
            jsonOut(['err' => 'E-posta gönderilemedi']);
        }
        
        jsonOut(['ok' => 1]);
    }
    
    // === GERİ DÖN (kayıt iptal) ===
    if ($act === 'cancelreg') {
        $pid = $_SESSION['pending_id'] ?? 0;
        if ($pid) {
            $db->prepare("DELETE FROM pending_users WHERE id=?")->execute([$pid]);
        }
        unset($_SESSION['pending_id'], $_SESSION['pending_email']);
        jsonOut(['ok' => 1]);
    }
    
    // === GİRİŞ ===
    if ($act === 'login') {
        $u = clean($_POST['u'] ?? '');
        $p = $_POST['p'] ?? '';
        $pk = $_POST['pk'] ?? '';
        
        $st = $db->prepare("SELECT * FROM users WHERE username=? OR email=?");
        $st->execute([$u, $u]);
        $r = $st->fetch();
        
        if (!$r || !password_verify($p, $r['password'])) {
            jsonOut(['err' => 'Kullanıcı adı veya şifre hatalı']);
        }
        
        if ($pk && !$r['pubkey']) {
            $db->prepare("UPDATE users SET pubkey=? WHERE id=?")->execute([$pk, $r['id']]);
        }
        
        $_SESSION['uid'] = $r['id'];
        jsonOut(['ok' => 1]);
    }
    
    // === ÇIKIŞ ===
    if ($act === 'logout') {
        session_destroy();
        jsonOut(['ok' => 1]);
    }
    
    // ===== GİRİŞ GEREKLİ =====
    if (!$user) jsonOut(['err' => 'Giriş yapın']);
    
    // === ME ===
    if ($act === 'me') {
        jsonOut(['ok' => 1, 'id' => (int)$user['id'], 'u' => $user['username'], 
                 'em' => $user['emoji'], 'code' => $user['friend_code'], 'pk' => $user['pubkey']]);
    }
    
    // === SETKEY ===
    if ($act === 'setkey') {
        $db->prepare("UPDATE users SET pubkey=? WHERE id=?")->execute([$_POST['k'], $user['id']]);
        jsonOut(['ok' => 1]);
    }
    
    // === ARKADAŞLIK İSTEĞİ ===
    if ($act === 'req') {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_POST['code'] ?? ''));
        
        if ($code === $user['friend_code']) jsonOut(['err' => 'Kendinize istek gönderemezsiniz']);
        
        $st = $db->prepare("SELECT id,username,emoji FROM users WHERE friend_code=?");
        $st->execute([$code]);
        $t = $st->fetch();
        if (!$t) jsonOut(['err' => 'Kullanıcı bulunamadı']);
        
        // Zaten arkadaş?
        $st = $db->prepare("SELECT 1 FROM friends WHERE user_id=? AND friend_id=?");
        $st->execute([$user['id'], $t['id']]);
        if ($st->fetch()) jsonOut(['err' => 'Zaten arkadaşsınız']);
        
        // Karşıdan istek var mı?
        $st = $db->prepare("SELECT id FROM friend_requests WHERE from_id=? AND to_id=?");
        $st->execute([$t['id'], $user['id']]);
        $existing = $st->fetch();
        
        if ($existing) {
            // Otomatik kabul
            $db->prepare("DELETE FROM friend_requests WHERE id=?")->execute([$existing['id']]);
            $db->prepare("INSERT INTO friends VALUES (?,?),(?,?)")->execute([$user['id'], $t['id'], $t['id'], $user['id']]);
            jsonOut(['ok' => 1, 'msg' => $t['username'] . ' ile arkadaş oldunuz!', 'added' => 1]);
        }
        
        // Bekleyen istek?
        $st = $db->prepare("SELECT 1 FROM friend_requests WHERE from_id=? AND to_id=?");
        $st->execute([$user['id'], $t['id']]);
        if ($st->fetch()) jsonOut(['err' => 'Zaten istek gönderilmiş']);
        
        $db->prepare("INSERT INTO friend_requests (from_id,to_id) VALUES (?,?)")->execute([$user['id'], $t['id']]);
        jsonOut(['ok' => 1, 'msg' => 'İstek gönderildi']);
    }
    
    // === İSTEKLERİ GETİR ===
    if ($act === 'reqs') {
        $st = $db->prepare("SELECT r.id,u.username,u.emoji FROM friend_requests r JOIN users u ON u.id=r.from_id WHERE r.to_id=?");
        $st->execute([$user['id']]);
        jsonOut(['ok' => 1, 'list' => $st->fetchAll()]);
    }
    
    // === KABUL ===
    if ($act === 'accept') {
        $id = (int)$_POST['id'];
        $st = $db->prepare("SELECT from_id FROM friend_requests WHERE id=? AND to_id=?");
        $st->execute([$id, $user['id']]);
        $r = $st->fetch();
        if (!$r) jsonOut(['err' => 'İstek bulunamadı']);
        
        $db->prepare("DELETE FROM friend_requests WHERE id=?")->execute([$id]);
        $db->prepare("INSERT INTO friends VALUES (?,?),(?,?)")->execute([$user['id'], $r['from_id'], $r['from_id'], $user['id']]);
        jsonOut(['ok' => 1]);
    }
    
    // === REDDET ===
    if ($act === 'reject') {
        $db->prepare("DELETE FROM friend_requests WHERE id=? AND to_id=?")->execute([(int)$_POST['id'], $user['id']]);
        jsonOut(['ok' => 1]);
    }
    
    // === ARKADAŞLAR ===
    if ($act === 'friends') {
        $st = $db->prepare("SELECT u.id,u.username,u.emoji,u.pubkey,TIMESTAMPDIFF(MINUTE,u.last_seen,NOW()) as ago 
                           FROM friends f JOIN users u ON u.id=f.friend_id WHERE f.user_id=?");
        $st->execute([$user['id']]);
        jsonOut(['ok' => 1, 'list' => $st->fetchAll()]);
    }
    
    // === MESAJ GEÇMİŞİ ===
    if ($act === 'hist') {
        $w = (int)$_POST['w'];
        $st = $db->prepare("SELECT id,sender,msg,iv,bytes FROM messages 
                           WHERE (sender=? AND receiver=?) OR (sender=? AND receiver=?) ORDER BY id DESC LIMIT 50");
        $st->execute([$user['id'], $w, $w, $user['id']]);
        jsonOut(['ok' => 1, 'list' => array_reverse($st->fetchAll())]);
    }
    
    // === YENİ MESAJLAR ===
    if ($act === 'poll') {
        $w = (int)$_POST['w'];
        $a = (int)$_POST['a'];
        $st = $db->prepare("SELECT id,sender,msg,iv,bytes FROM messages 
                           WHERE ((sender=? AND receiver=?) OR (sender=? AND receiver=?)) AND id>? ORDER BY id");
        $st->execute([$user['id'], $w, $w, $user['id'], $a]);
        jsonOut(['ok' => 1, 'list' => $st->fetchAll()]);
    }
    
    // === MESAJ GÖNDER ===
    if ($act === 'send') {
        $to = (int)$_POST['to'];
        $m = $_POST['m'];
        $iv = $_POST['iv'];
        
        $st = $db->prepare("SELECT 1 FROM friends WHERE user_id=? AND friend_id=?");
        $st->execute([$user['id'], $to]);
        if (!$st->fetch()) jsonOut(['err' => 'Arkadaş değilsiniz']);
        
        $b = strlen($m);
        $st = $db->prepare("INSERT INTO messages (sender,receiver,msg,iv,bytes) VALUES (?,?,?,?,?)");
        $st->execute([$user['id'], $to, $m, $iv, $b]);
        
        jsonOut(['ok' => 1, 'id' => (int)$db->lastInsertId(), 'b' => $b]);
    }
    
    jsonOut(['err' => 'Geçersiz']);
}

$page = $user ? 'app' : 'auth';
$pendingEmail = $_SESSION['pending_email'] ?? '';
$emojis = ['😀','😎','🤓','😈','👻','🤖','🦊','🐱','🐶','🦁','⚡','🔥','💎','🚀'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DragasChat</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f9fafb;color:#1f2937}
input,button{font:inherit;padding:12px;border:2px solid #e5e7eb;border-radius:8px;width:100%}
input:focus{border-color:#6366f1;outline:none}
button{background:#6366f1;color:#fff;border:none;cursor:pointer;font-weight:600}
button:hover{background:#4f46e5}
.g{background:#10b981}.g:hover{background:#059669}
.r{background:#ef4444}.r:hover{background:#dc2626}
.l{background:#fff;color:#374151;border:2px solid #e5e7eb}.l:hover{background:#f9fafb}
.cnt{max-width:420px;margin:0 auto;padding:20px}
.card{background:#fff;border:2px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:16px}
.c{text-align:center}
.mb{margin-bottom:16px}
.err{background:#fef2f2;color:#dc2626;padding:12px;border-radius:8px;margin-bottom:12px}
.ok{background:#ecfdf5;color:#059669;padding:12px;border-radius:8px;margin-bottom:12px}
.fg{margin-bottom:14px}
.fg label{display:block;margin-bottom:4px;font-weight:600;color:#374151}
.tabs{display:flex;gap:8px;margin-bottom:20px}
.tabs button{flex:1;background:#f3f4f6;color:#6b7280}
.tabs button.on{background:#6366f1;color:#fff}
.emo{display:flex;flex-wrap:wrap;gap:6px}
.emo label{font-size:24px;padding:6px;border-radius:8px;cursor:pointer;background:#f3f4f6}
.emo input{display:none}
.emo input:checked+span{background:#6366f1;border-radius:8px}
#app{display:flex;height:100vh}
#side{width:300px;border-right:2px solid #e5e7eb;display:flex;flex-direction:column;background:#fff}
#main{flex:1;display:flex;flex-direction:column;background:#f9fafb}
.hdr{padding:16px;border-bottom:2px solid #e5e7eb}
.ub{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.ub .em{font-size:36px}
.sec{padding:12px 16px;border-bottom:1px solid #e5e7eb}
.stit{font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;margin-bottom:8px}
.af{display:flex;gap:8px}
.af input{flex:1;text-transform:uppercase;font-family:monospace}
.af button{width:auto;padding:12px 16px}
.fri{display:flex;align-items:center;gap:10px;padding:12px 16px;cursor:pointer;border-bottom:1px solid #f3f4f6}
.fri:hover,.fri.on{background:#f9fafb}
.fri .em{font-size:28px}
.fri .nm{flex:1;font-weight:500}
.fri .st{width:10px;height:10px;border-radius:50%;background:#d1d5db}
.fri .st.on{background:#10b981}
#flist{flex:1;overflow-y:auto}
.ft{padding:12px;font-size:12px;color:#6b7280;text-align:center;border-top:1px solid #e5e7eb}
.req{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid #f3f4f6}
.req .nm{flex:1}
.req .btns{display:flex;gap:4px}
.req .btns button{width:32px;height:32px;padding:0;font-size:14px}
#chdr{display:none;padding:16px;border-bottom:2px solid #e5e7eb;background:#fff;align-items:center;gap:12px}
#chdr.on{display:flex}
#noch{flex:1;display:flex;align-items:center;justify-content:center;color:#9ca3af}
#msgs{flex:1;overflow-y:auto;padding:16px;display:none}
#msgs.on{display:block}
.msg{max-width:70%;padding:10px 14px;border-radius:14px;margin-bottom:8px;word-break:break-word}
.msg.me{margin-left:auto;background:#6366f1;color:#fff}
.msg.th{background:#fff;border:2px solid #e5e7eb}
.msg .bt{font-size:11px;opacity:.6;margin-top:4px}
#mfrm{display:none;padding:16px;border-top:2px solid #e5e7eb;background:#fff;gap:8px}
#mfrm.on{display:flex}
#mfrm input{flex:1}
#mfrm button{width:auto;padding:12px 20px}
.badge{background:#ef4444;color:#fff;font-size:11px;padding:2px 6px;border-radius:8px;margin-left:6px}
@media(max-width:600px){
#side{position:fixed;inset:0;width:100%;z-index:10;transform:translateX(0);transition:.3s}
#side.hide{transform:translateX(-100%)}
.bk{display:flex!important}
}
.bk{display:none;align-items:center;gap:4px}
#ld{position:fixed;inset:0;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center}
.sp{width:36px;height:36px;border:3px solid #e5e7eb;border-top-color:#6366f1;border-radius:50%;animation:sp .7s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<?php if ($page === 'auth'): ?>
<div class="cnt">
    <div class="c mb" style="padding-top:30px">
        <h1 style="color:#6366f1;font-size:28px">⚡ DragasChat</h1>
        <p style="color:#6b7280">Güvenli Şifreli Sohbet</p>
    </div>
    
    <div id="alertBox"></div>
    
    <!-- DOĞRULAMA -->
    <div id="verifyBox" style="display:<?= $pendingEmail ? 'block' : 'none' ?>">
        <div class="card">
            <div class="c mb">
                <div style="font-size:48px">📧</div>
                <h2 style="margin-top:8px">E-posta Doğrulama</h2>
                <p style="color:#6b7280;margin-top:4px">6 haneli kodu girin</p>
            </div>
            
            <div class="c mb">
                <span style="color:#6366f1;font-weight:600;font-size:16px" id="showEmail"><?= clean($pendingEmail) ?></span>
                <p style="color:#9ca3af;font-size:13px;margin-top:6px">
                    Hatalı mı? <a href="#" onclick="cancelReg()" style="color:#6366f1">Geri dön</a>
                </p>
            </div>
            
            <div class="fg">
                <input type="text" id="vCode" maxlength="6" style="font-size:24px;text-align:center;letter-spacing:8px" placeholder="000000">
            </div>
            <button onclick="verify()">Doğrula</button>
            
            <p class="c" style="margin-top:16px;color:#6b7280;font-size:13px">
                Kod gelmedi mi? <a href="#" onclick="resend()" id="resendLink" style="color:#6366f1">Tekrar gönder</a>
            </p>
        </div>
    </div>
    
    <!-- GİRİŞ/KAYIT -->
    <div id="authBox" style="display:<?= $pendingEmail ? 'none' : 'block' ?>">
        <div class="card">
            <div class="tabs">
                <button id="tabL" class="on" onclick="showTab('L')">Giriş Yap</button>
                <button id="tabR" onclick="showTab('R')">Kayıt Ol</button>
            </div>
            
            <!-- GİRİŞ -->
            <div id="formL">
                <div class="fg">
                    <label>Kullanıcı Adı veya E-posta</label>
                    <input type="text" id="lUser" autocomplete="username">
                </div>
                <div class="fg">
                    <label>Şifre</label>
                    <input type="password" id="lPass" autocomplete="current-password">
                </div>
                <button onclick="login()">Giriş Yap</button>
            </div>
            
            <!-- KAYIT -->
            <div id="formR" style="display:none">
                <div class="fg">
                    <label>Kullanıcı Adı</label>
                    <input type="text" id="rUser" autocomplete="username">
                    <small style="color:#6b7280">3-20 karakter</small>
                </div>
                <div class="fg">
                    <label>E-posta</label>
                    <input type="email" id="rEmail" autocomplete="email">
                </div>
                <div class="fg">
                    <label>Şifre</label>
                    <input type="password" id="rPass" autocomplete="new-password">
                    <small style="color:#6b7280">En az 6 karakter</small>
                </div>
                <div class="fg">
                    <label>Profil Emojisi</label>
                    <div class="emo">
                        <?php foreach ($emojis as $i => $em): ?>
                        <label><input type="radio" name="emoji" value="<?= $em ?>" <?= $i===0?'checked':'' ?>><span><?= $em ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button onclick="register()">Kayıt Ol</button>
            </div>
        </div>
        <p class="c" style="color:#6b7280;font-size:13px">🔒 Mesajlar uçtan uca şifrelenir</p>
    </div>
</div>

<script>
const $=id=>document.getElementById(id);

async function api(act, data={}) {
    const fd = new FormData();
    fd.append('act', act);
    for(const k in data) fd.append(k, data[k]);
    const r = await fetch('', {method:'POST', body:fd});
    return r.json();
}

function alert(m, t='err') {
    $('alertBox').innerHTML = `<div class="${t}">${m}</div>`;
}
function clearAlert() { $('alertBox').innerHTML = ''; }

function showTab(t) {
    clearAlert();
    $('tabL').className = t==='L' ? 'on' : '';
    $('tabR').className = t==='R' ? 'on' : '';
    $('formL').style.display = t==='L' ? 'block' : 'none';
    $('formR').style.display = t==='R' ? 'block' : 'none';
}

async function initCrypto() {
    let keys;
    const st = localStorage.getItem('dschat_k');
    if (st) {
        const k = JSON.parse(st);
        keys = {
            pub: await crypto.subtle.importKey('jwk', k.pub, {name:'ECDH',namedCurve:'P-256'}, true, []),
            priv: await crypto.subtle.importKey('jwk', k.priv, {name:'ECDH',namedCurve:'P-256'}, true, ['deriveKey'])
        };
    } else {
        const kp = await crypto.subtle.generateKey({name:'ECDH',namedCurve:'P-256'}, true, ['deriveKey']);
        keys = { pub: kp.publicKey, priv: kp.privateKey };
        localStorage.setItem('dschat_k', JSON.stringify({
            pub: await crypto.subtle.exportKey('jwk', kp.publicKey),
            priv: await crypto.subtle.exportKey('jwk', kp.privateKey)
        }));
    }
    return JSON.stringify(await crypto.subtle.exportKey('jwk', keys.pub));
}

async function login() {
    clearAlert();
    const pk = await initCrypto();
    const r = await api('login', {u: $('lUser').value, p: $('lPass').value, pk: pk});
    if (r.err) return alert(r.err);
    location.reload();
}

async function register() {
    clearAlert();
    const pk = await initCrypto();
    const em = document.querySelector('input[name="emoji"]:checked')?.value || '😀';
    const r = await api('register', {u: $('rUser').value, e: $('rEmail').value, p: $('rPass').value, em: em, pk: pk});
    if (r.err) return alert(r.err);
    $('showEmail').textContent = r.email;
    $('authBox').style.display = 'none';
    $('verifyBox').style.display = 'block';
    alert('Doğrulama kodu gönderildi!', 'ok');
}

async function verify() {
    clearAlert();
    const r = await api('verify', {code: $('vCode').value.trim()});
    if (r.err) return alert(r.err);
    alert('Hesap oluşturuldu! Arkadaş kodunuz: ' + r.code, 'ok');
    setTimeout(() => location.reload(), 2000);
}

async function resend() {
    const link = $('resendLink');
    link.textContent = 'Gönderiliyor...';
    link.style.pointerEvents = 'none';
    const r = await api('resend');
    if (r.err) alert(r.err); else alert('Yeni kod gönderildi!', 'ok');
    setTimeout(() => { link.textContent = 'Tekrar gönder'; link.style.pointerEvents = 'auto'; }, 60000);
}

async function cancelReg() {
    await api('cancelreg');
    $('verifyBox').style.display = 'none';
    $('authBox').style.display = 'block';
    clearAlert();
}
</script>

<?php else: ?>
<div id="ld">
    <div class="sp"></div>
    <p style="color:#6366f1;margin-top:12px;font-weight:600">⚡ DragasChat</p>
</div>

<div id="app" style="display:none">
    <div id="side">
        <div class="hdr">
            <div class="ub">
                <span class="em"><?= $user['emoji'] ?></span>
                <div style="flex:1">
                    <strong><?= clean($user['username']) ?></strong><br>
                    <small style="color:#6b7280">Kod: <span style="color:#6366f1;font-family:monospace"><?= $user['friend_code'] ?></span></small>
                </div>
            </div>
            <button class="l" onclick="logout()">Çıkış</button>
        </div>
        
        <div class="sec">
            <div class="stit">Arkadaş Ekle</div>
            <div class="af">
                <input type="text" id="addCode" placeholder="Arkadaş kodu" maxlength="8">
                <button onclick="sendReq()">Gönder</button>
            </div>
        </div>
        
        <div class="sec" id="reqSec" style="display:none">
            <div class="stit">İstekler <span id="reqBadge" class="badge">0</span></div>
            <div id="reqList"></div>
        </div>
        
        <div class="sec" style="padding-bottom:6px"><div class="stit">Arkadaşlar</div></div>
        <div id="flist"></div>
        
        <div class="ft">📊 <span id="totB">0</span> byte | 🔒 Şifreli</div>
    </div>
    
    <div id="main">
        <div id="chdr">
            <button class="l bk" onclick="$('side').classList.remove('hide')">← Geri</button>
            <span id="cEm" style="font-size:28px">😀</span>
            <div><strong id="cNm">Sohbet</strong><br><small style="color:#10b981">🔒 Şifreli</small></div>
        </div>
        <div id="noch">
            <div class="c">
                <div style="font-size:48px;margin-bottom:12px">💬</div>
                <p>Sohbet başlatmak için arkadaş seçin</p>
            </div>
        </div>
        <div id="msgs"></div>
        <div id="mfrm">
            <input type="text" id="msgIn" placeholder="Mesaj yazın..." maxlength="500" autocomplete="off">
            <button onclick="sendMsg()">Gönder</button>
        </div>
    </div>
</div>

<script>
const $=id=>document.getElementById(id);
const myId=<?= (int)$user['id'] ?>;
let myPk=<?= $user['pubkey'] ? 'JSON.parse(`'.$user['pubkey'].'`)' : 'null' ?>;

async function api(act, data={}) {
    const fd = new FormData();
    fd.append('act', act);
    for(const k in data) fd.append(k, data[k]);
    const r = await fetch('', {method:'POST', body:fd});
    return r.json();
}

// CRYPTO
let keys, derived={};

async function initCrypto() {
    const st = localStorage.getItem('dschat_k');
    if (st) {
        const k = JSON.parse(st);
        keys = {
            pub: await crypto.subtle.importKey('jwk', k.pub, {name:'ECDH',namedCurve:'P-256'}, true, []),
            priv: await crypto.subtle.importKey('jwk', k.priv, {name:'ECDH',namedCurve:'P-256'}, true, ['deriveKey'])
        };
    } else {
        const kp = await crypto.subtle.generateKey({name:'ECDH',namedCurve:'P-256'}, true, ['deriveKey']);
        keys = { pub: kp.publicKey, priv: kp.privateKey };
        localStorage.setItem('dschat_k', JSON.stringify({
            pub: await crypto.subtle.exportKey('jwk', kp.publicKey),
            priv: await crypto.subtle.exportKey('jwk', kp.privateKey)
        }));
    }
    if (!myPk) {
        const pk = await crypto.subtle.exportKey('jwk', keys.pub);
        await api('setkey', {k: JSON.stringify(pk)});
        myPk = pk;
    }
}

async function getShared(pk) {
    const h = JSON.stringify(pk);
    if (derived[h]) return derived[h];
    const their = await crypto.subtle.importKey('jwk', pk, {name:'ECDH',namedCurve:'P-256'}, false, []);
    derived[h] = await crypto.subtle.deriveKey({name:'ECDH',public:their}, keys.priv, {name:'AES-GCM',length:256}, false, ['encrypt','decrypt']);
    return derived[h];
}

async function encrypt(txt, pk) {
    const key = await getShared(pk);
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const enc = await crypto.subtle.encrypt({name:'AES-GCM',iv}, key, new TextEncoder().encode(txt));
    return { m: btoa(String.fromCharCode(...new Uint8Array(enc))), iv: btoa(String.fromCharCode(...iv)) };
}

async function decrypt(m, iv, pk) {
    try {
        const key = await getShared(pk);
        const dec = await crypto.subtle.decrypt(
            {name:'AES-GCM', iv:Uint8Array.from(atob(iv), c=>c.charCodeAt(0))},
            key,
            Uint8Array.from(atob(m), c=>c.charCodeAt(0))
        );
        return new TextDecoder().decode(dec);
    } catch(e) {
        return '[Şifre çözülemedi]';
    }
}

// STATE
let friends=[], cur=null, lastId=0, totB=0;

async function logout() { await api('logout'); location.reload(); }

async function sendReq() {
    const code = $('addCode').value.trim();
    if (!code) return;
    const r = await api('req', {code});
    if (r.err) return window.alert(r.err);
    window.alert(r.msg);
    $('addCode').value = '';
    if (r.added) await loadFriends();
}

async function loadReqs() {
    const r = await api('reqs');
    if (!r.ok) return;
    if (r.list.length > 0) {
        $('reqSec').style.display = 'block';
        $('reqBadge').textContent = r.list.length;
        $('reqList').innerHTML = r.list.map(x => `
            <div class="req">
                <span style="font-size:22px">${x.emoji}</span>
                <span class="nm">${x.username}</span>
                <div class="btns">
                    <button class="g" onclick="acceptReq(${x.id})">✓</button>
                    <button class="r" onclick="rejectReq(${x.id})">✕</button>
                </div>
            </div>
        `).join('');
    } else {
        $('reqSec').style.display = 'none';
    }
}

async function acceptReq(id) { await api('accept', {id}); await loadReqs(); await loadFriends(); }
async function rejectReq(id) { await api('reject', {id}); await loadReqs(); }

async function loadFriends() {
    const r = await api('friends');
    if (!r.ok) return;
    friends = r.list;
    if (!friends.length) {
        $('flist').innerHTML = '<p class="c" style="padding:20px;color:#9ca3af">Arkadaş yok</p>';
        return;
    }
    $('flist').innerHTML = friends.map(f => `
        <div class="fri ${cur?.id==f.id?'on':''}" onclick="selFriend(${f.id})">
            <span class="em">${f.emoji}</span>
            <span class="nm">${f.username}</span>
            <span class="st ${f.ago<5?'on':''}"></span>
        </div>
    `).join('');
}

async function selFriend(id) {
    cur = friends.find(f => f.id == id);
    if (!cur) return;
    lastId = 0;
    $('cEm').textContent = cur.emoji;
    $('cNm').textContent = cur.username;
    $('chdr').classList.add('on');
    $('noch').style.display = 'none';
    $('msgs').classList.add('on');
    $('mfrm').classList.add('on');
    loadFriends();
    if (innerWidth <= 600) $('side').classList.add('hide');
    await loadMsgs();
    $('msgIn').focus();
}

async function loadMsgs() {
    if (!cur) return;
    const r = await api('hist', {w: cur.id});
    if (!r.ok) return;
    $('msgs').innerHTML = '';
    for (const m of r.list) await addMsg(m);
    $('msgs').scrollTop = $('msgs').scrollHeight;
}

async function addMsg(m) {
    const mine = m.sender == myId;
    let txt = '...';
    if (cur.pubkey) {
        const pk = typeof cur.pubkey === 'string' ? JSON.parse(cur.pubkey) : cur.pubkey;
        txt = await decrypt(m.msg, m.iv, pk);
    }
    const d = document.createElement('div');
    d.className = 'msg ' + (mine ? 'me' : 'th');
    d.innerHTML = `${esc(txt)}<div class="bt">${m.bytes}B</div>`;
    $('msgs').appendChild(d);
    if (m.id > lastId) lastId = m.id;
}

function esc(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

async function sendMsg() {
    const txt = $('msgIn').value.trim();
    if (!txt || !cur) return;
    if (!cur.pubkey) return window.alert('Arkadaş henüz anahtar oluşturmamış');
    
    const pk = typeof cur.pubkey === 'string' ? JSON.parse(cur.pubkey) : cur.pubkey;
    const enc = await encrypt(txt, pk);
    const r = await api('send', {to: cur.id, m: enc.m, iv: enc.iv});
    
    if (r.err) return window.alert(r.err);
    
    totB += r.b;
    $('totB').textContent = totB;
    await addMsg({id: r.id, sender: myId, msg: enc.m, iv: enc.iv, bytes: r.b});
    $('msgs').scrollTop = $('msgs').scrollHeight;
    $('msgIn').value = '';
}

// Enter ile gönder
$('msgIn')?.addEventListener('keypress', e => { if (e.key === 'Enter') sendMsg(); });

async function poll() {
    if (!cur) return;
    const r = await api('poll', {w: cur.id, a: lastId});
    if (r.ok && r.list.length) {
        for (const m of r.list) {
            if (m.sender != myId) {
                await addMsg(m);
                $('msgs').scrollTop = $('msgs').scrollHeight;
            }
        }
    }
}

(async () => {
    await initCrypto();
    await loadFriends();
    await loadReqs();
    $('ld').style.display = 'none';
    $('app').style.display = 'flex';
    setInterval(poll, 3000);
    setInterval(loadReqs, 10000);
    setInterval(loadFriends, 30000);
})();
</script>
<?php endif; ?>

</body>
</html>