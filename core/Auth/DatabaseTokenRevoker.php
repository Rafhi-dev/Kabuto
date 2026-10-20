<?php

declare(strict_types=1);

namespace Kabuto\Auth;

use PDO;

final class DatabaseTokenRevoker implements AtomicTokenRevoker
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS kabuto_jwt_revocations (jti VARCHAR(191) PRIMARY KEY, family VARCHAR(191) NULL, expires_at INTEGER NOT NULL)');
    }

    public function revoke(string $jti, int $expiresAt): void
    {
        $this->purge();
        $update = $this->pdo->prepare('UPDATE kabuto_jwt_revocations SET expires_at = ? WHERE jti = ?');
        $update->execute([$expiresAt, $jti]);
        if ($update->rowCount() === 0) {
            try {
                $this->pdo->prepare('INSERT INTO kabuto_jwt_revocations (jti, expires_at) VALUES (?, ?)')->execute([$jti, $expiresAt]);
            } catch (\PDOException $exception) {
                if (!in_array($exception->errorInfo[0] ?? null, ['23000', '23505', '19'], true)) throw $exception;
                $this->pdo->prepare('UPDATE kabuto_jwt_revocations SET expires_at = ? WHERE jti = ?')->execute([$expiresAt, $jti]);
            }
        }
    }

    public function isRevoked(string $jti): bool
    {
        $this->purge();
        $stmt = $this->pdo->prepare('SELECT 1 FROM kabuto_jwt_revocations WHERE jti = ? AND expires_at > ?');
        $stmt->execute([$jti, time()]);
        return $stmt->fetchColumn() !== false;
    }

    public function consume(string $jti, string $family, int $expiresAt): bool
    {
        $this->purge();
        if ($family !== '' && $this->isFamilyRevoked($family)) return false;
        try {
            $stmt = $this->pdo->prepare('INSERT INTO kabuto_jwt_revocations (jti, expires_at) VALUES (?, ?)');
            $stmt->execute([$jti, $expiresAt]);
            return true;
        } catch (\PDOException $exception) {
            if (in_array($exception->errorInfo[0] ?? null, ['23000', '23505', '19'], true)) return false;
            throw $exception;
        }
    }

    public function revokeFamily(string $family, int $expiresAt): void
    {
        $this->revoke('family:' . $family, $expiresAt);
    }

    public function isFamilyRevoked(string $family): bool
    {
        return $this->isRevoked('family:' . $family);
    }

    private function purge(): void
    {
        $this->pdo->prepare('DELETE FROM kabuto_jwt_revocations WHERE expires_at <= ?')->execute([time()]);
    }
}
