<?php

namespace justinholtweb\exactly\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use justinholtweb\exactly\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Payments screen (Commerce's money against Exact's entries, per day), the payment-entry
 * preview, and the order panel's **Enter payments** button.
 */
class PaymentsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('exactly-viewDocuments');
        // Payments are an order's transactions; nothing here is wider than Commerce's own order
        // access.
        $this->requirePermission('commerce-manageOrders');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $days = max(1, min(366, (int)Craft::$app->getRequest()->getParam('days', 30)));

        return $this->renderTemplate('exactly/payments/_index', [
            'days' => $days,
            'rows' => $plugin->getPaymentEntries()->reconciliation($days),
            'unsent' => $plugin->getPaymentEntries()->getUnsent(100),
            'settings' => $plugin->getSettings(),
            'canPush' => Craft::$app->getUser()->checkPermission('exactly-pushOrders'),
        ]);
    }

    /**
     * Show exactly what would be entered for one transaction, without entering it.
     */
    public function actionPreview(int $transactionId): Response
    {
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionById($transactionId);
        $order = $transaction?->getOrder();

        if ($transaction === null || !$order instanceof Order) {
            throw new NotFoundHttpException('Transaction not found');
        }

        $preview = Plugin::getInstance()->getPaymentEntries()->preview($transaction);

        return $this->renderTemplate('exactly/payments/_preview', [
            'transaction' => $transaction,
            'order' => $order,
            'preview' => $preview,
            'json' => $preview['payload'] !== null
                ? json_encode($preview['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
        ]);
    }

    /**
     * Enter every payment and refund on one order that is not in Exact yet, now.
     */
    public function actionRegister(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('exactly-pushOrders');

        $orderId = (int)Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            return $this->asFailure(Craft::t('exactly', 'That order no longer exists.'));
        }

        if (!Plugin::getInstance()->getSettings()->registerPayments) {
            return $this->asFailure(Craft::t('exactly', 'Entering payments in Exact Online is switched off.'));
        }

        $result = Plugin::getInstance()->getPaymentEntries()->registerForOrder($order);
        $message = Craft::t('exactly', '{sent} entered, {waiting} waiting, {failed} failed, {skipped} already done or skipped.', [
            'sent' => $result['sent'],
            'waiting' => $result['waiting'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
        ]);

        return $result['failed'] > 0
            ? $this->asFailure($message . ' ' . implode(' ', array_unique($result['messages'])))
            : $this->asSuccess($message, ['result' => $result]);
    }

    /**
     * Re-run everything that is not in Exact yet — the Payments screen's button.
     */
    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('exactly-pushOrders');

        $result = Plugin::getInstance()->getPaymentEntries()->retryUnsent();

        return $this->asSuccess(Craft::t('exactly', '{attempted} tried: {sent} entered, {waiting} waiting, {failed} failed.', $result), ['result' => $result]);
    }
}
