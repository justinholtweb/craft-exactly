<?php

namespace justinholtweb\exactly\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\exactly\Plugin;
use yii\web\Response;

/**
 * The Ajax actions behind the settings screen.
 *
 * All of them are posted with `Craft.sendActionRequest` rather than submitted, because the plugin
 * settings screen is already inside Craft's own `<form>`: a nested form is invalid HTML, the
 * parser keeps the children and drops the tag, and the hidden `action` input ends up in the page
 * form next to Craft's own. With two of them Craft takes the last, so "Test connection" would run
 * on Save.
 */
class SettingsController extends Controller
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
        $this->requireAdmin(false);

        return true;
    }

    /**
     * Prove the connection works, and report what it is connected to.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();

        $status = Plugin::getInstance()->getDivisions()->getStatus();

        if (!$status['connected']) {
            return $this->asFailure($status['message']);
        }

        $me = $status['me'];

        return $this->asJson([
            'success' => true,
            'message' => $status['message'],
            'user' => $me['FullName'] ?? '',
            'email' => $me['Email'] ?? '',
            'division' => $me['CurrentDivision'] ?? null,
            'divisionName' => $me['DivisionCustomerName'] ?? '',
            'serverTime' => $me['ServerTime'] ?? null,
            'daysLeft' => $status['daysLeft'],
        ]);
    }

    /**
     * The administrations this connection can write to.
     */
    public function actionDivisions(): Response
    {
        $this->requirePostRequest();

        return $this->asJson([
            'success' => true,
            'divisions' => Plugin::getInstance()->getDivisions()->getDivisions(),
            'current' => Plugin::getInstance()->getOauth()->getDivision(),
        ]);
    }

    /**
     * VAT codes, GL accounts and payment conditions, refreshed from Exact.
     */
    public function actionMetadata(): Response
    {
        $this->requirePostRequest();

        $refresh = (bool)Craft::$app->getRequest()->getBodyParam('refresh', false);
        $plugin = Plugin::getInstance();

        return $this->asJson([
            'success' => true,
            'vatCodes' => $plugin->getVat()->getExactVatCodes($refresh),
            'glAccounts' => $plugin->getLedger()->getAccountLabels($refresh),
            'paymentConditions' => $plugin->getLedger()->getPaymentConditions($refresh),
        ]);
    }

    /**
     * Drop every cached lookup for the current division.
     */
    public function actionClearCache(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $division = $plugin->getOauth()->getDivision();

        $plugin->getLedger()->clearCache($division);
        $accounts = $plugin->getAccounts()->clearCache($division);
        $items = $plugin->getItems()->clearCache($division);

        return $this->asSuccess(Craft::t('exactly', 'Cleared {accounts} cached accounts and {items} cached items.', [
            'accounts' => $accounts,
            'items' => $items,
        ]));
    }

    /**
     * Check a VAT number against VIES from the settings screen, so a merchant can see the feature
     * work before trusting an order to it.
     */
    public function actionCheckVatNumber(): Response
    {
        $this->requirePostRequest();

        $number = (string)Craft::$app->getRequest()->getRequiredBodyParam('vatNumber');
        $result = Plugin::getInstance()->getVat()->validateWithVies($number);

        return $this->asJson([
            'success' => true,
            'valid' => $result,
            'message' => match ($result) {
                true => Craft::t('exactly', 'VIES says this number is registered.'),
                false => Craft::t('exactly', 'VIES says this number is not registered.'),
                default => Craft::t('exactly', 'VIES could not be reached.'),
            },
        ]);
    }
}
