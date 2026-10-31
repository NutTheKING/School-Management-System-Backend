<?php
declare(strict_types=1);

final class PdoStudentAuthRepository implements StudentAuthRepository
{
    public function __construct(private PDO $db) {}

    public function findActiveAccount(string $studentId): ?array
    {
        $query = $this->db->prepare('SELECT a.* FROM mobile_accounts a JOIN students s ON s.id=a.student_id WHERE a.student_id=? AND a.active=1 AND s.status=?');
        $query->execute([$studentId, 'Active']);
        return $query->fetch() ?: null;
    }

    public function saveToken(string $studentId, string $tokenHash, string $expiresAt): void
    {
        $this->db->exec('DELETE FROM student_mobile_tokens WHERE expires_at<=CURRENT_TIMESTAMP');
        $query = $this->db->prepare('INSERT INTO student_mobile_tokens(student_id,token_hash,expires_at) VALUES(?,?,?)');
        $query->execute([$studentId, $tokenHash, $expiresAt]);
    }

    public function revokeToken(string $tokenHash): void
    {
        $this->db->prepare('DELETE FROM student_mobile_tokens WHERE token_hash=?')->execute([$tokenHash]);
    }
}
