<?php
declare(strict_types=1);
namespace App;

final class AttachmentService {
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const PDF = 'application/pdf';
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const WORKBOOK_XML = self::XLSX.'.main+xml';
    private const METADATA = 'id, match_id, original_name, size_bytes, is_public, created_at';

    public function __construct(private Database $db, private Auth $auth, private AuditLogger $audit) {}

    public function listFiles(int $tid, ?int $matchId, bool $admin): array {
        if ($admin || $matchId !== null) $this->auth->requireTournament($tid);
        if ($matchId !== null) $this->requireMatch($tid, $matchId);
        $where = $matchId === null ? 'match_id IS NULL' : 'match_id = ?';
        if (!$admin && $matchId === null) $where .= ' AND is_public = 1';
        $params = $matchId === null ? [$tid] : [$tid, $matchId];
        return $this->db->all('SELECT '.self::METADATA." FROM attachments WHERE tournament_id = ? AND $where ORDER BY id DESC", $params);
    }

    public function upload(int $tid, ?int $matchId, bool $isPublic, array $file): void {
        $this->auth->requireTournament($tid);
        if ($matchId !== null && $isPublic) throw new Problem('比赛附件仅管理员可见。');
        $name = $file['name'] ?? null;
        $path = $file['tmp_name'] ?? null;
        if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new Problem('附件超过上传大小限制。', 413);
        }
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path)) {
            throw new Problem('未收到完整的附件，请重新选择文件。');
        }
        $size = filesize($path);
        if (!$size || $size > self::MAX_BYTES) throw new Problem('附件必须非空且每份不超过 10 MB。', 413);
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $name) || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new Problem('附件文件名无效。');
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $matchId === null ? ['pdf', 'xlsx'] : ['xlsx'];
        if (!in_array($extension, $allowed, true)) {
            throw new Problem($matchId === null ? '赛事附件仅支持 PDF 和 XLSX。' : '比赛附件仅支持 XLSX。');
        }

        if ($extension === 'pdf') {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if ($mime !== self::PDF || file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
                throw new Problem('文件内容不是有效的 PDF。');
            }
        } else {
            self::validateWorkbook($path);
        }
        $content = file_get_contents($path);
        if ($content === false || strlen($content) !== $size) throw new Problem('读取附件失败，请重新上传。', 500);

        $this->db->transaction(function () use ($tid, $matchId, $isPublic, $name, $size, $content): void {
            $this->lockTournament($tid);
            if ($matchId !== null) $this->requireMatch($tid, $matchId);
            $this->db->run(
                'INSERT INTO attachments (tournament_id, match_id, original_name, size_bytes, is_public, content) VALUES (?, ?, ?, ?, ?, ?)',
                [$tid, $matchId, $name, $size, (int)$isPublic, $content]
            );
            $id = $this->db->id();
            $this->audit->append($tid, 'attachment_upload', 'attachment', $id, null, $this->metadata($tid, $id));
        });
    }

    public function changeVisibility(int $tid, int $id, bool $isPublic): void {
        $this->db->transaction(function () use ($tid, $id, $isPublic): void {
            $this->lockTournament($tid);
            $before = $this->metadata($tid, $id);
            if ($before['match_id'] !== null) throw new Problem('比赛附件仅管理员可见。');
            if ((bool)$before['is_public'] === $isPublic) return;
            $this->db->run('UPDATE attachments SET is_public = ? WHERE tournament_id = ? AND id = ?', [(int)$isPublic, $tid, $id]);
            $after = $before;
            $after['is_public'] = (int)$isPublic;
            $this->audit->append($tid, 'attachment_visibility', 'attachment', $id, $before, $after);
        });
    }

    public function delete(int $tid, int $id): void {
        $this->db->transaction(function () use ($tid, $id): void {
            $this->lockTournament($tid);
            $before = $this->metadata($tid, $id);
            $this->db->run('DELETE FROM attachments WHERE tournament_id = ? AND id = ?', [$tid, $id]);
            $this->audit->append($tid, 'attachment_delete', 'attachment', $id, $before, null);
        });
    }

    public function download(int $tid, int $id): never {
        $admin = $this->auth->can($tid);
        $file = $this->db->one(
            'SELECT original_name, size_bytes, content FROM attachments
             WHERE tournament_id = ? AND id = ? AND (? = 1 OR (match_id IS NULL AND is_public = 1))',
            [$tid, $id, (int)$admin]
        );
        if (!$file) throw new Problem('附件不存在或无权访问。', 404);
        $pdf = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION)) === 'pdf';
        $fallback = $pdf ? 'document.pdf' : 'workbook.xlsx';
        session_write_close();
        header('Cache-Control: private, no-store');
        header('Content-Type: '.($pdf ? self::PDF : self::XLSX));
        header('Content-Disposition: attachment; filename="'.$fallback.'"; filename*=UTF-8'."''".rawurlencode($file['original_name']));
        header('Content-Length: '.$file['size_bytes']);
        echo $file['content'];
        exit;
    }

    private function metadata(int $tid, int $id): array {
        return $this->db->one('SELECT '.self::METADATA.' FROM attachments WHERE tournament_id = ? AND id = ?', [$tid, $id])
            ?? throw new Problem('附件不存在。', 404);
    }

    private function lockTournament(int $tid): void {
        $row = $this->db->one('SELECT id FROM tournaments WHERE id = ? FOR UPDATE', [$tid]);
        if (!$row) throw new Problem('赛事不存在。', 404);
        $this->auth->requireTournament($tid);
    }

    private function requireMatch(int $tid, int $matchId): void {
        if (!$this->db->one('SELECT id FROM matches WHERE tournament_id = ? AND id = ?', [$tid, $matchId])) {
            throw new Problem('比赛不存在。', 404);
        }
    }

    private static function validateWorkbook(string $path): void {
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class)) {
            throw new Problem('服务器需要启用 PHP 的 zip 和 DOM 扩展才能接收 XLSX。', 503);
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) throw new Problem('文件内容不是有效的 XLSX。');
        try {
            $manifest = $zip->statName('[Content_Types].xml');
            if (!$manifest || $manifest['size'] > 1024 * 1024 || $zip->locateName('_rels/.rels') === false
                || $zip->locateName('xl/workbook.xml') === false || $zip->locateName('xl/vbaProject.bin', \ZipArchive::FL_NOCASE) !== false) {
                throw new Problem('请选择有效的 .xlsx 工作簿。');
            }
            $xml = $zip->getFromName('[Content_Types].xml', 1024 * 1024);
        } finally {
            $zip->close();
        }
        $document = new \DOMDocument();
        if ($xml === false || $xml === '' || !@$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
            throw new Problem('无法识别 XLSX 文件的工作簿类型。');
        }
        $typesNamespace = 'http://schemas.openxmlformats.org/package/2006/content-types';
        foreach ($document->getElementsByTagNameNS($typesNamespace, 'Override') as $part) {
            if ($part->getAttribute('PartName') !== '/xl/workbook.xml') continue;
            if ($part->getAttribute('ContentType') === self::WORKBOOK_XML) return;
            throw new Problem('请选择有效的 .xlsx 工作簿。');
        }
        // A part without an override inherits the content type declared for its extension.
        foreach ($document->getElementsByTagNameNS($typesNamespace, 'Default') as $part) {
            if (strcasecmp($part->getAttribute('Extension'), 'xml') === 0
                && $part->getAttribute('ContentType') === self::WORKBOOK_XML) return;
        }
        throw new Problem('请选择有效的 .xlsx 工作簿。');
    }
}
