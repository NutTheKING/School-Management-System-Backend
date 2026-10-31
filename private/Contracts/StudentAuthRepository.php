<?php
declare(strict_types=1);

interface StudentAuthRepository
{
    public function findActiveAccount(string $studentId): ?array;
    public function saveToken(string $studentId, string $tokenHash, string $expiresAt): void;
    public function revokeToken(string $tokenHash): void;
}
