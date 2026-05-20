<?php
// Lihtne veebirakendus: tekstiväli + salvestus SQLite andmebaasi (tabel SUVA, veerg TEKST)
session_start();

// DB configuration: MySQL (cPanel) - nõutud keskkonnamuutujad
$dbHost = getenv('DB_HOST') ?: ($_SERVER['DB_HOST'] ?? null);
$dbName = getenv('DB_NAME') ?: ($_SERVER['DB_NAME'] ?? null);
$dbUser = getenv('DB_USER') ?: ($_SERVER['DB_USER'] ?? null);
$dbPass = getenv('DB_PASS') ?: ($_SERVER['DB_PASS'] ?? null);

// Kontrolli, et kõik nõutavad keskkonnamuutujad on määratud
if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
    die('Viga: andmebaasi seadistus puudub. Määra .htaccess või php.ini kaudu:<br>' .
         'SetEnv DB_HOST localhost<br>' .
         'SetEnv DB_NAME andmebaasi_nimi<br>' .
         'SetEnv DB_USER kasutaja<br>' .
         'SetEnv DB_PASS parool');
}

try {
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Loo tabelid, kui neid veel pole
    $pdo->exec("CREATE TABLE IF NOT EXISTS SUVA (
        id INT AUTO_INCREMENT PRIMARY KEY,
        TEKST TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS SUVA_LIKES (
        id INT AUTO_INCREMENT PRIMARY KEY,
        suva_id INT NOT NULL,
        session_id VARCHAR(128) NOT NULL,
        kind ENUM('like','dislike') NOT NULL,
        reason TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (suva_id) REFERENCES SUVA(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    die('MySQL viga: ' . htmlspecialchars($e->getMessage()));
}

$sessionId = session_id() ?: bin2hex(random_bytes(16));

$error = '';
// Add text
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tekst']) && !isset($_POST['action'])) {
    $tekst = trim((string)$_POST['tekst']);
    if ($tekst === '') {
        $error = 'Tekst ei tohi olla tühi.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO SUVA (TEKST) VALUES (:t)');
        $stmt->execute([':t' => $tekst]);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// AJAX endpoint: react
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'react') {
    header('Content-Type: application/json; charset=utf-8');
    $suva_id = (int)($_POST['suva_id'] ?? 0);
    $kind = $_POST['kind'] ?? '';
    $reason = trim((string)($_POST['reason'] ?? '')) ?: null;
    if ($suva_id <= 0 || !in_array($kind, ['like', 'dislike'], true)) {
        echo json_encode(['ok' => false, 'message' => 'Vigased parameetrid']);
        exit;
    }
    // dislike requires reason
    if ($kind === 'dislike' && !$reason) {
        echo json_encode(['ok' => false, 'message' => 'Dislaigi puhul peab põhjus olema sisestatud']);
        exit;
    }

    // find existing reaction by this session
    $stmt = $pdo->prepare('SELECT id, kind FROM SUVA_LIKES WHERE suva_id = :s AND session_id = :sess LIMIT 1');
    $stmt->execute([':s' => $suva_id, ':sess' => $sessionId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['kind'] === $kind) {
            // toggle off
            $del = $pdo->prepare('DELETE FROM SUVA_LIKES WHERE id = :id');
            $del->execute([':id' => $existing['id']]);
        } else {
            // switch kind (replace row)
            $upd = $pdo->prepare('UPDATE SUVA_LIKES SET kind = :k, reason = :r, created_at = CURRENT_TIMESTAMP WHERE id = :id');
            $upd->execute([':k' => $kind, ':r' => $reason, ':id' => $existing['id']]);
        }
    } else {
        // insert new
        $ins = $pdo->prepare('INSERT INTO SUVA_LIKES (suva_id, session_id, kind, reason) VALUES (:s, :sess, :k, :r)');
        $ins->execute([':s' => $suva_id, ':sess' => $sessionId, ':k' => $kind, ':r' => $reason]);
    }

    // return counts and current state
    $cnt = $pdo->prepare("SELECT kind, COUNT(*) AS c FROM SUVA_LIKES WHERE suva_id = :s GROUP BY kind");
    $cnt->execute([':s' => $suva_id]);
    $counts = ['like' => 0, 'dislike' => 0];
    while ($row = $cnt->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['kind']] = (int)$row['c'];
    }

    $stmt2 = $pdo->prepare('SELECT kind, reason FROM SUVA_LIKES WHERE suva_id = :s AND session_id = :sess LIMIT 1');
    $stmt2->execute([':s' => $suva_id, ':sess' => $sessionId]);
    $cur = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode(['ok' => true, 'counts' => $counts, 'current' => $cur]);
    exit;
}

// Load items with counts and current reaction
$items = $pdo->query('SELECT id, TEKST, created_at FROM SUVA ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
$ids = array_column($items, 'id');
$counts = [];
if (!empty($ids)) {
    $in = implode(',', array_map('intval', $ids));
    $sql = "SELECT suva_id, kind, COUNT(*) AS c FROM SUVA_LIKES WHERE suva_id IN ($in) GROUP BY suva_id, kind";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $counts[$r['suva_id']][$r['kind']] = (int)$r['c'];
    }
    $stmt3 = $pdo->prepare('SELECT suva_id, kind, reason FROM SUVA_LIKES WHERE session_id = :sess AND suva_id IN (' . $in . ')');
    $stmt3->execute([':sess' => $sessionId]);
    $mine = [];
    while ($r = $stmt3->fetch(PDO::FETCH_ASSOC)) {
        $mine[$r['suva_id']] = $r;
    }
} else {
    $mine = [];
}
?>
<!doctype html>
<html lang="et">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Lihtne SUVA</title>
    <style>
        :root{
            --bg-dark:#1a2f4a;
            --bg-darker:#0f1f33;
            --card:#ffffff;
            --card-item:#f9fafb;
            --muted:#7b8fa3;
            --accent:#0366d6;
            --success:#0a8f44;
            --danger:#b30000;
            --text-dark:#0f172a;
            --glass: rgba(255,255,255,0.6);
        }
        *{box-sizing:border-box}
        body{font-family:Inter,ui-sans-serif,system-ui,Segoe UI,Arial;margin:0;padding:32px;background:linear-gradient(180deg,var(--bg-dark),var(--bg-darker));min-height:100vh;color:var(--text-dark)}
        .container{max-width:920px;margin:0 auto}
        .card{background:var(--card);padding:24px;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);margin-bottom:16px}
        header.site{display:flex;align-items:center;gap:16px;margin-bottom:8px}
        header.site h1{margin:0;font-size:22px;color:var(--text-dark)}
        form{display:flex;gap:10px;margin-top:8px}
        input[type=text]{flex:1;padding:12px;border:2px solid #d1d5db;border-radius:10px;background:#ffffff;box-shadow:0 2px 8px rgba(0,0,0,0.05)}
        input[type=text]:focus{outline:none;border-color:var(--accent);box-shadow:0 2px 12px rgba(3,102,214,0.2)}
        button{padding:11px 16px;border-radius:10px;border:none;background:var(--accent);color:#fff;font-weight:600;cursor:pointer;transition:all 0.2s}
        button:hover{background:#0255c2;box-shadow:0 4px 12px rgba(3,102,214,0.3)}
        .err{color:var(--danger);margin-top:8px;font-weight:600}
        .items-list{display:grid;grid-template-columns:1fr;gap:12px;margin-top:16px}
        .item{padding:16px;border-radius:12px;background:var(--card-item);box-shadow:0 2px 8px rgba(0,0,0,0.08);border-left:5px solid var(--accent)}
        .item:nth-child(odd){background:#ffffff}
        .item:nth-child(even){background:#f3f4f6}
        .meta{color:var(--muted);font-size:13px;margin-top:8px}
        .controls{margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .btn-like,.btn-dislike{background:transparent;padding:8px 10px;border-radius:8px;border:1px solid #e5e7eb;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s}
        .btn-like{color:var(--success)}
        .btn-dislike{color:var(--danger)}
        .badge{display:inline-block;min-width:28px;text-align:center;padding:4px 8px;border-radius:999px;background:#e5e7eb;color:var(--text-dark);font-weight:700;font-size:13px}
        .btn-like[aria-pressed="true"]{background:rgba(10,143,68,0.12);border-color:rgba(10,143,68,0.25)}
        .btn-dislike[aria-pressed="true"]{background:rgba(179,0,0,0.12);border-color:rgba(179,0,0,0.25)}
        /* modal */
        #modal{display:none;position:fixed;inset:0;background:rgba(15,31,51,0.6);align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(2px)}
        #modal .box{background:var(--card);padding:20px;border-radius:14px;max-width:520px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,0.25)}
        #modal h4{margin:0 0 10px 0;font-size:18px;color:var(--text-dark)}
        #modal textarea{width:100%;padding:10px;border:2px solid #d1d5db;border-radius:8px;font-family:inherit;font-size:14px}
        #modal textarea:focus{outline:none;border-color:var(--danger)}
        footer.note{max-width:920px;margin:12px auto;color:var(--muted);font-size:13px}
        @media(min-width:900px){ .items-list{grid-template-columns:1fr} }
    </style>
</head>
<body>
    <div class="card">
        <h2>Lisa tekst</h2>
        <form method="post" action="">
            <input type="text" name="tekst" placeholder="Sisesta tekst siia...">
            <button type="submit">Saada baasi</button>
        </form>
        <?php if ($error): ?>
            <div class="err"><?=htmlspecialchars($error)?></div>
        <?php endif; ?>

        <h3>Salvestatud kirjed</h3>
        <div class="items-list">
            <?php if (empty($items)): ?>
                <div>Ühtegi kirjet pole.</div>
            <?php else: ?>
                <?php foreach($items as $it): $id=$it['id']; $likeCount = $counts[$id]['like'] ?? 0; $dislikeCount = $counts[$id]['dislike'] ?? 0; $my = $mine[$id] ?? null;?>
                    <div class="item" data-id="<?=$it['id']?>">
                        <div><?=nl2br(htmlspecialchars($it['TEKST']))?></div>
                        <div class="meta">ID: <?=$it['id']?> • <?=$it['created_at']?></div>
                        <div class="controls">
                            <button class="btn-like" data-id="<?=$id?>" aria-pressed="<?=($my && $my['kind']==='like')? 'true':'false'?>">👍 <span class="badge count-like"><?=$likeCount?></span></button>
                            <button class="btn-dislike" data-id="<?=$id?>" aria-pressed="<?=($my && $my['kind']==='dislike')? 'true':'false'?>">👎 <span class="badge count-dislike"><?=$dislikeCount?></span></button>
                            <?php if ($my && $my['kind'] === 'dislike' && !empty($my['reason'])): ?>
                                <div class="meta" data-reason>📌 Põhjus: <?=htmlspecialchars($my['reason'])?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal for dislike reason -->
    <div id="modal">
        <div class="box">
            <h4>Dislaigi põhjus</h4>
            <textarea id="reason" rows="4" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px"></textarea>
            <div style="margin-top:8px;text-align:right">
                <button id="cancel" style="margin-right:8px;padding:6px 10px;border-radius:6px">Tühista</button>
                <button id="confirm" style="background:#b30000;color:#fff;padding:6px 10px;border-radius:6px;border:none">Kinnita</button>
            </div>
            <div id="modal-err" style="color:#b00;margin-top:8px;display:none"></div>
        </div>
    </div>

    <script>
        (function(){
            const modal = document.getElementById('modal');
            const reasonInput = document.getElementById('reason');
            const cancel = document.getElementById('cancel');
            const confirm = document.getElementById('confirm');
            let pendingDislikeId = null;

            function showModal(id){ pendingDislikeId = id; reasonInput.value=''; modal.style.display='flex'; document.body.style.overflow='hidden'; document.getElementById('modal-err').style.display='none'; }
            function hideModal(){ pendingDislikeId = null; modal.style.display='none'; document.body.style.overflow='auto'; }
            cancel.addEventListener('click', e=>{ e.preventDefault(); hideModal(); });

            function updateButtons(data, id){
                const root = document.querySelector('.item[data-id="'+id+'"]');
                if(!root) return;
                root.querySelector('.count-like').textContent = data.counts.like;
                root.querySelector('.count-dislike').textContent = data.counts.dislike;
                const my = data.current;
                root.querySelector('.btn-like').setAttribute('aria-pressed', my && my.kind==='like' ? 'true' : 'false');
                root.querySelector('.btn-dislike').setAttribute('aria-pressed', my && my.kind==='dislike' ? 'true' : 'false');
                if(my && my.kind==='dislike' && my.reason){
                    let existing = root.querySelector('.meta[data-reason]');
                    if(!existing){
                        const d = document.createElement('div'); d.className='meta'; d.setAttribute('data-reason','1'); d.textContent = 'Põhjus: '+my.reason; root.appendChild(d);
                    } else { existing.textContent = 'Põhjus: '+my.reason; }
                } else {
                    const existing = root.querySelector('.meta[data-reason]');
                    if(existing) existing.remove();
                }
            }

            async function sendReact(id, kind, reason){
                const form = new FormData();
                form.append('action','react');
                form.append('suva_id', id);
                form.append('kind', kind);
                if(reason) form.append('reason', reason);
                const res = await fetch('', { method:'POST', body: form });
                return res.json();
            }

            document.querySelectorAll('.btn-like').forEach(btn=>{
                btn.addEventListener('click', async e=>{
                    const id = btn.dataset.id;
                    const resp = await sendReact(id, 'like', '');
                    if(resp.ok) updateButtons(resp, id);
                    else alert(resp.message || 'Viga');
                });
            });

            document.querySelectorAll('.btn-dislike').forEach(btn=>{
                btn.addEventListener('click', e=>{
                    const id = btn.dataset.id;
                    showModal(id);
                });
            });

            confirm.addEventListener('click', async e=>{
                const reason = reasonInput.value.trim();
                if(!reason){ document.getElementById('modal-err').textContent='Palun sisesta põhjus.'; document.getElementById('modal-err').style.display='block'; return; }
                const id = pendingDislikeId;
                const resp = await sendReact(id, 'dislike', reason);
                if(resp.ok){ updateButtons(resp, id); hideModal(); }
                else { document.getElementById('modal-err').textContent = resp.message || 'Viga'; document.getElementById('modal-err').style.display='block'; }
            });
        })();
    </script>
</body>
</html>
