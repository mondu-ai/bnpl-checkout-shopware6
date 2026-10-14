<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder\Service;

use Doctrine\DBAL\Connection;
use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderFields;

/**
 * Keeps the Mondu fields of an order being created in the admin until the order is saved.
 *
 * The admin saves the order through POST /api/_proxy-order/{salesChannelId} with an empty
 * body, so the fields cannot travel with that request. They are kept in the payload of the
 * sales channel context the admin works in, next to what Shopware itself stores there for
 * the order being created (customer, permissions), and are read back when the cart is
 * converted into the order.
 *
 * The row is written directly instead of through SalesChannelContextPersister::save():
 * that call needs the customer id to keep the row's customer, and with a customer id it may
 * pick up another context of the same customer and copy it under this token.
 */
class AsyncOrderFieldStorage
{
    public const PAYLOAD_KEY = 'monduAsyncOrderFields';

    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * @param array<string, mixed> $fields
     * @return bool false when no context exists for the token
     */
    public function save(string $contextToken, array $fields): bool
    {
        $payload = $this->loadPayload($contextToken);
        if ($payload === null) {
            return false;
        }

        $payload[self::PAYLOAD_KEY] = AsyncOrderFields::sanitize($fields);

        $this->connection->executeStatement(
            'UPDATE sales_channel_api_context SET payload = :payload WHERE token = :token',
            ['payload' => json_encode($payload, \JSON_THROW_ON_ERROR), 'token' => $contextToken]
        );

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function load(string $contextToken): array
    {
        $fields = $this->loadPayload($contextToken)[self::PAYLOAD_KEY] ?? [];

        return is_array($fields) ? AsyncOrderFields::sanitize($fields) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPayload(string $contextToken): ?array
    {
        $payload = $this->connection->fetchOne(
            'SELECT payload FROM sales_channel_api_context WHERE token = :token',
            ['token' => $contextToken]
        );

        if ($payload === false || $payload === null) {
            return null;
        }

        $decoded = json_decode((string) $payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
