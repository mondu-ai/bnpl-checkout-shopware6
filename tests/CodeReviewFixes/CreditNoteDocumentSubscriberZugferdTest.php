<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\CodeReviewFixes;

use Doctrine\DBAL\Connection;
use Mondu\MonduPayment\Components\Order\Subscriber\CreditNoteDocumentSubscriber;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests that CreditNoteDocumentSubscriber handles ZUGFeRD credit note types
 * in the cutoff date query, not just plain 'credit_note'.
 */
class CreditNoteDocumentSubscriberZugferdTest extends TestCase
{
    /**
     * Verify the SQL uses IN clause with all 3 credit note types via PARAM_STR_ARRAY.
     */
    public function testGetLatestCreditNoteCreatedAtIncludesZugferdTypes(): void
    {
        $connection = $this->createMock(Connection::class);
        $logger = new NullLogger();
        $configService = $this->createMock(ConfigService::class);

        $connection->expects(static::once())
            ->method('fetchOne')
            ->with(
                static::callback(function (string $sql): bool {
                    return str_contains($sql, 'IN (:technicalNames)');
                }),
                static::callback(function (array $params): bool {
                    return isset($params['technicalNames'])
                        && is_array($params['technicalNames'])
                        && in_array('credit_note', $params['technicalNames'], true)
                        && in_array('zugferd_credit_note', $params['technicalNames'], true)
                        && in_array('zugferd_embedded_credit_note', $params['technicalNames'], true);
                }),
                static::callback(function (array $types): bool {
                    return isset($types['technicalNames'])
                        && $types['technicalNames'] === Connection::PARAM_STR_ARRAY;
                })
            )
            ->willReturn('2026-05-13 12:00:00');

        $subscriber = new CreditNoteDocumentSubscriber($connection, $logger, $configService);

        $reflection = new \ReflectionMethod($subscriber, 'getLatestCreditNoteCreatedAt');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($subscriber, 'abc123');

        static::assertInstanceOf(\DateTimeImmutable::class, $result);
        static::assertSame('2026-05-13 12:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testReturnsNullWhenNoResults(): void
    {
        $connection = $this->createMock(Connection::class);
        $logger = new NullLogger();
        $configService = $this->createMock(ConfigService::class);

        $connection->method('fetchOne')->willReturn(false);

        $subscriber = new CreditNoteDocumentSubscriber($connection, $logger, $configService);

        $reflection = new \ReflectionMethod($subscriber, 'getLatestCreditNoteCreatedAt');
        $reflection->setAccessible(true);

        $result = $reflection->invoke($subscriber, 'abc123');

        static::assertNull($result);
    }
}
