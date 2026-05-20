<?php
/**
 * ============================================================
 *  SUVA — Ühe-faili kommentaarirakendus
 *  PHP + SQLite + AJAX + Vanilla JS
 *  Kõik ühes failis: backend, frontend, CSS, JS
 * ============================================================
 */

// ─── SEADISTUSED ────────────────────────────────────────────
define('DB_FILE', __DIR__ . '/suva.db');
define('MAX_TEXT_LENGTH', 1000);

// ─── ANDMEBAAS: Ühendus ja tabelite loomine ─────────────────
function getDB(): PDO {
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Foreign key tugi SQLite's
    $pdo->exec('PRAGMA foreign_keys = ON');

    // Loo tabelid kui neid pole
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suva (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            tekst     TEXT    NOT NULL,
            loodud    DATETIME DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS reactions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            comment_id  INTEGER NOT NULL REFERENCES suva(id) ON DELETE CASCADE,
            tyup        TEXT    NOT NULL CHECK(tyup IN ('agree','disagree')),
            pohjus      TEXT,
            loodud      DATETIME DEFAULT (datetime('now','localtime')),
            UNIQUE(comment_id, tyup)
        );
    ");

    return $pdo;
}

// ─── AJAX: JSON päringute käitlemine ────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    try {
        $db = getDB();

        switch ($action) {

            // ── Kommentaari lisamine ──────────────────────────
            case 'add_comment':
                $tekst = trim($_POST['tekst'] ?? '');

                if ($tekst === '') {
                    echo json_encode(['ok' => false, 'msg' => 'Tekst ei tohi olla tühi.']);
                    exit;
                }
                if (strlen($tekst) > MAX_TEXT_LENGTH) {
                    echo json_encode(['ok' => false, 'msg' => 'Tekst on liiga pikk (max ' . MAX_TEXT_LENGTH . ' tähemärki).']);
                    exit;
                }

                $stmt = $db->prepare("INSERT INTO suva (tekst) VALUES (?)");
                $stmt->execute([$tekst]);
                $id = $db->lastInsertId();

                $row = $db->prepare("SELECT * FROM suva WHERE id = ?");
                $row->execute([$id]);
                $comment = $row->fetch();

                echo json_encode(['ok' => true, 'comment' => $comment]);
                exit;

            // ── Kõigi kommentaaride laadimine ────────────────
            case 'get_comments':
                $stmt = $db->query("
                    SELECT s.id, s.tekst, s.loodud,
                           (SELECT COUNT(*) FROM reactions WHERE comment_id = s.id AND tyup = 'agree')    AS agree_count,
                           (SELECT COUNT(*) FROM reactions WHERE comment_id = s.id AND tyup = 'disagree') AS disagree_count,
                           (SELECT pohjus FROM reactions WHERE comment_id = s.id AND tyup = 'disagree' LIMIT 1) AS reaction_pohjus,
                           CASE WHEN (SELECT COUNT(*) FROM reactions WHERE comment_id = s.id AND tyup = 'disagree') > 0 THEN 'disagree'
                                WHEN (SELECT COUNT(*) FROM reactions WHERE comment_id = s.id AND tyup = 'agree') > 0 THEN 'agree'
                                ELSE NULL END AS reaction_type
                    FROM suva s
                    ORDER BY s.loodud DESC
                ");
                echo json_encode(['ok' => true, 'comments' => $stmt->fetchAll()]);
                exit;

            // ── Reaktsiooni lisamine / eemaldamine (toggle) ──
            case 'react':
                $comment_id = (int)($_POST['comment_id'] ?? 0);
                $tyup       = $_POST['tyup'] ?? '';
                $pohjus     = trim($_POST['pohjus'] ?? '');

                if (!in_array($tyup, ['agree', 'disagree'], true) || $comment_id <= 0) {
                    echo json_encode(['ok' => false, 'msg' => 'Vigane päring.']);
                    exit;
                }

                // Kontrolli et kommentaar eksisteerib
                $check = $db->prepare("SELECT id FROM suva WHERE id = ?");
                $check->execute([$comment_id]);
                if (!$check->fetch()) {
                    echo json_encode(['ok' => false, 'msg' => 'Kommentaari ei leitud.']);
                    exit;
                }

                // Võta olemasolev reaktsioon
                $existing = $db->prepare("SELECT * FROM reactions WHERE comment_id = ?");
                $existing->execute([$comment_id]);
                $current = $existing->fetch();

                if ($current) {
                    if ($current['tyup'] === $tyup) {
                        // Sama nupp → eemalda (toggle off)
                        $db->prepare("DELETE FROM reactions WHERE comment_id = ?")->execute([$comment_id]);
                        $new_reaction = null;
                    } else {
                        // Vaheta reaktsioon
                        $db->prepare("UPDATE reactions SET tyup = ?, pohjus = ?, loodud = datetime('now','localtime') WHERE comment_id = ?")
                           ->execute([$tyup, $tyup === 'disagree' ? $pohjus : null, $comment_id]);
                        $new_reaction = $tyup;
                    }
                } else {
                    // Uus reaktsioon
                    $db->prepare("INSERT INTO reactions (comment_id, tyup, pohjus) VALUES (?, ?, ?)")
                       ->execute([$comment_id, $tyup, $tyup === 'disagree' ? $pohjus : null]);
                    $new_reaction = $tyup;
                }

                // Loenda uued arvud
                $counts = $db->prepare("
                    SELECT
                        (SELECT COUNT(*) FROM reactions WHERE comment_id = ? AND tyup = 'agree')    AS agree_count,
                        (SELECT COUNT(*) FROM reactions WHERE comment_id = ? AND tyup = 'disagree') AS disagree_count,
                        (SELECT pohjus FROM reactions WHERE comment_id = ? AND tyup = 'disagree')   AS last_reason
                ");
                $counts->execute([$comment_id, $comment_id, $comment_id]);
                $stats = $counts->fetch();

                echo json_encode([
                    'ok'           => true,
                    'reaction'     => $new_reaction,
                    'agree_count'  => (int)$stats['agree_count'],
                    'disagree_count' => (int)$stats['disagree_count'],
                    'last_reason'  => $stats['last_reason'],
                ]);
                exit;

            // ── Kommentaari kustutamine ───────────────────────
            case 'delete_comment':
                $comment_id = (int)($_POST['comment_id'] ?? 0);
                if ($comment_id <= 0) {
                    echo json_encode(['ok' => false, 'msg' => 'Vigane ID.']);
                    exit;
                }
                // CASCADE kustutab ka reactions automaatselt
                $db->prepare("DELETE FROM suva WHERE id = ?")->execute([$comment_id]);
                echo json_encode(['ok' => true]);
                exit;

            default:
                echo json_encode(['ok' => false, 'msg' => 'Tundmatu tegevus.']);
                exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'Serveri viga: ' . $e->getMessage()]);
        exit;
    }
}

