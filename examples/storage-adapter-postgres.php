<?php

declare(strict_types=1);

namespace StripeKit\Examples;

use PDO;
use StripeKit\Contracts\StorageAdapter;

class PostgresStorageAdapter extends StorageAdapter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, stripe_customer_id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['id' => $row['id'], 'stripeCustomerId' => $row['stripe_customer_id']] : null;
    }

    public function findUserByStripeCustomerId(string $customerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, stripe_customer_id FROM users WHERE stripe_customer_id = :customerId LIMIT 1');
        $stmt->execute(['customerId' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['id' => $row['id'], 'stripeCustomerId' => $row['stripe_customer_id']] : null;
    }

    public function findUserById(string|int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, stripe_customer_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['id' => $row['id'], 'stripeCustomerId' => $row['stripe_customer_id']] : null;
    }

    public function saveCustomer(array $record): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stripe_customers (id, user_id, email, name, deleted, updated_at)
             VALUES (:id, :userId, :email, :name, :deleted, now())
             ON CONFLICT (id) DO UPDATE SET email = EXCLUDED.email, name = EXCLUDED.name, deleted = EXCLUDED.deleted, updated_at = now()',
        );
        $stmt->execute([
            'id' => $record['id'],
            'userId' => $record['userId'],
            'email' => $record['email'],
            'name' => $record['name'],
            'deleted' => $record['deleted'],
        ]);
    }

    public function saveSubscription(array $record): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions (id, user_id, customer_id, status, current_period_end_utc, updated_at)
             VALUES (:id, :userId, :customerId, :status, :currentPeriodEndUtc, now())
             ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status, current_period_end_utc = EXCLUDED.current_period_end_utc, updated_at = now()',
        );
        $stmt->execute([
            'id' => $record['id'],
            'userId' => $record['userId'],
            'customerId' => $record['customerId'],
            'status' => $record['status'],
            'currentPeriodEndUtc' => $record['currentPeriodEndUtc'],
        ]);
    }

    public function savePayment(array $record): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments (id, user_id, customer_id, amount, currency, status, created_at)
             VALUES (:id, :userId, :customerId, :amount, :currency, :status, :createdAt)
             ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status',
        );
        $stmt->execute([
            'id' => $record['id'],
            'userId' => $record['userId'],
            'customerId' => $record['customerId'],
            'amount' => $record['amount'],
            'currency' => $record['currency'],
            'status' => $record['status'],
            'createdAt' => $record['createdAtUtc'],
        ]);
    }

    public function saveInvoice(array $record): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoices (id, user_id, customer_id, amount_due, amount_paid, status, updated_at)
             VALUES (:id, :userId, :customerId, :amountDue, :amountPaid, :status, now())
             ON CONFLICT (id) DO UPDATE SET amount_paid = EXCLUDED.amount_paid, status = EXCLUDED.status, updated_at = now()',
        );
        $stmt->execute([
            'id' => $record['id'],
            'userId' => $record['userId'],
            'customerId' => $record['customerId'],
            'amountDue' => $record['amountDue'],
            'amountPaid' => $record['amountPaid'],
            'status' => $record['status'],
        ]);
    }

    public function hasProcessedWebhookEvent(string $eventId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM webhook_events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        return $stmt->fetchColumn() !== false;
    }

    public function markWebhookEventProcessed(string $eventId, string $type): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO webhook_events (id, type, processed_at) VALUES (:id, :type, now()) ON CONFLICT DO NOTHING',
        );
        $stmt->execute(['id' => $eventId, 'type' => $type]);
    }
}
