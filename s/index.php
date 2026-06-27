<?php
declare(strict_types=1);

/**
 * Short-Link-Resolver für QR-Codes auf Warehouse-Entitäten.
 *
 *   /s/{prefix}_{id}  →  302  /mbc/warehouse/{prefix}/{id}
 *
 * Beispiel: /s/locations_27 → /mbc/warehouse/locations/27
 *
 * Die Weiterleitung ist rein strukturell — keine Datenbank nötig. Der Prefix
 * entspricht dem Warehouse-Route-Segment (locations | items). Das Ziel ist ein
 * relativer, same-origin Pfad, daher kein Open-Redirect-Risiko. Unbekannte
 * Prefixe oder ungültige Formate liefern 404.
 */

/** Basis-Pfad der Angular-App (baseHref `/mbc/`, siehe app.htaccess.example). */
const APP_WAREHOUSE_BASE = '/mbc/warehouse';

/** Erlaubte Route-Segmente — verhindert Weiterleitung auf beliebige Pfade. */
const ALLOWED_PREFIXES = ['locations', 'items'];

$code = trim((string) ($_GET['r'] ?? ''), '/');

if (
    preg_match('/^([a-z]+)_(\d+)$/', $code, $matches) === 1
    && in_array($matches[1], ALLOWED_PREFIXES, true)
) {
    $target = APP_WAREHOUSE_BASE . '/' . $matches[1] . '/' . (int) $matches[2];
    header('Location: ' . $target, true, 302);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Short link not found';
