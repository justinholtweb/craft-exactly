<?php
/**
 * Payment entries: Commerce payments and refunds posted to Exact as bank/cash entries matched to
 * their invoice or credit note.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-exactly/tests/integration/payments.php
 *
 * Exact is a Guzzle MockHandler (see _support.php); the request, retry, logging and claim code is
 * the real thing.
 */

require __DIR__ . '/_support.php';

use craft\commerce\records\Transaction as TransactionRecord;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\errors\PaymentEntryException;
use justinholtweb\exactly\errors\RateLimitException;
use justinholtweb\exactly\events\PaymentEntryEvent;
use justinholtweb\exactly\events\ProcessorFeeEvent;
use justinholtweb\exactly\jobs\RegisterPayment;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\services\PaymentEntries;
use yii\base\Event;

$entries = $plugin->getPaymentEntries();
$on = [
    'registerPayments' => true,
    'paymentJournalCode' => '20',
    'paymentReceivablesGlAccountCode' => '1300',
    'paymentEntryType' => 'bank',
    'paymentAmountSign' => 'positive',
    'registerRefunds' => true,
    'recordProcessorFees' => false,
    'paymentFeeGlAccountCode' => '',
    'paymentJournalByGateway' => [],
    'retryDelayMinutes' => 0,
    'maxAttempts' => 5,
];

makeConnection();
primeLedger();
exact([]);

$product = makeProduct("EXP-$suffix", 121.00);
$variant = $product->getDefaultVariant();

/** A paid order with a processed invoice, and its capture — saved under a fake queue. */
$paidOrder = function(float $amount = 121.00, array $documentValues = [], mixed $response = null) use ($variant): array {
    return withFakeQueue(function() use ($variant, $amount, $documentValues, $response) {
        $order = makeOrder($variant);
        $document = $documentValues === ['none' => true] ? null : makeDocument($order, $documentValues);
        $transaction = makeTransaction($order, TransactionRecord::TYPE_CAPTURE, $amount, TransactionRecord::STATUS_SUCCESS, $response);

        return [$order, $document, $transaction];
    });
};

$posted = fn(array $body = ['EntryID' => 'eeeeeeee-0000-0000-0000-000000000001', 'EntryNumber' => 26000001]) => exactJson($body, 201);

// =====================================================================
section('Which transactions');

[$orderA, $invoiceA, $captureA] = $paidOrder();

check('a successful capture is a payment', fn() => $entries->kindOf($captureA) === PaymentEntries::KIND_PAYMENT);

check('a successful purchase is a payment, a refund a refund, an authorize nothing', function() use ($entries, $captureA) {
    $purchase = clone $captureA;
    $purchase->type = TransactionRecord::TYPE_PURCHASE;
    $refund = clone $captureA;
    $refund->type = TransactionRecord::TYPE_REFUND;
    $authorize = clone $captureA;
    $authorize->type = TransactionRecord::TYPE_AUTHORIZE;

    return $entries->kindOf($purchase) === PaymentEntries::KIND_PAYMENT
        && $entries->kindOf($refund) === PaymentEntries::KIND_REFUND
        && $entries->kindOf($authorize) === null;
});

check('a failed capture is not money', function() use ($entries, $captureA) {
    $failedCapture = clone $captureA;
    $failedCapture->status = TransactionRecord::STATUS_FAILED;

    return $entries->kindOf($failedCapture) === null;
});

check('with the setting off, saving a capture queues nothing', function() use ($variant) {
    return withFakeQueue(function(FakeQueue $queue) use ($variant) {
        $order = makeOrder($variant);
        makeTransaction($order, TransactionRecord::TYPE_CAPTURE, 121.00);

        return $queue->jobsOf(RegisterPayment::class) === [] ?: count($queue->jobsOf(RegisterPayment::class)) . ' jobs';
    });
});

check('with the setting on, saving a capture queues exactly one RegisterPayment for it', function() use ($variant, $on) {
    return withSettings($on, fn() => withFakeQueue(function(FakeQueue $queue) use ($variant) {
        $order = makeOrder($variant);
        $transaction = makeTransaction($order, TransactionRecord::TYPE_CAPTURE, 121.00);
        $jobs = $queue->jobsOf(RegisterPayment::class);

        return count($jobs) === 1 && $jobs[0]['job']->transactionId === (int)$transaction->id
            ?: count($jobs) . ' jobs';
    }));
});

