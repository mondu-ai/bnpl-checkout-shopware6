<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\CodeReviewFixes;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\Order\Controller\InvoiceController;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests InvoiceController error handling: exceptions are now logged
 * instead of being silently swallowed.
 */
class InvoiceControllerTest extends TestCase
{
    public function testCancelLogsExceptionOnFailure(): void
    {
        $monduClient = $this->createMock(MonduClient::class);
        $orderRepository = $this->createMock(EntityRepository::class);
        $invoiceDataRepository = $this->createMock(EntityRepository::class);
        $orderDataRepository = $this->createMock(EntityRepository::class);
        $documentRepository = $this->createMock(EntityRepository::class);
        $documentGenerator = $this->createMock(DocumentGenerator::class);
        $logger = $this->createMock(LoggerInterface::class);

        $orderDataRepository->method('search')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $logger->expects(static::once())
            ->method('error')
            ->with(
                'mondu.ERROR: Invoice cancellation failed',
                static::callback(function (array $context): bool {
                    return $context['orderId'] === 'test-order-id'
                        && str_contains($context['exception'], 'DB connection lost');
                })
            );

        $controller = new InvoiceController(
            $monduClient,
            $orderRepository,
            $invoiceDataRepository,
            $orderDataRepository,
            $documentRepository,
            $documentGenerator,
            $logger
        );

        $container = $this->createMock(ContainerInterface::class);
        $controller->setContainer($container);

        $request = new Request();
        $context = Context::createDefaultContext();

        $response = $controller->cancel($request, 'test-order-id', 'test-invoice-id', $context);

        static::assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        static::assertSame('error', $data['status']);
        static::assertSame('3', $data['error']);
    }

    public function testCancelReturnsOkWhenOrderDataExistsButNoInvoice(): void
    {
        $monduClient = $this->createMock(MonduClient::class);
        $orderRepository = $this->createMock(EntityRepository::class);
        $invoiceDataRepository = $this->createMock(EntityRepository::class);
        $orderDataRepository = $this->createMock(EntityRepository::class);
        $documentRepository = $this->createMock(EntityRepository::class);
        $documentGenerator = $this->createMock(DocumentGenerator::class);
        $logger = $this->createMock(LoggerInterface::class);

        $orderSearchResult = $this->createMock(EntitySearchResult::class);
        $orderSearchResult->method('first')->willReturn(new class {
            public function getSalesChannelId(): string { return 'sc-1'; }
        });
        $orderRepository->method('search')->willReturn($orderSearchResult);

        $orderDataResult = $this->createMock(EntitySearchResult::class);
        $orderDataResult->method('first')->willReturn(new class {
            public function getReferenceId(): string { return 'ref-1'; }
        });
        $orderDataRepository->method('search')->willReturn($orderDataResult);

        $invoiceResult = $this->createMock(EntitySearchResult::class);
        $invoiceResult->method('first')->willReturn(null);
        $invoiceDataRepository->method('search')->willReturn($invoiceResult);

        $logger->expects(static::never())->method('error');

        $controller = new InvoiceController(
            $monduClient,
            $orderRepository,
            $invoiceDataRepository,
            $orderDataRepository,
            $documentRepository,
            $documentGenerator,
            $logger
        );

        $container = $this->createMock(ContainerInterface::class);
        $controller->setContainer($container);

        $response = $controller->cancel(new Request(), 'order-1', 'invoice-1', Context::createDefaultContext());

        static::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        static::assertSame('ok', $data['status']);
    }
}
