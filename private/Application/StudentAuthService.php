<?php
declare(strict_types=1);

final class StudentAuthService
{
    public function __construct(private StudentAuthRepository $accounts) {}

    public function login(string $studentId, string $password): ?array
    {
        $studentId = strtoupper(trim($studentId));
        $account = $this->accounts->findActiveAccount($studentId);
        if (!$account || !password_verify($password, $account['password_hash'])) return null;
        $token = bin2hex(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
        $this->accounts->saveToken($studentId, hash('sha256', $token), $expires);
        return ['ok'=>true, 'token'=>$token, 'expires_at'=>$expires,
            'must_change_password'=>(bool)$account['must_change_password']];
    }

    public function logout(string $token): void
    {
        if ($token !== '') $this->accounts->revokeToken(hash('sha256', $token));
    }
}
