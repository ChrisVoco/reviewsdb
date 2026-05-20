<?php
// Konfiguratsioon — muuda vastavalt oma cPaneli andmetele
define('DB_TYPE', 'sqlite'); // 'sqlite' või 'mysql'

// MySQL seaded (kui DB_TYPE = 'mysql')
define('DB_HOST', 'localhost');
define('DB_NAME', 'sinu_baas');
define('DB_USER', 'sinu_kasutaja');
define('DB_PASS', 'sinu_parool');

// SQLite faili asukoht
define('SQLITE_FILE', __DIR__ . '/andmed.db');

function getDB() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (DB_TYPE === 'sqlite') {
        $pdo = new PDO('sqlite:' . SQLITE_FILE);
    } else {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
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
                reaktsioon TEXT NOT NULL CHECK(reaktsioon IN ('like', 'dislike')),
                pohjus TEXT,
                muudetud DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (suva_id) REFERENCES SUVA(id) ON DELETE CASCADE,
                UNIQUE(suva_id)
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

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    $meetod = $_SERVER['REQUEST_METHOD'];
    $toiming = $_GET['toiming'] ?? '';

    // POST: lisa uus tekst
    if ($meetod === 'POST' && $toiming === 'lisa') {
        $tekst = trim($_POST['tekst'] ?? '');
        if ($tekst === '') {
            echo json_encode(['ok' => false, 'viga' => 'Tekst ei tohi olla tühi.']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO SUVA (TEKST) VALUES (?)");
        $stmt->execute([$tekst]);
        $id = $pdo->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $id, 'tekst' => $tekst]);
        exit;
    }

    // POST: reaktsioon (like/dislike)
    if ($meetod === 'POST' && $toiming === 'reaktsioon') {
        $suvaId    = (int)($_POST['suva_id'] ?? 0);
        $reakts    = $_POST['reaktsioon'] ?? '';
        $pohjus    = trim($_POST['pohjus'] ?? '');

        if (!in_array($reaktsioon = $reakts, ['like', 'dislike'])) {
            echo json_encode(['ok' => false, 'viga' => 'Vigane reaktsioon.']);
            exit;
        }

        // Dislaigi puhul on põhjus kohustuslik
        if ($reaktsioon === 'dislike' && $pohjus === '') {
            echo json_encode(['ok' => false, 'viga' => 'Dislaigi põhjus on kohustuslik!']);
            exit;
        }

        // Kontrolli, kas kirje juba eksisteerib
        $stmt = $pdo->prepare("SELECT * FROM reaktsioonid WHERE suva_id = ?");
        $stmt->execute([$suvaId]);
        $olemasolev = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($olemasolev) {
            // Uuenda olemasolevat reaktsiooni
            // Stsenaar 4: like → dislike → like uuesti — põhjus säilib DB-s
            if ($reaktsioon === 'like') {
                // Like'i puhul ei kustuta põhjust — säilib varjatult
                $stmt = $pdo->prepare("UPDATE reaktsioonid SET reaktsioon = ?, muudetud = CURRENT_TIMESTAMP WHERE suva_id = ?");
                $stmt->execute([$reaktsioon, $suvaId]);
            } else {
                // Dislaigi puhul uuenda ka põhjus
                $stmt = $pdo->prepare("UPDATE reaktsioonid SET reaktsioon = ?, pohjus = ?, muudetud = CURRENT_TIMESTAMP WHERE suva_id = ?");
                $stmt->execute([$reaktsioon, $pohjus, $suvaId]);
            }
        } else {
            // Lisa uus reaktsioon
            $stmt = $pdo->prepare("INSERT INTO reaktsioonid (suva_id, reaktsioon, pohjus) VALUES (?, ?, ?)");
            $stmt->execute([$suvaId, $reaktsioon, $pohjus ?: null]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // POST: kustuta tekst
    if ($meetod === 'POST' && $toiming === 'kustuta') {
        $suvaId = (int)($_POST['suva_id'] ?? 0);
        // Reaktsioonid kustutatakse automaatselt CASCADE tõttu
        $stmt = $pdo->prepare("DELETE FROM SUVA WHERE id = ?");
        $stmt->execute([$suvaId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // GET: kõik kirjed koos reaktsioonidega
    if ($meetod === 'GET' && $toiming === 'loe') {
        $stmt = $pdo->query("
            SELECT s.id, s.TEKST, s.loodud,
                   r.reaktsioon, r.pohjus
            FROM SUVA s
            LEFT JOIN reaktsioonid r ON r.suva_id = s.id
            ORDER BY s.loodud DESC
        ");
        $read = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'kirjed' => $read]);
        exit;
    }

    echo json_encode(['ok' => false, 'viga' => 'Tundmatu toiming.']);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'viga' => $e->getMessage()]);
}
