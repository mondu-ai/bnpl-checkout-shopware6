<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\AsyncOrder;

use Mondu\MonduPayment\Components\Checkout\Service\NetTermOfferService;
use Mondu\MonduPayment\Components\Checkout\Subscriber\MailNetTermSubscriber;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduHandler;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Country\CountryEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class MailNetTermSubscriberTest extends TestCase
{
    public function testLaterEmailsNameTheStoredTerm(): void
    {
        $order = $this->order();
        $event = $this->send($order, storedDuration: 60);

        static::assertSame('Rechnungskauf (60 Tage)', $this->shownName($event));
        // The order the rest of the request works with keeps the stored name.
        static::assertSame('Rechnungskauf (30 Tage)', $order->getTransactions()->first()->getPaymentMethod()->getTranslation('name'));
    }

    public function testTheOrderConfirmationNamesTheTermFromTheCheckoutForm(): void
    {
        $request = new Request([], [MonduHandler::NET_TERM_FIELD => '60']);
        $offer = $this->createMock(NetTermOfferService::class);
        $offer->expects(static::once())->method('resolve')->with('channel', 'invoice', 'DE', '60')->willReturn(60);

        $event = $this->send($this->order(), storedDuration: 0, request: $request, offer: $offer);

        static::assertSame('Rechnungskauf (60 Tage)', $this->shownName($event));
    }

    public function testWithoutATermTheNameStays(): void
    {
        // No stored term and no checkout form, e.g. a Store API order.
        $event = $this->send($this->order(), storedDuration: 0, request: new Request());
        static::assertSame('Rechnungskauf (30 Tage)', $this->shownName($event));

        // Not a Mondu order.
        $order = $this->order('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrePayment');
        static::assertSame('Rechnungskauf (30 Tage)', $this->shownName($this->send($order, storedDuration: 60)));
    }

    private function send(
        OrderEntity $order,
        int $storedDuration,
        ?Request $request = null,
        ?NetTermOfferService $offer = null
    ): MailBeforeValidateEvent {
        $data = new OrderDataEntity();
        $data->setId('data');
        $data->assign(['orderId' => $order->getId(), 'duration' => $storedDuration]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context) => new EntitySearchResult(
                'mondu_order_data',
                1,
                new EntityCollection([$data]),
                null,
                $criteria,
                $context
            )
        );

        $requestStack = new RequestStack();
        if ($request !== null) {
            $requestStack->push($request);
        }

        $subscriber = new MailNetTermSubscriber(
            $offer ?? $this->createMock(NetTermOfferService::class),
            $repository,
            $requestStack
        );

        $event = new MailBeforeValidateEvent([], Context::createDefaultContext(), ['order' => $order]);
        $subscriber->onMailBeforeValidate($event);

        return $event;
    }

    private function shownName(MailBeforeValidateEvent $event): string
    {
        return $event->getTemplateData()['order']->getTransactions()->first()->getPaymentMethod()->getTranslation('name');
    }

    private function order(string $handler = MonduHandler::class): OrderEntity
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('method');
        $paymentMethod->setHandlerIdentifier($handler);
        $paymentMethod->setShortName('mondu_handler');
        $paymentMethod->setName('Rechnungskauf (30 Tage)');
        $paymentMethod->setTranslated(['name' => 'Rechnungskauf (30 Tage)']);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction');
        $transaction->setPaymentMethod($paymentMethod);

        $country = new CountryEntity();
        $country->setId('country');
        $country->setIso('DE');

        $address = new OrderAddressEntity();
        $address->setId('billing');
        $address->setCountry($country);

        $order = new OrderEntity();
        $order->setId('order');
        $order->setSalesChannelId('channel');
        $order->setBillingAddressId('billing');
        $order->setAddresses(new OrderAddressCollection([$address]));
        $order->setTransactions(new OrderTransactionCollection([$transaction]));

        return $order;
    }
}