// ─── HTML LEHT ──────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="et">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suva — Räägi oma mõtetest</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <style>
        /* ═══════════════════════════════════════════════════
           CSS MUUTUJAD — Dark/Light teema
        ═══════════════════════════════════════════════════ */
        :root {
            --bg:           #0d0f14;
            --surface:      #161922;
            --surface2:     #1e2330;
            --border:       #2a3040;
            --text:         #e8eaf0;
            --text-muted:   #6b7280;
            --text-soft:    #9ca3af;
            --accent:       #6c63ff;
            --accent-glow:  rgba(108, 99, 255, 0.35);
            --green:        #10b981;
            --green-glow:   rgba(16, 185, 129, 0.3);
            --red:          #ef4444;
            --red-glow:     rgba(239, 68, 68, 0.3);
            --danger:       #f87171;
            --radius:       14px;
            --radius-sm:    8px;
            --transition:   0.22s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow:       0 8px 32px rgba(0,0,0,0.45);
            --shadow-sm:    0 2px 12px rgba(0,0,0,0.3);
            --font-main:    'Georgia', 'Times New Roman', serif;
            --font-ui:      -apple-system, 'Segoe UI', system-ui, sans-serif;
        }

        /* Light teema */
        [data-theme="light"] {
            --bg:           #f0f2f8;
            --surface:      #ffffff;
            --surface2:     #f5f7ff;
            --border:       #dde1ef;
            --text:         #1a1d2e;
            --text-muted:   #9ca3af;
            --text-soft:    #6b7280;
            --accent:       #5b52ef;
            --accent-glow:  rgba(91, 82, 239, 0.2);
            --shadow:       0 8px 32px rgba(100,100,180,0.12);
            --shadow-sm:    0 2px 12px rgba(100,100,180,0.08);
        }

        /* ═══════════════════════════════════════════════════
           RESET & BAAS
        ═══════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { scroll-behavior: smooth; }

        body {
            font-family: var(--font-ui);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem 1rem 4rem;
            transition: background var(--transition), color var(--transition);
            background-image:
                radial-gradient(ellipse 80% 60% at 50% 0%, rgba(108,99,255,0.08) 0%, transparent 60%);
        }

        /* ═══════════════════════════════════════════════════
           HEADER
        ═══════════════════════════════════════════════════ */
        .app-header {
            width: 100%;
            max-width: 680px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2.5rem;
            padding: 0 0.25rem;
        }

        .logo {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
        }

        .logo-title {
            font-family: var(--font-main);
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.04em;
            color: var(--text);
            line-height: 1;
        }

        .logo-title span {
            background: linear-gradient(135deg, var(--accent), #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-sub {
            font-size: 0.75rem;
            color: var(--text-muted);
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-weight: 500;
        }

        /* Teema lüliti */
        .theme-toggle {
            background: var(--surface2);
            border: 1px solid var(--border);
            color: var(--text-soft);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: all var(--transition);
            flex-shrink: 0;
        }

        .theme-toggle:hover {
            border-color: var(--accent);
            color: var(--accent);
            transform: rotate(20deg);
        }

        /* ═══════════════════════════════════════════════════
           SISESTUSKAART
        ═══════════════════════════════════════════════════ */
        .input-card {
            width: 100%;
            max-width: 680px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.75rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            transition: border-color var(--transition), box-shadow var(--transition);
        }

        .input-card:focus-within {
            border-color: var(--accent);
            box-shadow: var(--shadow), 0 0 0 3px var(--accent-glow);
        }

        .input-label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
        }

        textarea {
            width: 100%;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text);
            font-family: var(--font-ui);
            font-size: 0.975rem;
            line-height: 1.6;
            padding: 0.875rem 1rem;
            resize: vertical;
            min-height: 100px;
            transition: border-color var(--transition), box-shadow var(--transition);
            outline: none;
        }

        textarea::placeholder { color: var(--text-muted); }

        textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .input-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 1rem;
            gap: 1rem;
        }

        .char-count {
            font-size: 0.78rem;
            color: var(--text-muted);
            transition: color var(--transition);
        }

        .char-count.warn { color: var(--red); }

        /* Peamine nupp */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, var(--accent), #8b5cf6);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            padding: 0.65rem 1.4rem;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            white-space: nowrap;
            position: relative;
            overflow: hidden;
        }

        .btn-primary::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.12);
            opacity: 0;
            transition: opacity var(--transition);
        }

        .btn-primary:hover::after { opacity: 1; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 20px var(--accent-glow); }
        .btn-primary:active { transform: translateY(0); }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Spinner */
        .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            display: none;
        }

        .btn-primary.loading .spinner { display: block; }
        .btn-primary.loading .btn-text { display: none; }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* ═══════════════════════════════════════════════════
           KOMMENTAARIDE NIMEKIRI
        ═══════════════════════════════════════════════════ */
        .comments-section {
            width: 100%;
            max-width: 680px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .section-title {
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .comment-count-badge {
            background: var(--surface2);
            border: 1px solid var(--border);
            color: var(--text-soft);
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.15rem 0.55rem;
            border-radius: 99px;
            min-width: 22px;
            text-align: center;
        }

        #comments-list {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }

        /* Tühi olek */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-soft);
            margin-bottom: 0.4rem;
        }

        .empty-state p {
            font-size: 0.875rem;
            line-height: 1.5;
        }

        /* Kommentaari kaart */
        .comment-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem 1.4rem;
            transition: border-color var(--transition), box-shadow var(--transition), transform var(--transition);
            animation: fadeSlideIn 0.35s cubic-bezier(0.4, 0, 0.2, 1) both;
        }

        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateY(-12px) scale(0.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .comment-card:hover {
            border-color: rgba(108,99,255,0.3);
            box-shadow: var(--shadow-sm);
            transform: translateY(-1px);
        }

        /* Agree aktiivselt */
        .comment-card.reacted-agree {
            border-color: rgba(16,185,129,0.4);
            box-shadow: 0 0 0 2px rgba(16,185,129,0.12);
        }

        /* Disagree aktiivselt */
        .comment-card.reacted-disagree {
            border-color: rgba(239,68,68,0.4);
            box-shadow: 0 0 0 2px rgba(239,68,68,0.12);
        }

        .comment-text {
            font-size: 0.975rem;
            line-height: 1.65;
            color: var(--text);
            margin-bottom: 1rem;
            word-break: break-word;
        }

        .comment-meta {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 0.9rem;
        }

        .comment-time {
            font-size: 0.73rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .comment-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Reaction nupud */
        .btn-react {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: var(--surface2);
            border: 1px solid var(--border);
            color: var(--text-soft);
            border-radius: 99px;
            padding: 0.38rem 0.85rem;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
        }

        .btn-react:hover { border-color: var(--text-muted); color: var(--text); }

        /* Agree aktiivne */
        .btn-react.active-agree {
            background: rgba(16,185,129,0.12);
            border-color: var(--green);
            color: var(--green);
            box-shadow: 0 0 12px var(--green-glow);
        }

        /* Disagree aktiivne */
        .btn-react.active-disagree {
            background: rgba(239,68,68,0.12);
            border-color: var(--red);
            color: var(--red);
            box-shadow: 0 0 12px var(--red-glow);
        }

        /* Kustutamise nupp */
        .btn-delete {
            display: inline-flex;
            align-items: center;
            background: transparent;
            border: 1px solid transparent;
            color: var(--text-muted);
            border-radius: var(--radius-sm);
            padding: 0.38rem 0.65rem;
            font-size: 0.82rem;
            cursor: pointer;
            transition: all var(--transition);
            margin-left: auto;
        }

        .btn-delete:hover {
            background: rgba(239,68,68,0.1);
            border-color: rgba(239,68,68,0.3);
            color: var(--danger);
        }

        /* Disagree põhjus */
        .disagree-reason {
            margin-top: 0.75rem;
            padding: 0.6rem 0.9rem;
            background: rgba(239,68,68,0.07);
            border-left: 3px solid var(--red);
            border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
            font-size: 0.82rem;
            color: var(--text-soft);
            line-height: 1.5;
            animation: fadeSlideIn 0.25s ease both;
        }

        .disagree-reason strong {
            color: var(--red);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            display: block;
            margin-bottom: 0.2rem;
        }

        /* ═══════════════════════════════════════════════════
           MODAL (Disagree põhjuse küsimine)
        ═══════════════════════════════════════════════════ */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.22s ease;
        }

        .modal-overlay.open {
            opacity: 1;
            pointer-events: all;
        }

        .modal {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 2rem;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 24px 64px rgba(0,0,0,0.5);
            transform: scale(0.92) translateY(20px);
            transition: transform 0.26s cubic-bezier(0.34,1.56,0.64,1), opacity 0.22s ease;
            opacity: 0;
        }

        .modal-overlay.open .modal {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        .modal-icon {
            font-size: 2.2rem;
            margin-bottom: 0.75rem;
            display: block;
        }

        .modal h3 {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
        }

        .modal p {
            font-size: 0.875rem;
            color: var(--text-soft);
            margin-bottom: 1.25rem;
            line-height: 1.5;
        }

        .modal textarea {
            min-height: 80px;
            margin-bottom: 1rem;
        }

        .modal-actions {
            display: flex;
            gap: 0.65rem;
            justify-content: flex-end;
        }

        .btn-secondary {
            background: var(--surface2);
            border: 1px solid var(--border);
            color: var(--text-soft);
            border-radius: var(--radius-sm);
            padding: 0.6rem 1.1rem;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition);
        }

        .btn-secondary:hover { border-color: var(--text-muted); color: var(--text); }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
            color: #fff;
            border-radius: var(--radius-sm);
            padding: 0.6rem 1.1rem;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
        }

        .btn-danger:hover { opacity: 0.88; transform: translateY(-1px); }

        /* ═══════════════════════════════════════════════════
           TOAST TEATED
        ═══════════════════════════════════════════════════ */
        .toast-container {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            z-index: 2000;
        }

        .toast {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 0.75rem 1.1rem;
            font-size: 0.85rem;
            color: var(--text);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 0.6rem;
            max-width: 300px;
            animation: toastIn 0.3s cubic-bezier(0.34,1.56,0.64,1) both;
        }

        .toast.error { border-color: var(--red); }
        .toast.success { border-color: var(--green); }

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(20px) scale(0.95); }
            to   { opacity: 1; transform: translateX(0) scale(1); }
        }

        @keyframes toastOut {
            to { opacity: 0; transform: translateX(20px) scale(0.95); }
        }

        /* ═══════════════════════════════════════════════════
           LAADIMISE OVERLAY
        ═══════════════════════════════════════════════════ */
        .loading-overlay {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            color: var(--text-muted);
            font-size: 0.875rem;
            gap: 0.75rem;
        }

        .loading-dot {
            width: 8px;
            height: 8px;
            background: var(--accent);
            border-radius: 50%;
            animation: pulse 1.2s ease-in-out infinite;
        }

        .loading-dot:nth-child(2) { animation-delay: 0.2s; }
        .loading-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes pulse {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
            40%            { transform: scale(1);   opacity: 1;   }
        }

        /* ═══════════════════════════════════════════════════
           RESPONSIIVSUS
        ═══════════════════════════════════════════════════ */
        @media (max-width: 520px) {
            body { padding: 1.25rem 0.75rem 3rem; }
            .app-header { margin-bottom: 1.75rem; }
            .logo-title { font-size: 1.6rem; }
            .input-card { padding: 1.25rem; }
            .comment-card { padding: 1rem 1.1rem; }
            .input-footer { flex-direction: column; align-items: stretch; }
            .btn-primary { justify-content: center; }
        }
    </style>
