<?php

namespace justinholtweb\exactly;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\OrderStatusEvent;
use craft\commerce\services\OrderHistories;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\exactly\models\Settings;
use justinholtweb\exactly\services\Accounts;
use justinholtweb\exactly\services\Api;
use justinholtweb\exactly\services\Divisions;
use justinholtweb\exactly\services\Documents;
use justinholtweb\exactly\services\Invoices;
use justinholtweb\exactly\services\Items;
use justinholtweb\exactly\services\Ledger;
use justinholtweb\exactly\services\Log;
use justinholtweb\exactly\services\Oauth;
use justinholtweb\exactly\services\Payments;
use justinholtweb\exactly\services\Sync;
use justinholtweb\exactly\services\Vat;
use justinholtweb\exactly\twig\ExactlyVariable;
use yii\base\Event;

/**
 * Exactly — Exact Online invoicing for Craft Commerce.
 *
 * @property-read Oauth $oauth
 * @property-read Api $api
 * @property-read Divisions $divisions
 * @property-read Accounts $accounts
 * @property-read Items $items
 * @property-read Ledger $ledger
 * @property-read Vat $vat
 * @property-read Invoices $invoices
 * @property-read Documents $documents
 * @property-read Payments $payments
 * @property-read Sync $sync
 * @property-read Log $log
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'exactly';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'oauth' => ['class' => Oauth::class],
                'api' => ['class' => Api::class],
                'divisions' => ['class' => Divisions::class],
                'accounts' => ['class' => Accounts::class],
                'items' => ['class' => Items::class],
                'ledger' => ['class' => Ledger::class],
                'vat' => ['class' => Vat::class],
                'invoices' => ['class' => Invoices::class],
                'documents' => ['class' => Documents::class],
                'payments' => ['class' => Payments::class],
                'sync' => ['class' => Sync::class],
                'log' => ['class' => Log::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();
        $this->_registerSiteRoutes();
        $this->_registerGarbageCollection();

        // Exactly can be installed while Commerce is disabled or mid-upgrade, and everything below
        // touches an order — so it all waits until Commerce is actually there.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderEditPanel();

        if ($this->isPro()) {
            $this->_registerAutomaticTriggers();
        }
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * Whether this install is licensed for the Pro feature set.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getOauth(): Oauth
    {
        return $this->get('oauth');
    }

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getDivisions(): Divisions
    {
        return $this->get('divisions');
    }

    public function getAccounts(): Accounts
    {
        return $this->get('accounts');
    }

    public function getItems(): Items
    {
        return $this->get('items');
    }

    public function getLedger(): Ledger
    {
        return $this->get('ledger');
    }

    public function getVat(): Vat
    {
        return $this->get('vat');
    }

    public function getInvoices(): Invoices
    {
        return $this->get('invoices');
    }

    public function getDocuments(): Documents
    {
        return $this->get('documents');
    }

    public function getPayments(): Payments
    {
        return $this->get('payments');
    }

    public function getSync(): Sync
    {
        return $this->get('sync');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     *
     * Note there is no `getSettingsResponse()` override: `settings/plugins/exactly` *is* the URL
     * Craft renders this at, so redirecting to it would be an infinite loop.
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('exactly/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('exactly', 'Exactly');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('exactly-viewDocuments')) {
            $subNav['documents'] = [
                'label' => Craft::t('exactly', 'Documents'),
                'url' => 'exactly/documents',
            ];
        }

        if ($this->isPro() && $user->checkPermission('exactly-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('exactly', 'Log'),
                'url' => 'exactly/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('exactly', 'Settings'),
                'url' => 'settings/plugins/exactly',
            ];
        }

        if (!$subNav) {
            return null;
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('exactly', ExactlyVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('exactly', 'Exactly'),
                    'permissions' => [
                        'exactly-viewDocuments' => [
                            'label' => Craft::t('exactly', 'View Exact Online documents'),
                            'nested' => [
                                'exactly-pushOrders' => [
                                    'label' => Craft::t('exactly', 'Send orders to Exact Online'),
                                ],
                                'exactly-creditOrders' => [
                                    'label' => Craft::t('exactly', 'Issue credit notes'),
                                ],
                            ],
                        ],
                        'exactly-viewLog' => [
                            'label' => Craft::t('exactly', 'View the connection log'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * The OAuth callback as a plain site path.
     *
     * Not an action URL: OAuth providers are fussy about redirect URIs and Craft's action URLs
     * carry `?p=…` unless `omitScriptNameInUrls` is on. This gives the merchant a clean path to
     * register on their Exact app.
     */
    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules[Settings::CALLBACK_PATH] = 'exactly/oauth/callback';
                $event->rules['exactly/oauth/connect'] = 'exactly/oauth/connect';
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['exactly'] = 'exactly/documents/index';
                $event->rules['exactly/documents'] = 'exactly/documents/index';
                $event->rules['exactly/documents/<documentId:\d+>'] = 'exactly/documents/detail';
                $event->rules['exactly/documents/preview/<orderId:\d+>'] = 'exactly/documents/preview';
                $event->rules['exactly/log'] = 'exactly/log/index';
                $event->rules['exactly/log/<entryId:\d+>'] = 'exactly/log/detail';
            }
        );
    }

    /**
     * Exactly's own panel on Commerce's order edit screen.
     */
    private function _registerOrderEditPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('exactly-viewDocuments')) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('exactly/_order-panel', [
                'order' => $order,
                'documents' => $this->getDocuments()->getDocumentsForOrder((int)$order->id),
                'isPro' => $this->isPro(),
                'connected' => $this->getOauth()->isConnected(),
                'canPush' => Craft::$app->getUser()->checkPermission('exactly-pushOrders'),
                'canCredit' => Craft::$app->getUser()->checkPermission('exactly-creditOrders'),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    /**
     * The automatic triggers (Pro).
     *
     * All three go through `Sync::handleOrder()`, which queues rather than pushes. Nothing here is
     * allowed to fail an order save — an Exact Online outage must not be able to stop a customer
     * checking out.
     */
    private function _registerAutomaticTriggers(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                if ($this->getSettings()->getEffectivePushTrigger() !== 'completed') {
                    return;
                }

                $order = $event->sender;

                if ($order instanceof Order) {
                    $this->getSync()->handleOrder($order);
                }
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_ORDER_PAID,
            function(Event $event) {
                if ($this->getSettings()->getEffectivePushTrigger() !== 'paid') {
                    return;
                }

                $order = $event->sender;

                if ($order instanceof Order) {
                    $this->getSync()->handleOrder($order);
                }
            }
        );

        Event::on(
            OrderHistories::class,
            OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            function(OrderStatusEvent $event) {
                if ($this->getSettings()->getEffectivePushTrigger() !== 'status') {
                    return;
                }

                $this->getSync()->handleOrder($event->order);
            }
        );
    }

    /**
     * Ledger rows for orders that no longer exist.
     *
     * Craft's foreign key covers deletes that went through it. This catches the rest — a restored
     * database, a hard delete, a botched migration — because a documents screen listing invoices
     * against orders nobody can open is worse than one that is a row short.
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            try {
                $this->getSync()->garbageCollect();
                $this->getLog()->prune();
            } catch (\Throwable $e) {
                Craft::warning('Exactly garbage collection failed: ' . $e->getMessage(), __METHOD__);
            }
        });
    }
}
