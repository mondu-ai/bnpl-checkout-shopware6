<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Subscriber;

use Mondu\MonduPayment\Components\Checkout\Service\NetTermOfferService;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduHandler;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Util\NetTermName;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Names the payment method in order emails with the order's term, see NetTermName.
 *
 * The templates print the stored name, "Rechnungskauf (30 Tage)", whatever term the order
 * went out with. The order confirmation is sent while the order is placed, before the
 * payment handler sent the term to Mondu, so the term comes from the checkout form then,
 * checked against the offer the same way the handler does it. Later emails use the term
 * stored with the order. The order in the template data is a copy, the order the rest of
 * the request works with keeps the stored name.
 */
class MailNetTermSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NetTermOfferService $netTermOfferService,
        private readonly EntityRepository $orderDataRepository,
        private readonly RequestStack $requestStack
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            MailBeforeValidateEvent::class => 'onMailBeforeValidate',
        ];
    }

    public function onMailBeforeValidate(MailBeforeValidateEvent $event): void
    {
        $templateData = $event->getTemplateData();
        $order = $templateData['order'] ?? null;

        if (!$order instanceof OrderEntity) {
            return;
        }

        $netTerm = $this->storedNetTerm($order->getId(), $event->getContext()) ?? $this->checkoutNetTerm($order);
        if ($netTerm === null) {
            return;
        }

        $order = clone $order;
        $renamed = false;

        if ($order->getTransactions() !== null) {
            $transactions = new OrderTransactionCollection();
            foreach ($order->getTransactions() as $transaction) {
                $transactions->add($this->renamed($transaction, $netTerm, $renamed));
            }
            $order->setTransactions($transactions);
        }

        if (method_exists($order, 'getPrimaryOrderTransaction') && $order->getPrimaryOrderTransaction() !== null) {
            $order->setPrimaryOrderTransaction($this->renamed($order->getPrimaryOrderTransaction(), $netTerm, $renamed));
        }

        if ($renamed) {
            $event->addTemplateData('order', $order);
        }
    }

    private function renamed(OrderTransactionEntity $transaction, int $netTerm, bool &$renamed): OrderTransactionEntity
    {
        $paymentMethod = $transaction->getPaymentMethod();
        if ($paymentMethod === null || !MethodHelper::isMonduPayment($paymentMethod)) {
            return $transaction;
        }

        $transaction = clone $transaction;
        $paymentMethod = clone $paymentMethod;
        NetTermName::apply($paymentMethod, [$netTerm]);
        $transaction->setPaymentMethod($paymentMethod);
        $renamed = true;

        return $transaction;
    }

    /**
     * The term Mondu authorized, or the one the buyer picked until Mondu decided.
     */
    private function storedNetTerm(string $orderId, Context $context): ?int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter(OrderDataEntity::FIELD_ORDER_ID, $orderId));

        /** @var OrderDataEntity|null $data */
        $data = $this->orderDataRepository->search($criteria, $context)->first();
        $duration = (int) ($data?->getDuration() ?? 0);

        return $duration > 0 ? $duration : null;
    }

    /**
     * The term the checkout form sends with the order being placed. A request without the
     * field (the Store API, the admin, a message worker) gives none.
     */
    private function checkoutNetTerm(OrderEntity $order): ?int
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->request->has(MonduHandler::NET_TERM_FIELD)) {
            return null;
        }

        $transactions = $order->getTransactions();
        $paymentMethod = $transactions !== null ? $transactions->last()?->getPaymentMethod() : null;
        if ($paymentMethod === null || !MethodHelper::isMonduPayment($paymentMethod)) {
            return null;
        }

        return $this->netTermOfferService->resolve(
            $order->getSalesChannelId(),
            MethodHelper::shortNameToMonduName($paymentMethod->getShortName()),
            $this->billingCountry($order),
            $request->request->get(MonduHandler::NET_TERM_FIELD)
        );
    }

    /**
     * The placed order comes with its addresses, not with the billing address association.
     */
    private function billingCountry(OrderEntity $order): ?string
    {
        $address = $order->getBillingAddress() ?? $order->getAddresses()?->get($order->getBillingAddressId());

        return $address?->getCountry()?->getIso();
    }
}
