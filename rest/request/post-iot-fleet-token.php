<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * POST /iot/fleet-tokens
 *
 * Erzeugt ein neues Fleet-Provisioning-Token (Admin-only, Session-Auth via
 * RequestBase::requireAuth). Das Token wird beim Flashen einer Geräte-Charge
 * verteilt und autorisiert deren Erst-Registrierung.
 *
 * Es wird nur der SHA-256-Hash gespeichert; der Klartext wird genau einmal in
 * der Response zurückgegeben und existiert danach nirgends mehr auf dem Server.
 *
 * Body: { "label": "Charge 2026-06" }
 */
class requestPostIotFleetToken extends RequestBase {
    private array $data = [];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            CsrfHelper::requireValidToken();
            $this->requireAnyPermission(['admin.access']);

            $label = trim($this->data['label'] ?? '');
            if ($label === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required field: label']);
                return;
            }

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            $stmt = $this->pdo->prepare(
                "INSERT INTO mbc_iot_fleet_tokens (label, token_hash, status)
                 VALUES (?, ?, 'active')"
            );
            $stmt->execute([$label, $tokenHash]);

            http_response_code(201);
            echo json_encode([
                'id'    => (int)$this->pdo->lastInsertId(),
                'label' => $label,
                'token' => $token,
                'note'  => 'Store this token now — it is shown only once and cannot be retrieved later.',
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error creating fleet provisioning token', $e);
        }
    }
}