</head>
<body>

<!-- ─── HEADER ─────────────────────────────────────────────── -->
<header class="app-header">
    <div class="logo">
        <h1 class="logo-title">Su<span>va</span></h1>
        <span class="logo-sub">Mõtteid jagades</span>
    </div>
    <button class="theme-toggle" id="themeToggle" title="Vaheta teemat">🌙</button>
</header>

<!-- ─── SISESTUSKAART ──────────────────────────────────────── -->
<div class="input-card">
    <label class="input-label" for="commentInput">✍️ Sinu mõte</label>
    <textarea
        id="commentInput"
        placeholder="Kirjuta siia midagi huvitavat…"
        maxlength="1000"
    ></textarea>
    <div class="input-footer">
        <span class="char-count" id="charCount">0 / 1000</span>
        <button class="btn-primary" id="submitBtn">
            <div class="spinner"></div>
            <span class="btn-text">💾 Saada baasi</span>
        </button>
    </div>
</div>

<!-- ─── KOMMENTAARIDE SEKTSIOON ────────────────────────────── -->
<section class="comments-section">
    <div class="section-header">
        <span class="section-title">Kommentaarid</span>
        <span class="comment-count-badge" id="commentCount">0</span>
    </div>
    <div id="comments-list">
        <div class="loading-overlay">
            <div class="loading-dot"></div>
            <div class="loading-dot"></div>
            <div class="loading-dot"></div>
        </div>
    </div>
