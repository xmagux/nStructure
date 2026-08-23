<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

use NStructure\Domain\Exception\ResourceInUseException;
use NStructure\Domain\Repository\UserRepository;
use PDO;
use RuntimeException;

final readonly class MySqlUserRepository implements UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, email, name, archived_at, last_login_at, created_at
             FROM users
             ORDER BY archived_at IS NOT NULL, name',
        );

        return array_map(fn (array $row): array => $this->normalize($row), $statement->fetchAll());
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, name, archived_at, last_login_at, created_at FROM users WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalize($row) : null;
    }

    public function findActiveByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, name, password_hash, archived_at, last_login_at, created_at
             FROM users WHERE email = :email AND archived_at IS NULL',
        );
        $statement->execute(['email' => strtolower(trim($email))]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function create(array $input): array
    {
        $email = strtolower(trim((string) $input['email']));
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash) VALUES (:email, :name, :password_hash)',
        );
        try {
            $statement->execute([
                'email' => $email,
                'name' => trim((string) $input['name']),
                'password_hash' => password_hash((string) $input['password'], PASSWORD_DEFAULT),
            ]);
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('An account with this email already exists');
            }
            throw $exception;
        }

        $user = $this->findById((int) $this->pdo->lastInsertId());
        if ($user === null) {
            throw new RuntimeException('User could not be read back after creation');
        }
        $this->recordAudit('USER', $user['id'], 'CREATE', null, ['email' => $user['email'], 'name' => $user['name']]);
        return $user;
    }

    public function updateProfile(int $userId, string $name, string $email): array
    {
        $email = strtolower(trim($email));
        $statement = $this->pdo->prepare('UPDATE users SET name = :name, email = :email WHERE id = :id AND archived_at IS NULL');
        try {
            $statement->execute(['id' => $userId, 'name' => trim($name), 'email' => $email]);
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('An account with this email already exists');
            }
            throw $exception;
        }

        $user = $this->findById($userId);
        if ($user === null) {
            throw new RuntimeException('User not found');
        }
        $this->recordAudit('USER', $userId, 'UPDATE', null, ['email' => $user['email'], 'name' => $user['name']]);
        return $user;
    }

    public function archive(int $userId): array
    {
        $user = $this->findById($userId);
        if ($user === null || $user['archived_at'] !== null) {
            throw new RuntimeException('User not found');
        }
        if ($this->countActive() <= 1) {
            throw new ResourceInUseException('last_active_user');
        }

        $statement = $this->pdo->prepare('UPDATE users SET archived_at = NOW() WHERE id = :id AND archived_at IS NULL');
        $statement->execute(['id' => $userId]);
        $this->recordAudit('USER', $userId, 'ARCHIVE', ['email' => $user['email'], 'name' => $user['name']], null);

        return ['id' => $userId, 'archived' => true];
    }

    public function touchLastLogin(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id AND archived_at IS NULL');
        $statement->execute(['id' => $userId]);
        $hash = $statement->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    }

    public function updatePassword(int $userId, string $newPassword): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $statement->execute([
            'id' => $userId,
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);
        $this->recordAudit('USER', $userId, 'PASSWORD_CHANGE', null, null);
    }

    private function recordAudit(string $entityType, int $entityId, string $action, ?array $before, ?array $after): void
    {
        $audit = $this->pdo->prepare(
            'INSERT INTO audit_events (user_id, entity_type, entity_id, action, before_data, after_data, ip_address)
             VALUES (:user_id, :entity_type, :entity_id, :action, :before_data, :after_data, :ip_address)',
        );
        $audit->execute([
            'user_id' => $_SESSION['user_id'] ?? null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_data' => $before !== null ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_data' => $after !== null ? json_encode($after, JSON_THROW_ON_ERROR) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    public function countActive(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users WHERE archived_at IS NULL')->fetchColumn();
    }

    public function auditLog(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ae.id, ae.entity_type, ae.entity_id, ae.action, ae.before_data, ae.after_data,
                ae.ip_address, ae.created_at, u.name AS user_name, u.email AS user_email
             FROM audit_events ae
             LEFT JOIN users u ON u.id = ae.user_id
             ORDER BY ae.created_at DESC, ae.id DESC
             LIMIT ' . max(1, min(500, $limit)),
        );
        $statement->execute();

        return array_map(static function (array $row): array {
            $after = $row['after_data'] !== null ? json_decode((string) $row['after_data'], true) : null;
            $before = $row['before_data'] !== null ? json_decode((string) $row['before_data'], true) : null;
            $label = $after['code'] ?? $after['name'] ?? $before['code'] ?? $before['name'] ?? null;
            return [
                'id' => (int) $row['id'],
                'entity_type' => $row['entity_type'],
                'entity_id' => (int) $row['entity_id'],
                'entity_label' => $label,
                'action' => $row['action'],
                'user_name' => $row['user_name'],
                'user_email' => $row['user_email'],
                'created_at' => $row['created_at'],
            ];
        }, $statement->fetchAll());
    }

    private function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'name' => $row['name'],
            'archived_at' => $row['archived_at'],
            'last_login_at' => $row['last_login_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
