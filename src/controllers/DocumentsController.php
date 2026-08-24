<?php

namespace justinholtweb\exactly\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use DateTime;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Documents screen, and the actions behind the order-edit panel.
 */
class DocumentsController extends Controller
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

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $criteria = [
            'status' => $request->getParam('status'),
            'kind' => $request->getParam('kind'),
            'search' => $request->getParam('search'),
        ];

        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = 100;

        return $this->renderTemplate('exactly/documents/_index', [
            'documents' => $plugin->getDocuments()->find($criteria, $perPage, ($page - 1) * $perPage),
            'criteria' => $criteria,
            'total' => $plugin->getDocuments()->count($criteria),
            'counts' => $plugin->getDocuments()->getStatusCounts(),
            'summary' => $plugin->getSync()->getSummary(),
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function actionDetail(int $documentId): Response
    {
        $document = Plugin::getInstance()->getDocuments()->getDocumentById($documentId);

        if ($document === null) {
            throw new NotFoundHttpException('Document not found');
        }

        return $this->renderTemplate('exactly/documents/_detail', [
            'document' => $document,
            'order' => $document->getOrder(),
            'entries' => $document->orderId !== null
                ? Plugin::getInstance()->getLog()->getEntries(['orderId' => $document->orderId], 25)
                : [],
        ]);
    }

    /**
     * Show exactly what would be sent, without sending it.
     */
    public function actionPreview(int $orderId): Response
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Order not found');
        }

        $error = null;
        $built = null;

        try {
            $built = Plugin::getInstance()->getInvoices()->preview($order);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->renderTemplate('exactly/documents/_preview', [
            'order' => $order,
            'built' => $built,
            'error' => $error,
            'json' => $built !== null
                ? json_encode($built['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
        ]);
    }

    /**
     * Send one order.
     */
    public function actionPush(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('exactly-pushOrders');

        $orderId = (int)Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $force = (bool)Craft::$app->getRequest()->getBodyParam('force', false);

        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            return $this->asFailure(Craft::t('exactly', 'That order no longer exists.'));
        }

        $result = Plugin::getInstance()->getInvoices()->push($order, ['force' => $force]);

        if (!$result['success']) {
            return $this->asJson([
                'success' => false,
                'message' => $result['message'],
                'skipped' => $result['skipped'],
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => $result['message'],
            'warnings' => $result['warnings'],
            'documentId' => $result['document']?->id,
            'invoiceNumber' => $result['document']?->invoiceNumber,
        ]);
    }

    /**
     * Queue one order instead of pushing it inline. Useful for a big order on a slow connection.
     */
    public function actionQueue(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('exactly-pushOrders');

        $orderId = (int)Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            return $this->asFailure(Craft::t('exactly', 'That order no longer exists.'));
        }

        $result = Plugin::getInstance()->getSync()->schedule($order);

        return $this->asSuccess($result['message']);
    }

    /**
     * Issue a credit note against an already-invoiced order.
     */
    public function actionCredit(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('exactly-creditOrders');

        $orderId = (int)Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            return $this->asFailure(Craft::t('exactly', 'That order no longer exists.'));
        }

        $result = Plugin::getInstance()->getSync()->creditNote($order);

        return $result['success']
            ? $this->asSuccess($result['message'])
            : $this->asFailure($result['message']);
    }

    /**
     * Queue everything that has never been invoiced.
     */
    public function actionBackfill(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('exactly-pushOrders');

        $request = Craft::$app->getRequest();
        $limit = max(1, min(500, (int)$request->getBodyParam('limit', 100)));
        $sinceParam = (string)$request->getBodyParam('since', '');
        $since = null;

        if ($sinceParam !== '') {
            try {
                $since = new DateTime($sinceParam);
            } catch (\Throwable) {
                return $this->asFailure(Craft::t('exactly', 'That is not a date Exactly can read.'));
            }
        }

        $result = Plugin::getInstance()->getSync()->backfill($since, $limit);

        return $this->asSuccess(Craft::t('exactly', 'Queued {count} orders for Exact Online.', [
            'count' => $result['queued'],
        ]));
    }

    /**
     * Re-queue failed documents.
     */
    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('exactly-pushOrders');

        $result = Plugin::getInstance()->getSync()->retryFailed();

        return $this->asSuccess(Craft::t('exactly', 'Re-queued {count} documents.', ['count' => $result['queued']]));
    }

    /**
     * Reconcile payment status against Exact's open items.
     */
    public function actionSyncPayments(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('exactly-pushOrders');

        $result = Plugin::getInstance()->getPayments()->sync();

        if ($result['errors']) {
            return $this->asFailure(implode(' ', $result['errors']));
        }

        return $this->asSuccess(Craft::t('exactly', '{checked} checked — {paid} paid, {outstanding} still open.', [
            'checked' => $result['checked'],
            'paid' => $result['paid'],
            'outstanding' => $result['outstanding'],
        ]));
    }

    /**
     * Stop tracking a document. The Exact invoice is untouched — an issued invoice is not Craft's
     * to retract — and the confirmation copy says so.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('exactly-pushOrders');

        $documentId = (int)Craft::$app->getRequest()->getRequiredBodyParam('documentId');
        $document = Plugin::getInstance()->getDocuments()->getDocumentById($documentId);

        if ($document === null) {
            return $this->asFailure(Craft::t('exactly', 'That document is already gone.'));
        }

        Plugin::getInstance()->getDocuments()->delete($documentId);

        return $this->asSuccess($document->isSent()
            ? Craft::t('exactly', 'Stopped tracking {label}. The invoice itself is still in Exact Online.', ['label' => $document->getLabel()])
            : Craft::t('exactly', 'Removed the record.'));
    }

    /**
     * Mark a document as deliberately not to be invoiced.
     */
    public function actionSkip(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('exactly-pushOrders');

        $request = Craft::$app->getRequest();
        $documentId = (int)$request->getRequiredBodyParam('documentId');
        $reason = (string)$request->getBodyParam('reason', Craft::t('exactly', 'Skipped by an administrator.'));

        $document = Plugin::getInstance()->getDocuments()->getDocumentById($documentId);

        if ($document === null) {
            return $this->asFailure(Craft::t('exactly', 'That document no longer exists.'));
        }

        if ($document->isSent()) {
            return $this->asFailure(Craft::t('exactly', 'This order has already been invoiced; it cannot be skipped.'));
        }

        Plugin::getInstance()->getDocuments()->markSkipped($document, $reason);

        return $this->asSuccess(Craft::t('exactly', 'Marked as skipped.'));
    }
}