</section>

<!-- ─── DISAGREE MODAL ─────────────────────────────────────── -->
<div class="modal-overlay" id="disagreeModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal">
        <span class="modal-icon">👎</span>
        <h3 id="modalTitle">Miks sa ei nõustu?</h3>
        <p>Palun selgita oma põhjust. Ilma põhjuseta Disagree ei salvestata.</p>
        <textarea id="disagreeReason" placeholder="Kirjuta oma põhjus siia…" maxlength="300"></textarea>
        <div class="modal-actions">
            <button class="btn-secondary" id="modalCancel">Loobu</button>
            <button class="btn-danger" id="modalConfirm">👎 Disagree</button>
        </div>
    </div>
</div>

<!-- ─── TOAST KONTEINER ────────────────────────────────────── -->
<div class="toast-container" id="toastContainer"></div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT — AJAX, Reaktsioonid, UI
═══════════════════════════════════════════════════════════ -->
<script>
"use strict";

// ═══════════════════════════════════════════════════════════
// KONSTANIDID JA OLEK
// ═══════════════════════════════════════════════════════════
const CONFIG = {
    MAX_COMMENT_LENGTH: 1000,
    MAX_REASON_LENGTH:  300,
    TOAST_DURATION:     3200,
    ANIMATION_DURATION: 300,
    REACTION_TYPES: {
        AGREE:    'agree',
        DISAGREE: 'disagree'
    }
};

