<?php

namespace justinholtweb\exactly\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\exactly\Plugin;
use yii\web\Response;

/**
 * The OAuth round trip.
 *
 * These actions live on the *site* request rather than the CP because Exact redirects a browser to
 * the registered URI, and that URI has to be a plain, stable URL — Craft's CP action URLs carry
 * the CP trigger and would have to be re-registered in Exact if `cpTrigger` ever changed.
 *
 * The session cookie comes along for the ride, so `requireAdmin()` still does its job.
 */
class OauthController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        return true;
    }

    /**
     * Send the admin to Exact Online to authorise the app.
     */
    public function actionConnect(): Response
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->hasCredentials()) {
            Craft::$app->getSession()->setError(Craft::t('exactly', 'Add your Exact Online client ID and secret first, and save.'));

            return $this->redirect(UrlHelper::cpUrl('settings/plugins/exactly'));
        }

        $url = Plugin::getInstance()->getOauth()->getAuthorizationUrl(
            Plugin::getInstance()->getOauth()->generateState()
        );

        return $this->redirect($url);
    }

    /**
     * Where Exact Online sends the browser back to.
     */
    public function actionCallback(): Response
    {
        $request = Craft::$app->getRequest();
        $session = Craft::$app->getSession();
        $settingsUrl = UrlHelper::cpUrl('settings/plugins/exactly');

        $error = $request->getQueryParam('error');

        if ($error !== null) {
            $session->setError(Craft::t('exactly', 'Exact Online refused the authorisation: {error}', [
                'error' => $request->getQueryParam('error_description') ?: $error,
            ]));

            return $this->redirect($settingsUrl);
        }

        // The state check is what stops an unsolicited callback swapping the connection out from
        // under the merchant.
        if (!Plugin::getInstance()->getOauth()->verifyState($request->getQueryParam('state'))) {
            $session->setError(Craft::t('exactly', 'That authorisation did not start here. Try connecting again.'));

            return $this->redirect($settingsUrl);
        }

        $code = $request->getQueryParam('code');

        if (!is_string($code) || $code === '') {
            $session->setError(Craft::t('exactly', 'Exact Online sent no authorization code.'));

            return $this->redirect($settingsUrl);
        }

        try {
            Plugin::getInstance()->getOauth()->exchangeCode($code);
            $me = Plugin::getInstance()->getDivisions()->syncIdentity();

            $session->setNotice(Craft::t('exactly', 'Connected to Exact Online as {name}.', [
                'name' => $me['FullName'] ?? Craft::t('exactly', 'your Exact user'),
            ]));
        } catch (\Throwable $e) {
            $session->setError($e->getMessage());
        }

        return $this->redirect($settingsUrl);
    }

    /**
     * Forget the stored tokens.
     */
    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->getOauth()->disconnect();
        Plugin::getInstance()->getLedger()->clearCache();

        return $this->asSuccess(Craft::t('exactly', 'Disconnected from Exact Online.'));
    }
}
