<?php
namespace Controllers;

use Http\Request;
use Http\Response;
use Infrastructure\Persistence\SessionRepository;
use Infrastructure\Persistence\ContextDocumentRepository;

class ContextDocumentController {
    private const MAX_CHARS  = 50000;
    private const WARN_CHARS = 30000;
    private const MAX_BYTES  = 10 * 1024 * 1024; // 10 MB

    private SessionRepository         $sessionRepo;
    private ContextDocumentRepository $docRepo;

    public function __construct() {
        $this->sessionRepo = new SessionRepository();
        $this->docRepo     = new ContextDocumentRepository();
    }

    public function show(Request $req): array {
        $id  = $req->param('id');
        $doc = $this->docRepo->findBySession($id);
        return ['context_document' => $doc];
    }

    public function saveManual(Request $req): array {
        $id   = $req->param('id');
        $data = $req->body();

        if (!$this->sessionRepo->findById($id)) {
            return Response::error('Session not found', 404);
        }

        $content = $data['content'] ?? '';
        if ($content === null || trim((string)$content) === '') {
            return Response::error('Content is required', 400);
        }

        $charCount = mb_strlen($content, 'UTF-8');
        if ($charCount > self::MAX_CHARS) {
            return Response::error(
                'Content exceeds 50,000 characters (' . $charCount . ' chars)',
                400
            );
        }

        $title = trim($data['title'] ?? '') ?: null;

        $doc = $this->docRepo->upsert([
            'id'              => $this->uuid(),
            'session_id'      => $id,
            'title'           => $title,
            'source_type'     => 'manual',
            'original_filename' => null,
            'mime_type'       => 'text/plain',
            'content'         => $content,
            'character_count' => $charCount,
        ]);

        return [
            'context_document' => $doc,
            'warning'          => $charCount > self::WARN_CHARS
                ? 'Large context may reduce model quality'
                : null,
        ];
    }

    public function upload(Request $req): array {
        $id = $req->param('id');

        if (!$this->sessionRepo->findById($id)) {
            return Response::error('Session not found', 404);
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            return Response::error('No file uploaded', 400);
        }

        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return Response::error('Upload error (code ' . $file['error'] . ')', 400);
        }

        if ($file['size'] > self::MAX_BYTES) {
            return Response::error('File exceeds 10 MB limit', 400);
        }

        $originalName = $file['name'];
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            return Response::error(
                'PDF extraction is not supported. Please paste the content manually or use TXT/MD.',
                422
            );
        }

        if (!in_array($ext, ['txt', 'md', 'docx'], true)) {
            return Response::error('Unsupported file type. Allowed: .txt .md .pdf .docx', 400);
        }

        try {
            $content = $this->extractText($file['tmp_name'], $ext);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        $charCount = mb_strlen($content, 'UTF-8');
        if ($charCount > self::MAX_CHARS) {
            return Response::error(
                'Extracted content exceeds 50,000 characters (' . $charCount . ' chars)',
                400
            );
        }

        $title = trim($_POST['title'] ?? '') ?: null;

        $doc = $this->docRepo->upsert([
            'id'               => $this->uuid(),
            'session_id'       => $id,
            'title'            => $title,
            'source_type'      => 'upload',
            'original_filename'=> $originalName,
            'mime_type'        => $file['type'] ?: 'application/octet-stream',
            'content'          => $content,
            'character_count'  => $charCount,
        ]);

        return [
            'context_document' => $doc,
            'warning'          => $charCount > self::WARN_CHARS
                ? 'Large context may reduce model quality'
                : null,
        ];
    }

    public function destroy(Request $req): array {
        $id = $req->param('id');

        if (!$this->sessionRepo->findById($id)) {
            return Response::error('Session not found', 404);
        }

        $this->docRepo->delete($id);
        return ['success' => true];
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function extractText(string $tmpPath, string $ext): string {
        if ($ext === 'txt' || $ext === 'md') {
            $content = file_get_contents($tmpPath);
            if ($content === false) {
                throw new \RuntimeException('Cannot read file.');
            }
            return $content;
        }

        if ($ext === 'docx') {
            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException(
                    'ZipArchive extension is not enabled on this server. Cannot extract DOCX.'
                );
            }
            $zip = new \ZipArchive();
            if ($zip->open($tmpPath) !== true) {
                throw new \RuntimeException('Cannot open DOCX file.');
            }
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml === false) {
                throw new \RuntimeException('Cannot read DOCX content (word/document.xml not found).');
            }
            $text = strip_tags($xml);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', $text);
            return trim($text);
        }

        throw new \RuntimeException('Unsupported format: ' . $ext);
    }

    private function uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