const STATE = {
    pendingDisagree: null,  // { commentId }
    theme: localStorage.getItem('theme') || 'dark'
};

// ═══════════════════════════════════════════════════════════
// TEEMA HALDUS
// ═══════════════════════════════════════════════════════════
function applyTheme(theme) {
    STATE.theme = theme;
    document.documentElement.setAttribute('data-theme', theme === 'light' ? 'light' : '');
    document.getElementById('themeToggle').textContent = theme === 'light' ? '☀️' : '🌙';
    localStorage.setItem('theme', theme);
}

applyTheme(STATE.theme);

document.getElementById('themeToggle').addEventListener('click', () => {
    applyTheme(STATE.theme === 'dark' ? 'light' : 'dark');
});

// ═══════════════════════════════════════════════════════════
// KOMMENTAARI SISESTUS
// ═══════════════════════════════════════════════════════════
const commentInput = document.getElementById('commentInput');
const charCount    = document.getElementById('charCount');

commentInput.addEventListener('input', () => {
    const len = commentInput.value.length;
    charCount.textContent = `${len} / ${CONFIG.MAX_COMMENT_LENGTH}`;
    charCount.classList.toggle('warn', len > CONFIG.MAX_COMMENT_LENGTH * 0.85);
});

// Enter saadab (Shift+Enter = uus rida)
commentInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submitComment();
    }
});

