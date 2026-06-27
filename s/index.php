<?php
declare(strict_types=1);

/**
 * Short-Link-Resolver für QR-Codes auf Warehouse-Entitäten.
 *
 *   /s/locations_{id}  →  302  /mbc/warehouse/items?location_id={id}
 *   /s/items_{id}      →  302  /mbc/warehouse/items/{id}
 *
 * Beispiel: /s/locations_27 → /mbc/warehouse/items?location_id=27
 *           (Items-Liste, gefiltert auf genau diesen Lagerplatz — „Regal
 *            scannen, Inhalt sehen“.)
 *
 * Die Weiterleitung ist rein strukturell — keine Datenbank nötig. Das Ziel ist
 * ein relativer, same-origin Pfad, daher kein Open-Redirect-Risiko. Unbekannte
 * Prefixe oder ungültige Formate liefern 404.
 */

/** Basis-Pfad der Angular-App (baseHref `/mbc/`, siehe app.htaccess.example). */
const APP_WAREHOUSE_BASE = '/mbc/warehouse';

/** Erlaubte Prefixe — verhindert Weiterleitung auf beliebige Pfade. */
const ALLOWED_PREFIXES = ['locations', 'items'];

$code = trim((string) ($_GET['r'] ?? ''), '/');

if (
    preg_match('/^([a-z]+)_(\d+)$/', $code, $matches) === 1
    && in_array($matches[1], ALLOWED_PREFIXES, true)
) {
    $prefix = $matches[1];
    $id = (int) $matches[2];

    // Lagerplatz-QR führt auf die Items-Liste, gefiltert auf diesen Platz.
    // Item-QR führt direkt auf das Item-Detail.
    $target = $prefix === 'locations'
        ? APP_WAREHOUSE_BASE . '/items?location_id=' . $id
        : APP_WAREHOUSE_BASE . '/' . $prefix . '/' . $id;

    header('Location: ' . $target, true, 302);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Short link not found';