check('an authorize, and a refund with refunds off, queue nothing', function() use ($variant, $on) {
    return withSettings(['registerRefunds' => false] + $on, fn() => withFakeQueue(function(FakeQueue $queue) use ($variant) {
        $order = makeOrder($variant);
        makeTransaction($order, TransactionRecord::TYPE_AUTHORIZE, 121.00);
        makeTransaction($order, TransactionRecord::TYPE_REFUND, 121.00);

        return $queue->jobsOf(RegisterPayment::class) === [] ?: count($queue->jobsOf(RegisterPayment::class)) . ' jobs';
    }));
});

check('queueing a transaction is refused, not thrown, without a connection', function() use ($entries, $captureA, $on, $plugin) {
    return withSettings($on, fn() => withFakeQueue(function(FakeQueue $queue) use ($entries, $captureA, $plugin) {
        Craft::$app->getDb()->createCommand()->update(Table::CONNECTIONS, ['refreshToken' => null, 'accessToken' => null], ['connectionKey' => $plugin->getSettings()->getConnectionKey()])->execute();
        $plugin->getOauth()->getConnection(true);

        try {
            return $entries->queueTransaction($captureA) === false && $queue->pushed === [];
        } finally {
            makeConnection();
        }
    }));
});

// =====================================================================
section('The payload (dry run)');

check('a payment becomes one BankEntries line on the receivables account, matched by account and invoice number', function() use ($entries, $captureA, $invoiceA, $on) {
    return withSettings($on, function() use ($entries, $captureA, $invoiceA) {
        $built = $entries->buildPayload($captureA);
        $line = $built['payload']['BankEntryLines'][0] ?? [];

        $expect = [
            $built['endpoint'] === PaymentEntries::ENDPOINT_BANK,
            $built['payload']['JournalCode'] === '20',
            $built['payload']['Currency'] === strtoupper((string)$captureA->currency),
            count($built['payload']['BankEntryLines']) === 1,
            $line['GLAccount'] === 'aa001300-0000-0000-0000-000000000000',
            $line['Account'] === $invoiceA->exactAccountId,
            $line['OurRef'] === (int)$invoiceA->invoiceNumber,
            $line['AmountFC'] === 121.0,
            str_contains($line['Description'], (string)($captureA->getOrder()->reference ?: $captureA->getOrder()->getShortNumber())),
        ];

        return !in_array(false, $expect, true) ?: json_encode([$built['payload'], $expect]);
    });
});

check('OurRef goes as an integer, not the string the invoice number is stored as', function() use ($entries, $captureA, $on) {
    return withSettings($on, fn() => is_int($entries->buildPayload($captureA)['payload']['BankEntryLines'][0]['OurRef']));
});

check('the line date is a zone-less day in the site time zone', function() use ($entries, $captureA, $on) {
    return withSettings($on, function() use ($entries, $captureA) {
        $date = $entries->buildPayload($captureA)['payload']['BankEntryLines'][0]['Date'];
        $expected = PaymentEntries::localDate($captureA)->format('Y-m-d') . 'T00:00:00';

        return $date === $expected ?: "got $date, expected $expected";
    });
});

check('a gateway with its own journal uses it instead of the default', function() use ($entries, $captureA, $on) {
    return withSettings(['paymentJournalByGateway' => ['dummy' => 'STR']] + $on, fn() => $entries->buildPayload($captureA)['payload']['JournalCode'] === 'STR');
});

check('cash entry type posts CashEntries with CashEntryLines', function() use ($entries, $captureA, $on) {
    return withSettings(['paymentEntryType' => 'cash'] + $on, function() use ($entries, $captureA) {
        $built = $entries->buildPayload($captureA);

        return $built['endpoint'] === PaymentEntries::ENDPOINT_CASH
            && isset($built['payload']['CashEntryLines'][0])
            && !isset($built['payload']['BankEntryLines']);
    });
});

check('the sign setting flips money received', function() use ($entries, $captureA, $on) {
    return withSettings(['paymentAmountSign' => 'negative'] + $on, fn() => $entries->buildPayload($captureA)['payload']['BankEntryLines'][0]['AmountFC'] === -121.0);
});