document.getElementById('submitBtn').addEventListener('click', submitComment);

// ═══════════════════════════════════════════════════════════
// KOMMENTAARIDE HALDUS
// ═══════════════════════════════════════════════════════════

/** Saada uus kommentaar serverisse */
async function submitComment() {
    const tekst = commentInput.value.trim();
    if (!tekst) {
        showToast('Tühi tekst ei salvestu!', 'error');
        return;
    }
    if (tekst.length > CONFIG.MAX_COMMENT_LENGTH) {
        showToast('Tekst on liiga pikk!', 'error');
        return;
    }

    const btn = document.getElementById('submitBtn');
    btn.classList.add('loading');
    btn.disabled = true;

    try {
        const data = await ajax('add_comment', { tekst });
        if (!data.ok) {
            showToast(data.msg, 'error');
            return;
        }

        commentInput.value = '';
        charCount.textContent = `0 / ${CONFIG.MAX_COMMENT_LENGTH}`;
        charCount.classList.remove('warn');
        prependComment(data.comment);
        updateCount(1);
        showToast('Kommentaar salvestatud! ✓', 'success');
    } catch(e) {
        showToast('Viga salvestamisel. Proovi uuesti.', 'error');
    } finally {
        btn.classList.remove('loading');
        btn.disabled = false;
    }
}

/** Laadi kõik kommentaarid lehel avamisel */

async function loadComments() {
    const list = document.getElementById('comments-list');
    try {
        const data = await ajax('get_comments', {}, 'GET');
        list.innerHTML = '';

        if (!data.ok || !data.comments.length) {
            showEmpty();
            return;
        }

        data.comments.forEach(c => appendComment(c));
        updateCount(data.comments.length);
    } catch(e) {
        list.innerHTML = '';
        showEmpty();
    }
}

/** Lisama uus kommentaar listi algusse (animatsiooniga) */
function prependComment(c) {
    const list = document.getElementById('comments-list');
    const empty = list.querySelector('.empty-state');
    if (empty) list.innerHTML = '';

    const card = buildCard(c);
    list.prepend(card);
}

/** Lisa kommentaar listi lõppu (laadimisel) */
function appendComment(c) {
    const list = document.getElementById('comments-list');
    list.appendChild(buildCard(c));
}

/** Ehita kommentaari kaardi DOM element */
function buildCard(c) {
    const card = document.createElement('div');
    card.className = 'comment-card';
    card.dataset.id = c.id;

    const reaction = c.reaction_type || null;
    if (reaction === CONFIG.REACTION_TYPES.AGREE)    card.classList.add('reacted-agree');
    if (reaction === CONFIG.REACTION_TYPES.DISAGREE) card.classList.add('reacted-disagree');

    const agreeCount    = parseInt(c.agree_count    || 0);
    const disagreeCount = parseInt(c.disagree_count || 0);

    card.innerHTML = `
        <p class="comment-text">${escHtml(c.tekst)}</p>
        <div class="comment-meta">
            <span class="comment-time">🕐 ${formatTime(c.loodud)}</span>
        </div>
        <div class="comment-actions">
            <button class="btn-react ${reaction === CONFIG.REACTION_TYPES.AGREE ? 'active-agree' : ''}"
                    data-action="${CONFIG.REACTION_TYPES.AGREE}" data-id="${c.id}">
                👍 <span class="agree-count">${agreeCount}</span>
            </button>
            <button class="btn-react ${reaction === CONFIG.REACTION_TYPES.DISAGREE ? 'active-disagree' : ''}"
                    data-action="${CONFIG.REACTION_TYPES.DISAGREE}" data-id="${c.id}">
                👎 <span class="disagree-count">${disagreeCount}</span>
            </button>
            <button class="btn-delete" data-id="${c.id}" title="Kustuta kommentaar">🗑</button>
        </div>
        ${c.reaction_pohjus ? `
            <div class="disagree-reason">
                <strong>Disagree põhjus</strong>
                ${escHtml(c.reaction_pohjus)}
            </div>` : ''}
    `;

    // Reaktsiooninuppude kuularid
    card.querySelectorAll('.btn-react').forEach(btn => {
        btn.addEventListener('click', () => handleReact(btn.dataset.id, btn.dataset.action));
    });

    // Kustutamise nupp
    card.querySelector('.btn-delete').addEventListener('click', () => handleDelete(c.id));

    return card;
}

