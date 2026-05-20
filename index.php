<?php
define('DB_TYPE', 'sqlite');
define('DB_HOST', 'localhost');
define('DB_NAME', 'sinu_baas');
define('DB_USER', 'sinu_kasutaja');
define('DB_PASS', 'sinu_parool');
define('SQLITE_FILE', __DIR__ . '/andmed.db');

function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (DB_TYPE === 'sqlite') {
        $pdo = new PDO('sqlite:' . SQLITE_FILE);
    } else {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    initDB($pdo);
    return $pdo;
}

function initDB($pdo) {
    if (DB_TYPE === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS SUVA (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                TEKST TEXT NOT NULL,
                loodud DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS reaktsioonid (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                suva_id INTEGER NOT NULL,
                reaktsioon TEXT NOT NULL,
                pohjus TEXT,
                muudetud DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(suva_id),
                FOREIGN KEY (suva_id) REFERENCES SUVA(id) ON DELETE CASCADE
            );
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS SUVA (
                id INT AUTO_INCREMENT PRIMARY KEY,
                TEKST TEXT NOT NULL,
                loodud DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE IF NOT EXISTS reaktsioonid (
                id INT AUTO_INCREMENT PRIMARY KEY,
                suva_id INT NOT NULL,
                reaktsioon ENUM('like','dislike') NOT NULL,
                pohjus TEXT,
                muudetud DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (suva_id),
                FOREIGN KEY (suva_id) REFERENCES SUVA(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}

$toiming = $_GET['toiming'] ?? '';
if ($toiming !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = getDB();
        $meetod = $_SERVER['REQUEST_METHOD'];

        if ($meetod === 'POST' && $toiming === 'lisa') {
            $tekst = trim($_POST['tekst'] ?? '');
            if ($tekst === '') { echo json_encode(['ok'=>false,'viga'=>'Tekst ei tohi olla tühi.']); exit; }
            $s = $pdo->prepare("INSERT INTO SUVA (TEKST) VALUES (?)");
            $s->execute([$tekst]);
            echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
            exit;
        }

        if ($meetod === 'POST' && $toiming === 'reaktsioon') {
            $suvaId = (int)($_POST['suva_id'] ?? 0);
            $reakts = $_POST['reaktsioon'] ?? '';
            $pohjus = trim($_POST['pohjus'] ?? '');
            if (!in_array($reakts, ['like','dislike'])) { echo json_encode(['ok'=>false,'viga'=>'Vigane reaktsioon.']); exit; }
            if ($reakts === 'dislike' && $pohjus === '') { echo json_encode(['ok'=>false,'viga'=>'Dislaigi põhjus on kohustuslik!']); exit; }

            $s = $pdo->prepare("SELECT id FROM reaktsioonid WHERE suva_id = ?");
            $s->execute([$suvaId]);
            $olemas = $s->fetch();

            if ($olemas) {
                if ($reakts === 'like') {
                    $s = $pdo->prepare("UPDATE reaktsioonid SET reaktsioon='like', muudetud=CURRENT_TIMESTAMP WHERE suva_id=?");
                    $s->execute([$suvaId]);
                } else {
                    $s = $pdo->prepare("UPDATE reaktsioonid SET reaktsioon='dislike', pohjus=?, muudetud=CURRENT_TIMESTAMP WHERE suva_id=?");
                    $s->execute([$pohjus, $suvaId]);
                }
            } else {
                $s = $pdo->prepare("INSERT INTO reaktsioonid (suva_id, reaktsioon, pohjus) VALUES (?,?,?)");
                $s->execute([$suvaId, $reakts, $pohjus ?: null]);
            }
            echo json_encode(['ok'=>true]);
            exit;
        }

        if ($meetod === 'POST' && $toiming === 'kustuta') {
            $suvaId = (int)($_POST['suva_id'] ?? 0);
            $s = $pdo->prepare("DELETE FROM SUVA WHERE id=?");
            $s->execute([$suvaId]);
            echo json_encode(['ok'=>true]);
            exit;
        }

        if ($meetod === 'GET' && $toiming === 'loe') {
            $s = $pdo->query("
                SELECT s.id, s.TEKST, s.loodud, r.reaktsioon, r.pohjus
                FROM SUVA s
                LEFT JOIN reaktsioonid r ON r.suva_id = s.id
                ORDER BY s.loodud DESC
            ");
            echo json_encode(['ok'=>true,'kirjed'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        echo json_encode(['ok'=>false,'viga'=>'Tundmatu toiming.']);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'viga'=>$e->getMessage()]);
    }
    exit;
}
?><!DOCTYPE html>
<html lang="et">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teksti Baas</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Fraunces:ital,wght@0,300;0,600;1,300&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f5f0e8;--paper:#fffdf7;--ink:#1a1208;--muted:#7a6e5a;
  --border:#d4c9b0;--like:#2d6a4f;--like-bg:#d8f3dc;
  --dis:#9b2226;--dis-bg:#fde8e8;--acc:#c8552a;--r:4px;
}
body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--ink);min-height:100vh;padding:48px 24px 80px}
header{max-width:680px;margin:0 auto 48px;border-bottom:2px solid var(--ink);padding-bottom:16px}
header h1{font-family:'Fraunces',serif;font-size:clamp(2rem,5vw,3.2rem);font-weight:600;letter-spacing:-0.02em;line-height:1}
header p{font-size:.75rem;color:var(--muted);margin-top:6px;letter-spacing:.08em;text-transform:uppercase}
.sisend{max-width:680px;margin:0 auto 48px;display:flex;gap:10px}
.sisend input{flex:1;padding:12px 16px;font-family:'DM Mono',monospace;font-size:.9rem;background:var(--paper);border:1.5px solid var(--border);border-radius:var(--r);color:var(--ink);outline:none;transition:border-color .15s}
.sisend input:focus{border-color:var(--ink)}
.sisend input::placeholder{color:var(--muted)}
.nupp{padding:12px 20px;font-family:'DM Mono',monospace;font-size:.82rem;font-weight:500;letter-spacing:.04em;cursor:pointer;border:1.5px solid var(--ink);border-radius:var(--r);background:var(--ink);color:var(--bg);white-space:nowrap;transition:background .12s,color .12s}
.nupp:hover{background:var(--acc);border-color:var(--acc)}
.nupp:disabled{opacity:.4;cursor:not-allowed}
.nimekiri{max-width:680px;margin:0 auto;display:flex;flex-direction:column;gap:14px}
.kirje{background:var(--paper);border:1.5px solid var(--border);border-radius:var(--r);overflow:hidden;animation:ilmu .2s ease}
@keyframes ilmu{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.kirje-sisu{padding:16px 18px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.kirje-tekst{font-family:'Fraunces',serif;font-size:1.05rem;font-weight:300;line-height:1.5;flex:1}
.kirje-meta{font-size:.68rem;color:var(--muted);margin-top:4px;letter-spacing:.05em}
.kirje-toimingud{display:flex;gap:6px;flex-shrink:0;align-items:center;padding-top:2px}
.react-nupp{padding:5px 10px;font-family:'DM Mono',monospace;font-size:.75rem;border-radius:999px;border:1.5px solid var(--border);background:transparent;color:var(--muted);cursor:pointer;transition:all .12s;display:flex;align-items:center;gap:4px}
.react-nupp:hover{border-color:var(--ink);color:var(--ink)}
.react-nupp.aktiivne-like{background:var(--like-bg);border-color:var(--like);color:var(--like);font-weight:500}
.react-nupp.aktiivne-dislike{background:var(--dis-bg);border-color:var(--dis);color:var(--dis);font-weight:500}
.kustuta-nupp{padding:5px 8px;font-size:.72rem;border-radius:var(--r);border:1.5px solid transparent;background:transparent;color:var(--muted);cursor:pointer;transition:all .12s;font-family:'DM Mono',monospace}
.kustuta-nupp:hover{border-color:var(--dis);color:var(--dis);background:var(--dis-bg)}
.pohjus-riba{padding:10px 18px;background:#fff8f0;border-top:1px dashed var(--border);font-size:.75rem;color:var(--muted);font-style:italic}
.pohjus-riba strong{color:var(--dis);font-style:normal}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(26,18,8,.55);z-index:100;align-items:center;justify-content:center;padding:24px}
.modal-overlay.nahtav{display:flex}
.modal{background:var(--paper);border:2px solid var(--ink);border-radius:var(--r);padding:28px;width:100%;max-width:420px;animation:ms .15s ease}
@keyframes ms{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}
.modal h2{font-family:'Fraunces',serif;font-size:1.3rem;font-weight:600;margin-bottom:6px}
.modal p{font-size:.78rem;color:var(--muted);margin-bottom:16px;line-height:1.5}
.modal textarea{width:100%;padding:10px 12px;font-family:'DM Mono',monospace;font-size:.85rem;background:var(--bg);border:1.5px solid var(--border);border-radius:var(--r);color:var(--ink);resize:vertical;min-height:80px;outline:none;transition:border-color .15s}
.modal textarea:focus{border-color:var(--ink)}
.modal-nupud{display:flex;gap:8px;margin-top:14px;justify-content:flex-end}
.nupp-t{padding:9px 16px;font-family:'DM Mono',monospace;font-size:.8rem;cursor:pointer;border:1.5px solid var(--border);border-radius:var(--r);background:transparent;color:var(--muted);transition:all .12s}
.nupp-t:hover{border-color:var(--ink);color:var(--ink)}
.nupp-k{padding:9px 16px;font-family:'DM Mono',monospace;font-size:.8rem;cursor:pointer;border:1.5px solid var(--dis);border-radius:var(--r);background:var(--dis);color:#fff;transition:all .12s}
.nupp-k:hover{background:#7b1a1d;border-color:#7b1a1d}
.teated{max-width:680px;margin:0 auto 20px}
.teade{padding:10px 14px;border-radius:var(--r);font-size:.8rem;margin-bottom:8px;border:1px solid;animation:ilmu .2s ease}
.teade.viga{background:var(--dis-bg);border-color:var(--dis);color:var(--dis)}
.teade.edu{background:var(--like-bg);border-color:var(--like);color:var(--like)}
.tühi{text-align:center;color:var(--muted);font-size:.8rem;padding:40px;font-style:italic;border:1px dashed var(--border);border-radius:var(--r)}
</style>
</head>
<body>
<header>
  <h1>Teksti Baas</h1>
  <p>Sisesta · Laigi · Dislaigi</p>
</header>
<div class="teated" id="teated"></div>
<div class="sisend">
  <input type="text" id="si" placeholder="Kirjuta midagi..." maxlength="1000" autocomplete="off">
  <button class="nupp" id="sb" onclick="lisaKirje()">Saada baasi</button>
</div>
<div class="nimekiri" id="nimekiri"><div class="tühi">Laen…</div></div>
<div class="modal-overlay" id="modal">
  <div class="modal">
    <h2>👎 Miks ei meeldi?</h2>
    <p>Põhjus on kohustuslik — ilma selleta ei saa dislaigida.</p>
    <textarea id="mp" placeholder="Kirjuta põhjus siia..."></textarea>
    <div id="mv" style="color:var(--dis);font-size:.75rem;margin-top:6px;display:none">Põhjus ei tohi olla tühi!</div>
    <div class="modal-nupud">
      <button class="nupp-t" onclick="sulge()">Tühista</button>
      <button class="nupp-k" onclick="kinnita()">Dislaigi</button>
    </div>
  </div>
</div>
<script>
let mId = null;
const $ = id => document.getElementById(id);

function teade(t, tp='edu', ms=3000) {
  const d = document.createElement('div');
  d.className = 'teade ' + tp; d.textContent = t;
  $('teated').prepend(d); setTimeout(() => d.remove(), ms);
}

async function api(toiming, post) {
  const url = 'index.php?toiming=' + toiming;
  if (post) {
    const r = await fetch(url, {method:'POST', body:new URLSearchParams(post), headers:{'Content-Type':'application/x-www-form-urlencoded'}});
    return r.json();
  }
  return (await fetch(url)).json();
}

async function lisaKirje() {
  const tekst = $('si').value.trim();
  if (!tekst) { teade('Tekst ei tohi olla tühi!','viga'); return; }
  $('sb').disabled = true;
  const v = await api('lisa', {tekst});
  $('sb').disabled = false;
  if (v.ok) { $('si').value = ''; teade('Salvestatud!'); lae(); }
  else teade(v.viga, 'viga');
}

function esc(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function lae() {
  const v = await api('loe');
  const n = $('nimekiri');
  if (!v.ok) { teade(v.viga,'viga'); return; }
  if (!v.kirjed.length) { n.innerHTML='<div class="tühi">Ühtegi kirjet pole. Lisa esimene!</div>'; return; }
  n.innerHTML = v.kirjed.map(k => {
    const r = k.reaktsioon;
    const aeg = new Date(k.loodud).toLocaleString('et-EE');
    const pb = (r==='dislike' && k.pohjus)
      ? `<div class="pohjus-riba">Põhjus: <strong>${esc(k.pohjus)}</strong></div>` : '';
    return `<div class="kirje" id="k${k.id}">
      <div class="kirje-sisu">
        <div>
          <div class="kirje-tekst">${esc(k.TEKST)}</div>
          <div class="kirje-meta">#${k.id} · ${aeg}</div>
        </div>
        <div class="kirje-toimingud">
          <button class="react-nupp ${r==='like'?'aktiivne-like':''}" onclick="reakt(${k.id},'like')">👍 ${r==='like'?'Laigisin':'Laigi'}</button>
          <button class="react-nupp ${r==='dislike'?'aktiivne-dislike':''}" onclick="reakt(${k.id},'dislike')">👎 ${r==='dislike'?'Dislaigisin':'Dislaigi'}</button>
          <button class="kustuta-nupp" onclick="kustuta(${k.id})">✕</button>
        </div>
      </div>${pb}</div>`;
  }).join('');
}

async function reakt(id, t) {
  if (t === 'dislike') {
    mId = id; $('mp').value = ''; $('mv').style.display='none';
    $('modal').classList.add('nahtav'); $('mp').focus();
  } else {
    const v = await api('reaktsioon', {suva_id:id, reaktsioon:'like', pohjus:''});
    if (v.ok) lae(); else teade(v.viga,'viga');
  }
}

function sulge() { $('modal').classList.remove('nahtav'); mId = null; }

async function kinnita() {
  const p = $('mp').value.trim();
  if (!p) { $('mv').style.display='block'; return; }
  sulge();
  const v = await api('reaktsioon', {suva_id:mId, reaktsioon:'dislike', pohjus:p});
  if (v.ok) lae(); else teade(v.viga,'viga');
}

async function kustuta(id) {
  if (!confirm('Kustutad selle kirje?')) return;
  const v = await api('kustuta', {suva_id:id});
  if (v.ok) {
    teade('Kirje kustutatud.');
    document.getElementById('k'+id)?.remove();
    if (!$('nimekiri').children.length)
      $('nimekiri').innerHTML='<div class="tühi">Ühtegi kirjet pole. Lisa esimene!</div>';
  } else teade(v.viga,'viga');
}

$('mp').addEventListener('keydown', e => { if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();kinnita()} });
$('modal').addEventListener('click', e => { if(e.target===$('modal'))sulge() });
$('si').addEventListener('keydown', e => { if(e.key==='Enter')lisaKirje() });

lae();
</script>
</body>
</html>
