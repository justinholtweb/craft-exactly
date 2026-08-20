<?php

namespace justinholtweb\exactly\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\exactly\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log in the control panel.
 *
 * Pro only. Lite still writes a short tail of entries so a support question can be answered from
 * the database, it just has no screen for them.
 */
class LogController extends Controller
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
        $this->requirePermission('exactly-viewLog');

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException('The connection log requires Exactly Pro.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $log = Plugin::getInstance()->getLog();

        // `action` is Craft's own routing parameter, so the filter cannot be named that: a select
        // called `action` overwrites `action=exactly/log/index` with its own value and the request
        // 404s on a route nobody wrote, before any controller runs.
        $criteria = [
            'action' => $request->getParam('logAction'),
            'level' => $request->getParam('level'),
        ];

        return $this->renderTemplate('exactly/log/_index', [
            'entries' => $log->getEntries($criteria, 200),
            'criteria' => $criteria,
            'actions' => $log->getActions(),
            'total' => $log->count(),
            'errors' => $log->count(['level' => 'error']),
        ]);
    }

    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->getEntryById($entryId);

        if ($entry === null) {
            throw new NotFoundHttpException('Log entry not found');
        }

        return $this->renderTemplate('exactly/log/_detail', [
            'entry' => $entry,
        ]);
    }

    public function actionPrune(): Response
    {
        $this->requirePostRequest();

        $days = (int)Craft::$app->getRequest()->getBodyParam('days', 0);
        $deleted = Plugin::getInstance()->getLog()->prune($days > 0 ? $days : null);

        return $this->asSuccess(Craft::t('exactly', 'Removed {count} log entries.', ['count' => $deleted]));
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $deleted = Plugin::getInstance()->getLog()->clear();

        return $this->asSuccess(Craft::t('exactly', 'Removed {count} log entries.', ['count' => $deleted]));
    }
}