// ═══════════════════════════════════════════════════════════
// REAKTSIOONID
// ═══════════════════════════════════════════════════════════

/** Käitle reaktsiooni nupp – disagree'l küsi põhjust */
async function handleReact(commentId, tyup) {
    if (tyup === CONFIG.REACTION_TYPES.DISAGREE) {
        const card = document.querySelector(`.comment-card[data-id="${commentId}"]`);
        const alreadyDisagree = card && card.classList.contains('reacted-disagree');

        if (alreadyDisagree) {
            // Toggle off – pole põhjust vaja
            await sendReaction(commentId, tyup, '');
        } else {
            // Ava modal põhjuse küsimiseks
            STATE.pendingDisagree = { commentId };
            openModal();
        }
        return;
    }

    // Agree – saada kohe
    await sendReaction(commentId, tyup, '');
}

/** Saada reaktsioon serverisse */

async function sendReaction(commentId, tyup, pohjus) {
    try {
        const data = await ajax('react', { comment_id: commentId, tyup, pohjus });
        if (!data.ok) {
            showToast(data.msg || 'Viga reaktsioonis.', 'error');
            return;
        }
        updateCardReaction(commentId, data);
    } catch(e) {
        showToast('Ühenduse viga. Proovi uuesti.', 'error');
    }
}

/** Uuenda kaardi reaktsioonide visuaal ja loendurid */

function updateCardReaction(commentId, data) {
    const card = document.querySelector(`.comment-card[data-id="${commentId}"]`);
    if (!card) return;

    // Nulli CSS klassid
    card.classList.remove('reacted-agree', 'reacted-disagree');
    card.querySelectorAll('.btn-react').forEach(b => {
        b.classList.remove('active-agree', 'active-disagree');
    });

    // Lisa uus olek
    if (data.reaction === CONFIG.REACTION_TYPES.AGREE) {
        card.classList.add('reacted-agree');
        card.querySelector(`[data-action="${CONFIG.REACTION_TYPES.AGREE}"]`).classList.add('active-agree');
    } else if (data.reaction === CONFIG.REACTION_TYPES.DISAGREE) {
        card.classList.add('reacted-disagree');
        card.querySelector(`[data-action="${CONFIG.REACTION_TYPES.DISAGREE}"]`).classList.add('active-disagree');
    }

    // Uuenda loendurid
    card.querySelector('.agree-count').textContent    = data.agree_count;
    card.querySelector('.disagree-count').textContent = data.disagree_count;

    // Uuenda põhjus tekst
    let reasonEl = card.querySelector('.disagree-reason');
    if (data.last_reason && data.reaction === CONFIG.REACTION_TYPES.DISAGREE) {
        if (!reasonEl) {
            reasonEl = document.createElement('div');
            reasonEl.className = 'disagree-reason';
            card.appendChild(reasonEl);
        }
        reasonEl.innerHTML = `<strong>Disagree põhjus</strong>${escHtml(data.last_reason)}`;
    } else if (reasonEl) {
        reasonEl.remove();
    }
}

/** Kustuta kommentaar (pärast kinnitust) */
async function handleDelete(commentId) {
    if (!confirm('Kas kustutada see kommentaar? Seda ei saa tagasi võtta.')) {
        return;
    }

    try {
        const data = await ajax('delete_comment', { comment_id: commentId });
        if (!data.ok) {
            showToast(data.msg || 'Kustutamine ebaõnnestus.', 'error');
            return;
        }

        const card = document.querySelector(`.comment-card[data-id="${commentId}"]`);
        if (card) {
            card.style.transition = `opacity ${CONFIG.ANIMATION_DURATION}ms ease, transform ${CONFIG.ANIMATION_DURATION}ms ease`;
            card.style.opacity = '0';
            card.style.transform = 'scale(0.95)';
            setTimeout(() => {
                card.remove();
                updateCount(-1);
                const list = document.getElementById('comments-list');
                if (!list.children.length) showEmpty();
            }, CONFIG.ANIMATION_DURATION);
        }

        showToast('Kommentaar kustutatud.', 'success');
    } catch(e) {
        showToast('Kustutamine ebaõnnestus. Proovi uuesti.', 'error');
    }
}

