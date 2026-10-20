<?php
/**
 * Router für die lokale Entwicklung mit dem eingebauten PHP-Server:
 *
 *   php -S localhost:8000 -t asc dev-router.php
 *
 * Bildet die Regeln aus den .htaccess-Dateien nach (saubere URLs, gesperrte Ordner).
 * Wird auf Strato nicht benötigt und nicht hochgeladen.
 */
$pfad = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = __DIR__ . '/asc';

// Gesperrt wie per .htaccess
if (preg_match('#(^|/)\.|^/lib(/|$)#', $pfad)) {
    http_response_code(403);
    exit('403 – Zugriff verweigert');
}

// /preise -> preise.html
if (preg_match('#^/([a-z0-9-]+)/?$#', $pfad, $m) && is_file("$root/{$m[1]}.html")) {
    header('Content-Type: text/html; charset=utf-8');
    readfile("$root/{$m[1]}.html");
    return true;
}

// /admin -> /admin/index.php
if ($pfad === '/admin') {
    header('Location: /admin/');
    return true;
}

if ($pfad !== '/' && !file_exists($root . $pfad)) {
    http_response_code(404);
    readfile("$root/404.html");
    return true;
}

return false; // Datei normal ausliefern bzw. PHP ausführen
