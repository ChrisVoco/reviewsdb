# Teksti Baas — Paigaldamisjuhend

## Failid
- `index.html` — kasutajaliides (HTML/CSS/JS)
- `index.php` — serveripool (PHP + SQLite/MySQL)

---

## Paigaldamine cPanelisse

### 1. Lae failid üles
cPanel → File Manager → `public_html` (või alamkaust, nt `public_html/baas/`)
Lae üles mõlemad failid: `index.html` ja `index.php`

### 2. Vali andmebaas

#### Variant A — SQLite (lihtsam, soovituslik alguseks)
`index.php` ülaosas on juba `define('DB_TYPE', 'sqlite');` — midagi muutma ei pea.
SQLite loob faili `andmed.db` automaatselt.
Veendu, et kaust on kirjutatav (chmod 755 kaustal).

#### Variant B — MySQL
1. cPanel → MySQL Databases → loo uus baas + kasutaja
2. Muuda `index.php` ülaosas:
   ```php
   define('DB_TYPE', 'mysql');
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'su_prefix_baasnimi');
   define('DB_USER', 'su_prefix_kasutaja');
   define('DB_PASS', 'parool');
   ```
   Tabelid luuakse automaatselt esimesel külastusel.

### 3. Ava brauseris
`https://sinudomain.ee/baas/index.html`

---

## Stsenaariumid — kuidas käitub

| # | Stsenaarium | Käitumine |
|---|-------------|-----------|
| 1 | Laikinud → dislaigib | Dislaik asendab laigi; põhjus küsitakse modalis |
| 2 | Dislaigitud kirje kustutatakse | Reaktsioon kustub automaatselt (FOREIGN KEY CASCADE) |
| 3 | Dislaigi põhjust ei sisestata | Modal jääb lahti, viga kuvatakse — baasi ei salvestata |
| 4 | Like → dislike → like uuesti | Olek muutub like'iks; põhjus jääb DB-sse varjatult (ei kuvata) |
| 5 | Lehe värskendus | Kõik loetakse DB-st uuesti — olek säilib täielikult |

---

## Andmebaasi struktuur

```sql
-- Põhitekstid
SUVA (id, TEKST, loodud)

-- Reaktsioonid — üks kirje SUVA kirje kohta (UNIQUE suva_id)
reaktsioonid (id, suva_id, reaktsioon, pohjus, muudetud)
```

PHPMyAdminis näed mõlemat tabelit pärast esimest kirjet.