// ═══════════════════════════════════════════════════════════
// MODAL (Disagree põhjuse küsimine)
// ═══════════════════════════════════════════════════════════

/** Ava disagree modal */
function openModal() {
    const modal = document.getElementById('disagreeModal');
    document.getElementById('disagreeReason').value = '';
    modal.classList.add('open');
    setTimeout(() => document.getElementById('disagreeReason').focus(), 50);
}

/** Sulge disagree modal */
function closeModal() {
    document.getElementById('disagreeModal').classList.remove('open');
    STATE.pendingDisagree = null;
}

document.getElementById('modalCancel').addEventListener('click', closeModal);

document.getElementById('modalConfirm').addEventListener('click', async () => {
    const reason = document.getElementById('disagreeReason').value.trim();
    if (!reason) {
        showToast('Põhjus on kohustuslik Disagree jaoks!', 'error');
        document.getElementById('disagreeReason').focus();
        return;
    }
    if (!STATE.pendingDisagree) {
        closeModal();
        return;
    }

    closeModal();
    await sendReaction(STATE.pendingDisagree.commentId, CONFIG.REACTION_TYPES.DISAGREE, reason);
});

// Sulge modal overlay klikiga
document.getElementById('disagreeModal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeModal();
});

// Sulge modal Escape klahviga
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
});

// ═══════════════════════════════════════════════════════════
// UTILIIDID
// ═══════════════════════════════════════════════════════════

/**
 * Universaalne AJAX funktsioon
 * @param {string} action - tegevuse nimi (add_comment, get_comments, react, delete_comment)
 * @param {object} params - POST parameetrid
 * @param {string} method - HTTP meetod (GET või POST)
 * @returns {Promise<object>} Serveri vastus JSON-na
 */
async function ajax(action, params = {}, method = 'POST') {
    const url = window.location.href.split('?')[0];

    if (method === 'GET') {
        const res = await fetch(`${url}?action=${action}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        return res.json();
    }

    const body = new FormData();
    body.append('action', action);
    Object.entries(params).forEach(([k, v]) => body.append(k, v));

    const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body
    });
    return res.json();
}

/** XSS kaitse — HTML erimärkide asendamine */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#039;');
}

/** Ajavormindus — loetav formaat */
function formatTime(datetime) {
    if (!datetime) return '';
    try {
        const d = new Date(datetime.replace(' ', 'T'));
        const now = new Date();
        const diff = Math.floor((now - d) / 1000);

        if (diff < 60)     return 'just nüüd';
        if (diff < 3600)   return `${Math.floor(diff/60)} min tagasi`;
        if (diff < 86400)  return `${Math.floor(diff/3600)} h tagasi`;

        return d.toLocaleDateString('et-EE', {
            day:   '2-digit',
            month: '2-digit',
            year:  'numeric',
            hour:  '2-digit',
            minute:'2-digit'
        });
    } catch(e) {
        return datetime;
    }
}

/** Kommentaaride arvu uuendamine */
function updateCount(delta) {
    const badge = document.getElementById('commentCount');
    const current = parseInt(badge.textContent) || 0;
    badge.textContent = Math.max(0, current + delta);
}

/** Tühja oleku näitamine */
function showEmpty() {
    document.getElementById('commentCount').textContent = '0';
    document.getElementById('comments-list').innerHTML = `
        <div class="empty-state">
            <div class="empty-state-icon">💭</div>
            <h3>Kommentaare pole veel</h3>
            <p>Ole esimene, kes oma mõtte jagab!</p>
        </div>
    `;
}

/**
 * Näita teadet ekraanil (toast)
 * @param {string} msg - Teate tekst
 * @param {string} type - Tüüp: 'success', 'error', 'info'
 */
function showToast(msg, type = 'info') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    const icon = type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ';
    toast.innerHTML = `<span>${icon}</span><span>${escHtml(msg)}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = `toastOut ${CONFIG.ANIMATION_DURATION}ms ease forwards`;
        setTimeout(() => toast.remove(), CONFIG.ANIMATION_DURATION);
    }, CONFIG.TOAST_DURATION);
}

// ═══════════════════════════════════════════════════════════
// KÄIVITUS
// ═══════════════════════════════════════════════════════════
loadComments();
</script>

</body>
</html>