check('no journal refuses with a message naming the gateway', function() use ($entries, $captureA, $on) {
    try {
        withSettings(['paymentJournalCode' => ''] + $on, fn() => $entries->buildPayload($captureA));
    } catch (PaymentEntryException $e) {
        return str_contains($e->getMessage(), '“dummy”') ?: $e->getMessage();
    }

    return 'no exception';
});

check('no receivables account refuses rather than guessing one', function() use ($entries, $captureA, $on) {
    try {
        withSettings(['paymentReceivablesGlAccountCode' => ''] + $on, fn() => $entries->buildPayload($captureA));
    } catch (PaymentEntryException) {
        return true;
    }

    return 'no exception';
});

check('a receivables code missing from the chart is refused after one refresh of the chart', function() use ($entries, $captureA, $on) {
    exact([exactList([['ID' => 'aa001300-0000-0000-0000-000000000000', 'Code' => '1300', 'Description' => 'Debiteuren']])]);

    try {
        withSettings(['paymentReceivablesGlAccountCode' => '1399'] + $on, fn() => $entries->buildPayload($captureA));
    } catch (PaymentEntryException $e) {
        $calls = exactCalls();
        primeLedger();

        return count($calls) === 1 && str_contains($calls[0]['path'], 'financial/GLAccounts') && str_contains($e->getMessage(), '1399')
            ?: json_encode($calls);
    }

    primeLedger();

    return 'no exception';
});

check('a payment in another currency than the invoice is refused', function() use ($entries, $captureA, $invoiceA, $on) {
    Craft::$app->getDb()->createCommand()->update(Table::DOCUMENTS, ['currency' => 'GBP'], ['id' => $invoiceA->id])->execute();

    try {
        withSettings($on, fn() => $entries->buildPayload($captureA));
    } catch (PaymentEntryException $e) {
        return str_contains($e->getMessage(), 'GBP') ?: $e->getMessage();
    } finally {
        Craft::$app->getDb()->createCommand()->update(Table::DOCUMENTS, ['currency' => $invoiceA->currency], ['id' => $invoiceA->id])->execute();
    }

    return 'no exception';
});

check('the preview of a payment with no invoice yet has no OurRef, says why, and calls nothing', function() use ($entries, $paidOrder, $on) {
    [, , $capture] = $paidOrder(50.00, ['none' => true]);
    exact([]);

    return withSettings($on, function() use ($entries, $capture) {
        $preview = $entries->preview($capture);
        $line = $preview['payload']['BankEntryLines'][0] ?? [];

        return $preview['error'] === null && !isset($line['OurRef']) && $preview['warnings'] !== [] && exactCalls() === []
            ?: json_encode($preview);
    });
});

// =====================================================================
section('Processor fees');

$stripeResponse = json_encode(['id' => 'pi_x', 'latest_charge' => ['balance_transaction' => ['fee' => 381, 'currency' => strtolower((string)$orderA->currency)]]]);
[$orderFee, , $captureFee] = $paidOrder(121.00, [], $stripeResponse);

check('a Stripe balance-transaction fee becomes a second line on the fee account, the opposite sign', function() use ($entries, $captureFee, $on) {
    return withSettings(['recordProcessorFees' => true, 'paymentFeeGlAccountCode' => '4900'] + $on, function() use ($entries, $captureFee) {
        $built = $entries->buildPayload($captureFee);
        $lines = $built['payload']['BankEntryLines'];

        return count($lines) === 2
            && $lines[1]['GLAccount'] === 'aa004900-0000-0000-0000-000000000000'
            && $lines[1]['AmountFC'] === -3.81
            && $built['fee'] === 3.81
            ?: json_encode($lines);
    });
});

check('a fee with no fee account is left off with a warning, never booked to receivables', function() use ($entries, $captureFee, $on) {
    return withSettings(['recordProcessorFees' => true, 'paymentFeeGlAccountCode' => ''] + $on, function() use ($entries, $captureFee) {
        $built = $entries->buildPayload($captureFee);

        return count($built['payload']['BankEntryLines']) === 1 && $built['warnings'] !== [] && $built['fee'] === null;
    });
});

check('fees are off by default', function() use ($entries, $captureFee, $on) {
    return withSettings($on, fn() => count($entries->buildPayload($captureFee)['payload']['BankEntryLines']) === 1);
});

check('a fee in another currency is ignored rather than converted', function() use ($entries) {
    return $entries->feeFromResponse(['balance_transaction' => ['fee' => 300, 'currency' => 'usd']], 'EUR') === null;
});

