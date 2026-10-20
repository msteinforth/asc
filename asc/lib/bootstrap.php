<?php
/**
 * Gemeinsamer Einstieg für alle PHP-Skripte (API und Admin).
 */
declare(strict_types=1);

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/datum.php';
require_once __DIR__ . '/tarife.php';
require_once __DIR__ . '/verfuegbarkeit.php';
require_once __DIR__ . '/buchungen.php';
require_once __DIR__ . '/sicherheit.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/texte.php';
require_once __DIR__ . '/formulare.php';

/** Liefert die Konfiguration (einmal geladen). */
function config(?string $key = null): mixed
{
    static $config = null;
    if ($config === null) {
        $datei = __DIR__ . '/config.php';
        if (!is_file($datei)) {
            // Lokal (php -S) darf die Beispielkonfiguration genutzt werden, auf dem Server nie.
            if (PHP_SAPI === 'cli-server' || PHP_SAPI === 'cli') {
                $datei = __DIR__ . '/config.example.php';
            } else {
                http_response_code(500);
                exit('Konfiguration fehlt: lib/config.php anlegen (Vorlage: lib/config.example.php).');
            }
        }
        $config = require $datei;
        if (!empty($GLOBALS['ASC_CONFIG_OVERRIDE']) && is_array($GLOBALS['ASC_CONFIG_OVERRIDE'])) {
            $config = array_replace_recursive($config, $GLOBALS['ASC_CONFIG_OVERRIDE']);
        }
        date_default_timezone_set($config['zeitzone'] ?? 'Europe/Berlin');
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

/** Sendet eine JSON-Antwort und beendet das Skript. */
function json_antwort(mixed $daten, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** HTML-Escaping. */
function h(mixed $wert): string
{
    return htmlspecialchars((string) $wert, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Formatiert einen Eurobetrag deutsch, z. B. 12,50 €. */
function euro(float|int $betrag): string
{
    $hatCent = abs($betrag - round($betrag)) > 0.001;
    return number_format((float) $betrag, $hatCent ? 2 : 0, ',', '.') . ' €';
}

config();
