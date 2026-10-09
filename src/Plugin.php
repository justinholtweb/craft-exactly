<?php

namespace justinholtweb\exactly;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\elements\Order;
use craft\commerce\events\OrderStatusEvent;
use craft\commerce\models\Transaction;
use craft\commerce\services\OrderHistories;
use craft\commerce\services\Transactions;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\PopulateElementsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Html;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\exactly\elements\actions\SendToExact;
use justinholtweb\exactly\elements\conditions\ExactStatusConditionRule;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\models\Settings;
use justinholtweb\exactly\services\Accounts;
use justinholtweb\exactly\services\Alerts;
use justinholtweb\exactly\services\Api;
use justinholtweb\exactly\services\Divisions;
use justinholtweb\exactly\services\Documents;
use justinholtweb\exactly\services\Invoices;
use justinholtweb\exactly\services\Items;
use justinholtweb\exactly\services\Ledger;
use justinholtweb\exactly\services\Log;
use justinholtweb\exactly\services\Oauth;
use justinholtweb\exactly\services\PaymentEntries;
use justinholtweb\exactly\services\Payments;
use justinholtweb\exactly\services\Sync;
use justinholtweb\exactly\services\Vat;
use justinholtweb\exactly\twig\ExactlyVariable;
use justinholtweb\exactly\widgets\HealthWidget;
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
 * @property-read PaymentEntries $paymentEntries
 * @property-read Alerts $alerts
 * @property-read Sync $sync
 * @property-read Log $log
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'exactly';

    public string $schemaVersion = '5.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

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
                'paymentEntries' => ['class' => PaymentEntries::class],
                'alerts' => ['class' => Alerts::class],
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
        $this->_registerWidgets();

        // Exactly can be installed while Commerce is disabled or mid-upgrade, and everything below
        // touches an order — so it all waits until Commerce is actually there.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderEditPanel();
        $this->_registerAutomaticTriggers();
        $this->_registerOrderIndex();
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
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

    public function getPaymentEntries(): PaymentEntries
    {
        return $this->get('paymentEntries');
    }

    public function getAlerts(): Alerts
    {
        return $this->get('alerts');
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

        if ($user->checkPermission('exactly-viewDocuments') && $user->checkPermission('commerce-manageOrders')) {
            $subNav['documents'] = [
                'label' => Craft::t('exactly', 'Documents'),
                'url' => 'exactly/documents',
            ];
        }

        if (
            $user->checkPermission('exactly-viewDocuments')
            && $user->checkPermission('commerce-manageOrders')
            && $this->getSettings()->registerPayments
        ) {
            $subNav['payments'] = [
                'label' => Craft::t('exactly', 'Payments'),
                'url' => 'exactly/payments',
            ];
        }

        if ($user->checkPermission('exactly-viewLog')) {
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
                $event->rules['exactly/payments'] = 'exactly/payments/index';
                $event->rules['exactly/payments/preview/<transactionId:\d+>'] = 'exactly/payments/preview';
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
            'paymentEntries' => $this->getPaymentEntries()->getEntriesForOrder((int)$order->id),
            'registerPayments' => $this->getSettings()->registerPayments,
                'connected' => $this->getOauth()->isConnected(),
                'canPush' => Craft::$app->getUser()->checkPermission('exactly-pushOrders'),
                'canCredit' => Craft::$app->getUser()->checkPermission('exactly-creditOrders'),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    /**
     * The automatic triggers.
     *
     * The three invoice triggers go through `Sync::handleOrder()`, and a refund through
     * `Sync::handleRefund()`; both queue rather than push. Nothing here is
     * allowed to fail an order save — an Exact Online outage must not be able to stop a customer
     * checking out.
     */
    private function _registerAutomaticTriggers(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                if ($this->getSettings()->pushTrigger !== 'completed') {
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
                if ($this->getSettings()->pushTrigger !== 'paid') {
                    return;
                }

                $order = $event->sender;

                if ($order instanceof Order) {
                    $this->getSync()->handleOrder($order);
                }
            }
        );

        // Refund → credit note. On *every* saved transaction, not Payments' after-refund event:
        // Commerce transactions are immutable, so a refund a gateway reports as processing and
        // settles later by webhook (Mollie, for one) arrives as a new success transaction through
        // saveTransaction() — and never re-fires the after-refund event. A synchronous refund goes
        // through saveTransaction() too. `handleRefund()` ignores anything that is not a successful
        // refund, and never throws: the gateway has already refunded.
        Event::on(
            Transactions::class,
            Transactions::EVENT_AFTER_SAVE_TRANSACTION,
            function(Event $event) {
                $transaction = $event->transaction ?? null;

                // Payment entries: every successful capture, purchase or refund, queued only —
                // `queueTransaction()` never touches the network and never throws, so a checkout
                // cannot fail on it. Same event as the credit notes below, for the same reason: a
                // webhook-settled payment arrives as a new transaction through saveTransaction().
                if ($transaction instanceof Transaction && $this->getSettings()->registerPayments) {
                    $this->getPaymentEntries()->queueTransaction($transaction);
                }

                if (!$this->getSettings()->creditNotesOnRefund) {
                    return;
                }

                $transaction = $event->transaction ?? null;

                if ($transaction instanceof Transaction) {
                    $this->getSync()->handleRefund($transaction);
                }
            }
        );

        Event::on(
            OrderHistories::class,
            OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            function(OrderStatusEvent $event) {
                if ($this->getSettings()->pushTrigger !== 'status') {
                    return;
                }

                $this->getSync()->handleOrder($event->order);
            }
        );
    }

    private function _registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = HealthWidget::class;
            }
        );
    }

    /**
     * The Orders index: an Exact Online column, an "Exact Online status" filter, and a bulk
     * "Send to Exact Online" action.
     *
     * Every hook is attached to the Order class, not to Element: the table-attribute events do not
     * say which element type is asking.
     */
    private function _registerOrderIndex(): void
    {
        // Registered unconditionally. A rule registered only for some users or settings is
        // dropped from saved conditions, and a custom source built on it silently widens to
        // every order.
        Event::on(
            OrderCondition::class,
            OrderCondition::EVENT_REGISTER_CONDITION_RULES,
            static function(RegisterConditionRulesEvent $event) {
                $event->conditionRules[] = ExactStatusConditionRule::class;
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            static function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['exactlyStatus'] = ['label' => Craft::t('exactly', 'Exact Online')];
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            static function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== 'exactlyStatus') {
                    return;
                }

                /** @var Order $order */
                $order = $event->sender;
                $event->html = Plugin::getInstance()->orderStatusHtml($order);
                $event->handled = true;
            }
        );

        // One lookup per index page rather than per row.
        Event::on(
            OrderQuery::class,
            OrderQuery::EVENT_AFTER_POPULATE_ELEMENTS,
            static function(PopulateElementsEvent $event) {
                $request = Craft::$app->getRequest();

                if ($request->getIsConsoleRequest() || !$request->getIsCpRequest() || ($request->getActionSegments()[0] ?? null) !== 'element-indexes') {
                    return;
                }

                $ids = [];

                foreach ($event->elements as $element) {
                    if ($element instanceof Order && $element->id) {
                        $ids[] = $element->id;
                    }
                }

                try {
                    Plugin::getInstance()->getDocuments()->prefetchOrderSummaries($ids);
                } catch (\Throwable $e) {
                    // The Orders index is Commerce's screen; it must not 500 because of a column.
                    Craft::warning('Exactly could not prefetch order statuses: ' . $e->getMessage(), __METHOD__);
                }
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_REGISTER_ACTIONS,
            static function(RegisterElementActionsEvent $event) {
                // Actions are not saved anywhere, so offering this only to people who may use it
                // is safe; the action checks the permission again when it runs.
                if (Craft::$app->getUser()->checkPermission('exactly-pushOrders')) {
                    $event->actions[] = SendToExact::class;
                }
            }
        );
    }

    /**
     * The Orders index cell: a status dot, a word, and the invoice number once there is one.
     */
    public function orderStatusHtml(Order $order): string
    {
        if (!$order->id || !Craft::$app->getUser()->checkPermission('exactly-viewDocuments')) {
            return '';
        }

        $summary = $this->getDocuments()->orderSummary((int)$order->id);
        $status = $summary['status'];
        $label = \justinholtweb\exactly\services\Documents::orderStatusOptions()[$status] ?? $status;
        $color = match ($status) {
            Document::ORDER_PAID, Document::ORDER_INVOICED => 'green',
            Document::ORDER_CREDITED => 'grey',
            Document::ORDER_FAILED => 'red',
            Document::ORDER_IN_FLIGHT => 'blue',
            Document::ORDER_PENDING => 'orange',
            default => 'disabled',
        };

        if ($status === Document::ORDER_NONE) {
            return Html::tag('span', Html::encode($label), ['class' => 'light']);
        }

        $number = in_array($status, [Document::ORDER_PAID, Document::ORDER_INVOICED, Document::ORDER_CREDITED], true) && $summary['number']
            ? ' ' . Html::tag('span', Html::encode($summary['number']), ['class' => 'light code'])
            : '';

        return Html::tag('span', '', ['class' => ['status', $color], 'aria-hidden' => 'true'])
            . Html::encode($label) . $number;
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
