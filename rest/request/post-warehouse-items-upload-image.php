<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /warehouse-items/upload-image
 *
 * Nimmt ein Bild via multipart/form-data im Feld "image" entgegen,
 * speichert es nach <repo-root>/dist/uploads/items/<uniqid>.<ext>
 * und liefert die zugehoerige URL zurueck.
 *
 * @version 1.0.0
 */
class requestPostWarehouseItemsUploadImage extends RequestBase {
    private const FILE_SIZE_MAX = 10_000_000;            // 10 MB
    private const ALLOWED_EXT  = ['jpg', 'jpeg', 'png', 'webp', 'heic'];
    private const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];
    /**
     * Zielverzeichnis relativ zu diesem Handler-File (`<api-root>/request/`).
     * Eine Ebene rauf nach `<api-root>/`, dann nach `uploads/items/`.
     * Auf Prod: `rest2/uploads/items/`. Lokal: `rest/uploads/items/`.
     */
    private const TARGET_DIR_REL = '../uploads/items/';

    public function execute(): void {
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }

            if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing file field "image"']);
                return;
            }

            $file = $_FILES['image'];

            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['error' => 'Upload failed', 'code' => $file['error'] ?? null]);
                return;
            }

            $size = (int)($file['size'] ?? 0);
            if ($size <= 0 || $size > self::FILE_SIZE_MAX) {
                http_response_code(400);
                echo json_encode(['error' => 'File too large or empty', 'size' => $size]);
                return;
            }

            $origName = (string)($file['name'] ?? '');
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXT, true)) {
                http_response_code(415);
                echo json_encode(['error' => 'Unsupported file extension', 'ext' => $ext]);
                return;
            }

            $tmp = (string)($file['tmp_name'] ?? '');
            if (!is_uploaded_file($tmp)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid upload']);
                return;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = (string)$finfo->file($tmp);
            if (!in_array($mime, self::ALLOWED_MIME, true)) {
                http_response_code(415);
                echo json_encode(['error' => 'Unsupported MIME type', 'mime' => $mime]);
                return;
            }

            $targetDir = __DIR__ . '/' . self::TARGET_DIR_REL;
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                $this->handleError('Could not create upload directory: ' . $targetDir);
                return;
            }

            do {
                $name = uniqid('itm_', true) . '.' . $ext;
                $target = $targetDir . $name;
            } while (file_exists($target));

            if (!move_uploaded_file($tmp, $target)) {
                $this->handleError('Failed to move uploaded file');
                return;
            }

            // Liefere eine absolute URL zurück, abgeleitet aus dem aktuellen Request,
            // damit das FE den Pfad ohne weiteres Wissen über die API-Basis anzeigen kann.
            // Beispiel auf Prod: https://oliverlohkemper.de/rest2/uploads/items/itm_xyz.jpg
            $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                ? 'https'
                : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $apiBase = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/'); // -> /rest2 oder /rest
            $url = $scheme . '://' . $host . $apiBase . '/uploads/items/' . $name;

            http_response_code(201);
            header('Content-Type: application/json');
            echo json_encode([
                'url'  => $url,
                'name' => $name,
                'size' => $size,
                'mime' => $mime,
            ]);
        } catch (\Throwable $e) {
            $this->handleError('Error uploading warehouse item image', $e);
        }
    }
}