check('PayPal Orders v2 and NVP fees are read', function() use ($entries) {
    $v2 = ['purchase_units' => [['payments' => ['captures' => [['seller_receivable_breakdown' => ['paypal_fee' => ['value' => '1.25', 'currency_code' => 'EUR']]]]]]]];

    return $entries->feeFromResponse($v2, 'EUR') === 1.25
        && $entries->feeFromResponse(['FEEAMT' => '0.99', 'CURRENCYCODE' => 'EUR'], 'EUR') === 0.99;
});

check('a fee handler can supply a fee the response does not carry', function() use ($entries, $captureA) {
    $handler = static function(ProcessorFeeEvent $event) {
        $event->fee = 2.5;
    };
    Event::on(PaymentEntries::class, PaymentEntries::EVENT_DEFINE_PROCESSOR_FEE, $handler);

    try {
        return $entries->processorFee($captureA) === 2.5;
    } finally {
        Event::off(PaymentEntries::class, PaymentEntries::EVENT_DEFINE_PROCESSOR_FEE, $handler);
    }
});

check('a "fee" not less than the payment is a misread and ignored', function() use ($entries, $captureA) {
    $huge = clone $captureA;
    $huge->response = json_encode(['balance_transaction' => ['fee' => 99999, 'currency' => strtolower((string)$captureA->currency)]]);

    return $entries->processorFee($huge) === null;
});

// =====================================================================
section('Entering a payment (scripted Exact)');

check('register posts the previewed body, once, to the division’s BankEntries, and records the entry', function() use ($entries, $captureA, $on, $posted) {
    return withSettings($on, function() use ($entries, $captureA, $posted) {
        $preview = $entries->preview($captureA)['payload'];
        exact([$posted()]);
        $result = $entries->register($captureA);
        $calls = exactCalls();
        $entry = $result['entry'];

        $expect = [
            $result['status'] === PaymentEntries::STATUS_SENT,
            count($calls) === 1,
            $calls[0]['method'] === 'POST',
            str_ends_with($calls[0]['path'], '/api/v1/' . TEST_DIVISION . '/financialtransaction/BankEntries'),
            $calls[0]['body'] == $preview,
            $entry?->exactEntryId === 'eeeeeeee-0000-0000-0000-000000000001',
            $entry?->entryNumber === '26000001',
            $entry?->invoiceNumber !== null,
            $entry?->attempts === 1,
        ];

        return !in_array(false, $expect, true) ?: json_encode([$expect, $result['message'], $calls]);
    });
});

check('registering it again posts nothing and says it is already entered', function() use ($entries, $captureA, $on) {
    return withSettings($on, function() use ($entries, $captureA) {
        exact([]);
        $result = $entries->register($captureA);

        return $result['status'] === PaymentEntries::STATUS_SKIPPED && exactCalls() === [] ?: json_encode($result['message']);
    });
});

