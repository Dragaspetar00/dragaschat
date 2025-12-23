<?php
// 🔧 TARAYICI CACHE'İNİ KAPAT
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// 🔁 CACHE TEMİZLEME TOKEN (her yenilemede değişir)
$token = time();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- 🔒 Tarayıcıya cache yapma -->
<meta http-equiv="cache-control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="pragma" content="no-cache">
<meta http-equiv="expires" content="0">

<title>Sıfırlanıyor... | Drawest</title>

<!-- 💅 Basit Stil -->
<link rel="stylesheet" href="style.css?v=<?php echo $token; ?>">

<style>
body {
  background: #0f0f0f;
  color: #fff;
  font-family: "Segoe UI", sans-serif;
  text-align: center;
  margin: 0;
  padding: 0;
}
.container {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}
h1 {
  font-size: 2.4em;
  margin-bottom: 0.4em;
}
.loader {
  width: 50px;
  height: 50px;
  border: 6px solid #444;
  border-top-color: #fff;
  border-radius: 50%;
  margin: 20px auto;
  animation: spin 1s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
</head>

<body>
  <div class="container">
    <div class="loader"></div>
    <h1>Sıfırlanıyor...</h1>
  </div>

  <!-- 🧹 CACHE TEMİZLEYİCİ -->
  <script>
  // Cache temizle
  caches.keys().then(names => { for (let name of names) caches.delete(name); });
  localStorage.clear();
  sessionStorage.clear();
  
  // Eski JS/CSS cache'lerini atlatmak için benzersiz token
  document.querySelectorAll('link[rel="stylesheet"], script[src]').forEach(el => {
    const attr = el.tagName === 'LINK' ? 'href' : 'src';
    el.setAttribute(attr, el.getAttribute(attr) + '?v=' + Date.now());
  });

  // 2 saniye sonra ana sayfaya yönlendir
  setTimeout(() => {
    window.location.href = '/';
  }, 2000);
  </script>
</body>
</html>