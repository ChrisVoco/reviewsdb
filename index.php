<?php
// Lihtne veebirakendus: tekstiväli + salvestus SQLite andmebaasi (tabel SUVA, veerg TEKST)
session_start();

// DB configuration: if DB_HOST/DB_NAME/DB_USER are set (cPanel/MySQL), use MySQL, otherwise fallback to SQLite
$dbHost = getenv('DB_HOST') ?: ($_SERVER['DB_HOST'] ?? null);
$dbName = getenv('DB_NAME') ?: ($_SERVER['DB_NAME'] ?? null);
$dbUser = getenv('DB_USER') ?: ($_SERVER['DB_USER'] ?? null);
$dbPass = getenv('DB_PASS') ?: ($_SERVER['DB_PASS'] ?? null);
$isMySQL = !empty($dbHost) && !empty($dbName) && !empty($dbUser);

try {
    if ($isMySQL) {
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // create tables if missing (safe to run each request)
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
    } else {
        $dbFile = __DIR__ . '/data.sqlite';
        $isNew = !file_exists($dbFile);
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($isNew) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS SUVA (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                TEKST TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS SUVA_LIKES (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                suva_id INTEGER NOT NULL,
                session_id TEXT NOT NULL,
                kind TEXT CHECK(kind IN ('like','dislike')) NOT NULL,
                reason TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(suva_id) REFERENCES SUVA(id) ON DELETE CASCADE
            )");
        }
    }
} catch (Exception $e) {
    die('DB error: ' . htmlspecialchars($e->getMessage()));
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
        body{font-family:system-ui,Segoe UI,Arial;margin:24px;background:#f7f7f8}
        .card{background:#fff;padding:16px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);max-width:800px;margin:0 auto}
        form{display:flex;gap:8px}
        input[type=text]{flex:1;padding:8px;border:1px solid #ddd;border-radius:6px}
        button{padding:8px 12px;border-radius:6px;border:none;background:#0366d6;color:#fff}
        .err{color:#b00;margin-top:8px}
        .item{padding:12px 0;border-bottom:1px solid #eee}
        .meta{color:#666;font-size:12px}
        .btn-like,.btn-dislike{background:#eef;padding:8px 10px;border-radius:8px;border:1px solid #ccd;cursor:pointer;font-weight:600}
        .btn-like{color:#064;}
        .btn-dislike{color:#500}
        .btn-like .count-like,.btn-dislike .count-dislike{margin-left:6px;font-weight:700}
        .btn-like[aria-pressed="true"]{background:#dff7e6;border-color:#7ee0a8;color:#075}
        .btn-dislike[aria-pressed="true"]{background:#ffecec;border-color:#ff9a9a;color:#b30000}
        /* modal */
        #modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);align-items:center;justify-content:center}
        #modal .box{background:#fff;padding:16px;border-radius:8px;max-width:420px;width:90%}
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
        <div id="items">
            <?php if (empty($items)): ?>
                <div>Ühtegi kirjet pole.</div>
            <?php else: ?>
                <?php foreach($items as $it): $id=$it['id']; $likeCount = $counts[$id]['like'] ?? 0; $dislikeCount = $counts[$id]['dislike'] ?? 0; $my = $mine[$id] ?? null;?>
                    <div class="item" data-id="<?=$it['id']?>">
                        <div><?=nl2br(htmlspecialchars($it['TEKST']))?></div>
                        <div class="meta">ID: <?=$it['id']?> • <?=$it['created_at']?></div>
                        <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
                            <button class="btn-like" data-id="<?=$id?>" aria-pressed="<?=($my && $my['kind']==='like')? 'true':'false'?>">👍 <span class="count-like"><?=$likeCount?></span></button>
                            <button class="btn-dislike" data-id="<?=$id?>" aria-pressed="<?=($my && $my['kind']==='dislike')? 'true':'false'?>">👎 <span class="count-dislike"><?=$dislikeCount?></span></button>
                            <?php if ($my && $my['kind'] === 'dislike' && !empty($my['reason'])): ?>
                                <div class="meta" data-reason> Põhjus: <?=htmlspecialchars($my['reason'])?></div>
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