check('the unique index refuses a second row for the same transaction and division', function() use ($captureA) {
    try {
        Craft::$app->getDb()->createCommand()->insert(Table::PAYMENTS, [
            'transactionId' => $captureA->id,
            'orderId' => $captureA->orderId,
            'division' => TEST_DIVISION,
            'kind' => 'payment',
            'status' => 'pending',
            'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();
    } catch (yii\db\IntegrityException) {
        return true;
    }

    return 'inserted a duplicate';
});

check('a fresh `sending` claim is respected: a second claimant gets nothing', function() use ($entries, $paidOrder, $on) {
    [$order, , $capture] = $paidOrder();

    return withSettings($on, function() use ($entries, $order, $capture) {
        $first = $entries->claim($capture, $order, TEST_DIVISION, PaymentEntries::KIND_PAYMENT);
        $second = $entries->claim($capture, $order, TEST_DIVISION, PaymentEntries::KIND_PAYMENT);

        return $first['claimed'] === true && $second['claimed'] === false ?: json_encode([$first['claimed'], $second['claimed']]);
    });
});

check('a payment made before its invoice exists waits, posts nothing and spends no attempt', function() use ($entries, $paidOrder, $on) {
    [$order, , $capture] = $paidOrder(80.00, ['none' => true]);
    $GLOBALS['waitingOrder'] = $order;
    $GLOBALS['waitingCapture'] = $capture;

    return withSettings($on, function() use ($entries, $capture) {
        exact([]);
        $result = $entries->register($capture);

        return $result['status'] === PaymentEntries::STATUS_WAITING && exactCalls() === [] && $result['entry']?->attempts === 0
            ?: json_encode([$result['status'], $result['entry']?->attempts]);
    });
});

check('once the invoice is sent, queueForOrder queues the waiting payment, and it goes through', function() use ($entries, $on, $posted) {
    $order = $GLOBALS['waitingOrder'];
    $capture = $GLOBALS['waitingCapture'];
    makeDocument($order);

    return withSettings($on, function() use ($entries, $order, $capture, $posted) {
        $queued = withFakeQueue(function(FakeQueue $queue) use ($entries, $order) {
            $entries->queueForOrder((int)$order->id);

            return $queue->jobsOf(RegisterPayment::class);
        });

        exact([$posted()]);
        $result = $entries->register($capture);

        return count($queued) === 1 && $result['status'] === PaymentEntries::STATUS_SENT ?: json_encode([count($queued), $result['message']]);
    });
});

check('a draft invoice is re-read from Exact; still a draft, the payment waits', function() use ($entries, $paidOrder, $on) {
    [$order, $invoice, $capture] = $paidOrder(60.00, ['exactStatus' => Document::EXACT_STATUS_DRAFT]);
    $GLOBALS['draft'] = [$order, $invoice, $capture];

    return withSettings($on, function() use ($entries, $invoice, $capture) {
        exact([exactList([['InvoiceID' => $invoice->exactInvoiceId, 'Status' => 10]])]);
        $result = $entries->register($capture);
        $calls = exactCalls();

        return $result['status'] === PaymentEntries::STATUS_WAITING
            && count($calls) === 1
            && str_contains($calls[0]['path'], 'salesinvoice/SalesInvoices')
            ?: json_encode([$result['status'], $calls]);
    });
});

check('processed in Exact since, the same payment is entered on the next run', function() use ($entries, $on, $posted) {
    [, $invoice, $capture] = $GLOBALS['draft'];

    return withSettings($on, function() use ($entries, $invoice, $capture, $posted) {
        exact([exactList([['InvoiceID' => $invoice->exactInvoiceId, 'Status' => 50]]), $posted()]);
        $result = $entries->register($capture);

        return $result['status'] === PaymentEntries::STATUS_SENT && count(exactCalls()) === 2 ?: json_encode([$result['message'], exactCalls()]);
    });
});

check('a 500 from Exact fails the row, retryably', function() use ($entries, $paidOrder, $on) {
    [, , $capture] = $paidOrder(33.00);
    $GLOBALS['lost'] = $capture;

    return withSettings($on, function() use ($entries, $capture) {
        exact([exactError(500, 'Internal error')]);
        $result = $entries->register($capture);

        return $result['status'] === PaymentEntries::STATUS_FAILED && $result['retryable'] === true ?: json_encode($result['message']);
    });
});

check('the queue job throws on a retryable failure, so the queue retries it', function() use ($on) {
    $capture = $GLOBALS['lost'];
    Craft::$app->getDb()->createCommand()->update(Table::PAYMENTS, ['dateLastAttempt' => null], ['transactionId' => $capture->id])->execute();

    return withSettings($on, function() use ($capture) {
        exact([exactList([]), exactError(503, 'Unavailable')]);

        try {
            (new RegisterPayment(['transactionId' => (int)$capture->id]))->execute(Craft::$app->getQueue());
        } catch (RuntimeException) {
            return true;
        }

        return 'no exception';
    });
});

check('a retry first looks for an entry the lost POST made, and finds it rather than posting twice', function() use ($entries, $on) {
    $capture = $GLOBALS['lost'];

    return withSettings($on, function() use ($entries, $capture) {
        exact([exactList([['ID' => 'ffffffff-0000-0000-0000-000000000009', 'EntryID' => 'eeeeeeee-0000-0000-0000-000000000099', 'EntryNumber' => 26000099, 'AmountFC' => 33.0]])]);
        $result = $entries->register($capture);
        $calls = exactCalls();
        $posts = array_filter($calls, static fn($c) => $c['method'] === 'POST');

        return $result['status'] === PaymentEntries::STATUS_SENT
            && $posts === []
            && str_contains($calls[0]['path'], 'financialtransaction/BankEntryLines')
            && str_contains($calls[0]['query'], "Description eq '")
            && $result['entry']?->entryNumber === '26000099'
            ?: json_encode([$result['message'], $calls]);
    });
});

check('a 400 refusal fails the row and is not retried by the queue', function() use ($entries, $paidOrder, $on) {
    [, , $capture] = $paidOrder(44.00);

    return withSettings($on, function() use ($entries, $capture) {
        exact([exactError(400, 'Journal 20 does not exist')]);
        $result = $entries->register($capture);

        return $result['status'] === PaymentEntries::STATUS_FAILED && $result['retryable'] === false && str_contains((string)$result['entry']?->lastError, 'Journal 20')
            ?: json_encode($result['message']);
    });
});

check('a rate limit is thrown, and the attempt it cost is given back', function() use ($entries, $paidOrder, $on) {
    [, , $capture] = $paidOrder(45.00);

    return withSettings($on, function() use ($entries, $capture) {
        exact([new GuzzleHttp\Psr7\Response(429, ['Retry-After' => '30'], '{}')]);

        try {
            $entries->register($capture);
        } catch (RateLimitException) {
            $entry = $entries->getEntry((int)$capture->id, TEST_DIVISION);

            return $entry?->status === PaymentEntries::STATUS_FAILED && $entry->attempts === 0 ?: json_encode([$entry?->status, $entry?->attempts]);
        }

        return 'no exception';
    });
});

check('a configuration gap fails without spending an attempt, and is not retried by the queue', function() use ($entries, $paidOrder, $on) {
    [, , $capture] = $paidOrder(46.00);

    return withSettings(['paymentJournalCode' => ''] + $on, function() use ($entries, $capture) {
        exact([]);
        $result = $entries->register($capture);

        return $result['status'] === PaymentEntries::STATUS_FAILED && $result['retryable'] === false && $result['entry']?->attempts === 0 && exactCalls() === []
            ?: json_encode([$result['status'], $result['entry']?->attempts]);
    });
});

check('a beforeRegister handler can veto: skipped with its reason, nothing posted', function() use ($entries, $paidOrder, $on) {
    [, , $capture] = $paidOrder(47.00);
    $handler = static function(PaymentEntryEvent $event) {
        $event->isValid = false;
        $event->message = 'Booked by the PSP import';
    };
    Event::on(PaymentEntries::class, PaymentEntries::EVENT_BEFORE_REGISTER, $handler);

    try {
        return withSettings($on, function() use ($entries, $capture) {
            exact([]);
            $result = $entries->register($capture);

            return $result['status'] === PaymentEntries::STATUS_SKIPPED && $result['message'] === 'Booked by the PSP import' && exactCalls() === [];
        });
    } finally {
        Event::off(PaymentEntries::class, PaymentEntries::EVENT_BEFORE_REGISTER, $handler);
    }
});

check('retryUnsent picks up failed rows with attempts left, and waiting rows', function() use ($entries, $paidOrder, $on, $posted) {
    [, , $capture] = $paidOrder(48.00);

    return withSettings($on, function() use ($entries, $capture, $posted) {
        exact([exactError(502, 'Bad gateway')]);
        $entries->register($capture);

        // Everything else in the fixture division that is failed or waiting is retried too, so
        // the scripted Exact answers whatever comes: lookups find nothing, posts succeed.
        $handler = static function(Psr\Http\Message\RequestInterface $request) {
            return str_contains($request->getUri()->getPath(), 'BankEntryLines')
                ? GuzzleHttp\Promise\Create::promiseFor(exactList([]))
                : GuzzleHttp\Promise\Create::promiseFor(exactJson(['EntryID' => 'eeeeeeee-0000-0000-0000-0000000000' . random_int(10, 99), 'EntryNumber' => 1], 201));
        };
        exact(array_fill(0, 40, $handler));
        $result = $entries->retryUnsent();

        return $entries->getEntry((int)$capture->id, TEST_DIVISION)?->status === PaymentEntries::STATUS_SENT && $result['attempted'] >= 1
            ?: json_encode($result);
    });
});

// =====================================================================
section('Refunds');

check('a refund with no credit note to match is skipped with the reason', function() use ($entries, $paidOrder, $on) {
    [$order] = $paidOrder(121.00);

    return withSettings($on, function() use ($entries, $order) {
        $refund = withFakeQueue(fn() => makeTransaction($order, TransactionRecord::TYPE_REFUND, 121.00));
        exact([]);
        $result = $entries->register($refund);

        return $result['status'] === PaymentEntries::STATUS_SKIPPED && exactCalls() === [] && str_contains((string)$result['message'], 'credit note')
            ?: json_encode($result['message']);
    });
});

check('a refund whose credit note is queued waits for it', function() use ($entries, $paidOrder, $on) {
    [$order] = $paidOrder(121.00);
    makeDocument($order, ['kind' => Document::KIND_CREDIT_NOTE, 'status' => Document::STATUS_QUEUED, 'exactInvoiceId' => null, 'invoiceNumber' => null]);

    return withSettings($on, function() use ($entries, $order) {
        $refund = withFakeQueue(fn() => makeTransaction($order, TransactionRecord::TYPE_REFUND, 121.00));
        exact([]);

        return $entries->register($refund)['status'] === PaymentEntries::STATUS_WAITING;
    });
});

check('a refund is matched to the credit note, money out', function() use ($entries, $paidOrder, $on, $posted) {
    [$order] = $paidOrder(121.00);
    $credit = makeDocument($order, ['kind' => Document::KIND_CREDIT_NOTE]);

    return withSettings($on, function() use ($entries, $order, $credit, $posted) {
        $refund = withFakeQueue(fn() => makeTransaction($order, TransactionRecord::TYPE_REFUND, 121.00));
        exact([$posted()]);
        $result = $entries->register($refund);
        $line = exactCalls()[0]['body']['BankEntryLines'][0] ?? [];

        return $result['status'] === PaymentEntries::STATUS_SENT
            && $line['OurRef'] === (int)$credit->invoiceNumber
            && (float)$line['AmountFC'] === -121.0
            && str_starts_with($line['Description'], 'Refund ')
            && $result['entry']?->kind === PaymentEntries::KIND_REFUND
            ?: json_encode($line);
    });
});

// =====================================================================
section('Reconciliation per day');

check('today’s row counts a new capture as missing, then as entered once it is in Exact', function() use ($entries, $paidOrder, $on, $posted) {
    $today = (new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');
    $row = fn() => array_values(array_filter($entries->reconciliation(2), static fn($r) => $r['date'] === $today))[0] ?? null;

    return withSettings($on, function() use ($row, $paidOrder, $entries, $posted) {
        $before = $row();
        [, , $capture] = $paidOrder(17.25);
        $middle = $row();

        exact([$posted()]);
        $entries->register($capture);
        $after = $row();

        $expect = [
            round($middle['captured'] - $before['captured'], 2) === 17.25,
            $middle['missing'] - $before['missing'] === 1,
            round($after['entered'] - $middle['entered'], 2) === 17.25,
            $after['missing'] === $before['missing'],
        ];

        return !in_array(false, $expect, true) ?: json_encode([$before, $middle, $after]);
    });
});

check('days are bounded and come newest first', function() use ($entries) {
    $rows = $entries->reconciliation(3);

    return count($rows) === 3 && $rows[0]['date'] > $rows[2]['date'];
});

check('the order panel lists the order’s payments, with the Enter payments button', function() use ($plugin, $orderA, $on) {
    return withSettings($on, function() use ($plugin, $orderA) {
        Craft::$app->getUser()->setIdentity(craft\elements\User::find()->admin()->one());
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

        try {
            $html = $view->renderTemplate('exactly/_order-panel', [
                'order' => $orderA,
                'documents' => $plugin->getDocuments()->getDocumentsForOrder((int)$orderA->id),
                'paymentEntries' => $plugin->getPaymentEntries()->getEntriesForOrder((int)$orderA->id),
                'registerPayments' => true,
                'connected' => true,
                'canPush' => true,
                'canCredit' => false,
            ]);
        } finally {
            $view->setTemplateMode($mode);
            Craft::$app->getUser()->setIdentity(null);
        }

        return str_contains($html, 'Payments in Exact') && str_contains($html, 'exactly-register-payments') && str_contains($html, 'Entered')
            ?: substr(strip_tags($html), 0, 300);
    });
});

// =====================================================================
section('Console');

check('exactly/payments/preview prints a payload and exits 0; an unknown transaction is a usage error', function() use ($captureA, $on) {
    return withSettings($on, function() use ($captureA) {
        $controller = new justinholtweb\exactly\console\controllers\PaymentsController('payments', Craft::$app);
        $controller->transaction = (int)$captureA->id;
        ob_start();
        $ok = $controller->actionPreview();
        ob_end_clean();
        $controller->transaction = 999999999;
        $missing = $controller->actionPreview();

        return $ok === 0 && $missing === yii\console\ExitCode::USAGE ?: "$ok / $missing";
    });
});

check('exactly/payments/reconcile exits 0 with gaps, as cron wants', function() {
    $controller = new justinholtweb\exactly\console\controllers\PaymentsController('payments', Craft::$app);
    $controller->days = 2;

    return $controller->actionReconcile() === 0;
});

check('exactly/payments/register with entering switched off is a config error, not a post', function() use ($orderA) {
    exact([]);
    $controller = new justinholtweb\exactly\console\controllers\PaymentsController('payments', Craft::$app);
    $controller->order = (int)$orderA->id;

    return $controller->actionRegister() === yii\console\ExitCode::CONFIG && exactCalls() === [];
});

// =====================================================================
section('Security (over HTTP)');

[$viewer, $viewerPassword] = makeUser('viewer', ['accessCp', 'accessPlugin-exactly', 'exactly-viewDocuments', 'commerce-manageOrders', 'accessPlugin-commerce']);
[$pusher, $pusherPassword] = makeUser('pusher', ['accessCp', 'accessPlugin-exactly', 'exactly-viewDocuments', 'exactly-pushOrders', 'commerce-manageOrders', 'accessPlugin-commerce']);
[$guestAction, $guestCp] = client(null, null);
[$viewerAction, $viewerCp] = client($viewer->username, $viewerPassword);
[$pusherAction, $pusherCp] = client($pusher->username, $pusherPassword);

check('a guest cannot enter payments', function() use ($guestAction, $orderA) {
    $status = $guestAction('exactly/payments/register', ['orderId' => $orderA->id])->getStatusCode();

    return in_array($status, [302, 401, 403], true) ?: "HTTP $status";
});

check('a user without “Send orders to Exact Online” gets 403 from register and retry', function() use ($viewerAction, $orderA) {
    $a = $viewerAction('exactly/payments/register', ['orderId' => $orderA->id])->getStatusCode();
    $b = $viewerAction('exactly/payments/retry')->getStatusCode();

    return $a === 403 && $b === 403 ?: "HTTP $a / $b";
});

check('register refuses GET, and a POST without a CSRF token', function() use ($pusherAction, $orderA) {
    $get = $pusherAction('exactly/payments/register', ['orderId' => $orderA->id], 'GET')->getStatusCode();
    $noCsrf = $pusherAction('exactly/payments/register', ['orderId' => $orderA->id], 'POST', false)->getStatusCode();

    return in_array($get, [400, 405], true) && $noCsrf === 400 ?: "GET $get / no CSRF $noCsrf";
});

check('a permitted user reaches register (the web process has entering switched off, so it says so)', function() use ($pusherAction, $orderA) {
    $response = $pusherAction('exactly/payments/register', ['orderId' => $orderA->id]);
    $body = json_decode((string)$response->getBody(), true);

    return in_array($response->getStatusCode(), [200, 400], true) && isset($body['message']) ?: 'HTTP ' . $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 200);
});

check('the Payments screen and a preview render for a viewer', function() use ($viewerCp, $captureA) {
    $index = $viewerCp('exactly/payments');
    $preview = $viewerCp('exactly/payments/preview/' . $captureA->id);

    return $index->getStatusCode() === 200
        && str_contains((string)$index->getBody(), 'exactly-reconciliation')
        && $preview->getStatusCode() === 200
        && str_contains((string)$preview->getBody(), 'Payment entry preview')
        ?: 'HTTP ' . $index->getStatusCode() . ' / ' . $preview->getStatusCode();
});

check('a user without Commerce order access cannot open the Payments screen', function() {
    [$limited, $password] = makeUser('limited', ['accessCp', 'accessPlugin-exactly', 'exactly-viewDocuments']);
    [, $cp] = client($limited->username, $password);
    $status = $cp('exactly/payments')->getStatusCode();

    return $status === 403 ?: "HTTP $status";
});
