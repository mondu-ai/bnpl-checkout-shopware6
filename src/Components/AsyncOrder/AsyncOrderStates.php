<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder;

/**
 * Order flows and the payment states an order created in the admin passes through.
 *
 * An order the merchant creates in the admin goes to Mondu through POST /orders/create_async.
 * Mondu assesses it right away and then asks the buyer to confirm it by email, so the
 * order can sit in two states Shopware has no name for: under review at Mondu, and
 * waiting for the buyer. Both are added to the order_transaction state machine by
 * Migration1791676800AsyncOrderFlow. The transition that leads into a state carries the
 * same technical name as the state.
 */
final class AsyncOrderStates
{
    /**
     * Order placed by the buyer in the storefront and paid through the hosted checkout.
     */
    public const FLOW_AUTHORIZATION = 'authorization';

    /**
     * Order created by the merchant in the admin through POST /orders/create_async.
     */
    public const FLOW_ASYNC = 'async';

    /**
     * Mondu reviews the order (order/pending webhook).
     */
    public const STATE_PAYMENT_REVIEW = 'mondu_payment_review';

    /**
     * Mondu approved the order and waits for the buyer to confirm it by email
     * (order/authorized webhook).
     */
    public const STATE_PENDING_BUYER_CONFIRMATION = 'mondu_pending_buyer_confirmation';

    public const STATES = [
        self::STATE_PAYMENT_REVIEW,
        self::STATE_PENDING_BUYER_CONFIRMATION,
    ];

    /**
     * Existing states an order transaction may enter each new state from.
     *
     * A transaction of an admin order starts in open. The others are listed so that a
     * webhook still lands when the merchant moved the transaction by hand in between.
     */
    public const ENTRY_STATES = [
        self::STATE_PAYMENT_REVIEW => ['open', 'in_progress', 'unconfirmed', 'reminded'],
        self::STATE_PENDING_BUYER_CONFIRMATION => ['open', 'in_progress', 'unconfirmed', 'reminded', self::STATE_PAYMENT_REVIEW],
    ];

    /**
     * Ways out of each new state: the same ones Shopware offers from unconfirmed.
     *
     * action name => target state
     */
    public const EXIT_TRANSITIONS = [
        'authorize' => 'authorized',
        'paid' => 'paid',
        'paid_partially' => 'paid_partially',
        'cancel' => 'cancelled',
        'fail' => 'failed',
        'reopen' => 'open',
    ];

    /**
     * State names per admin locale.
     */
    public const STATE_NAMES = [
        self::STATE_PAYMENT_REVIEW => [
            'en-GB' => 'Payment review (Mondu)',
            'de-DE' => 'Zahlungsprüfung (Mondu)',
            'nl-NL' => 'Betalingscontrole (Mondu)',
            'fr-FR' => 'Vérification du paiement (Mondu)',
        ],
        self::STATE_PENDING_BUYER_CONFIRMATION => [
            'en-GB' => 'Pending buyer confirmation (Mondu)',
            'de-DE' => 'Wartet auf Käuferbestätigung (Mondu)',
            'nl-NL' => 'Wacht op bevestiging koper (Mondu)',
            'fr-FR' => 'En attente de confirmation de l\'acheteur (Mondu)',
        ],
    ];

    private function __construct()
    {
    }
}
