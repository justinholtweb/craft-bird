<?php

namespace justinholtweb\bird;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\OrderStatusEvent;
use craft\commerce\services\OrderHistories;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\bird\models\Settings;
use justinholtweb\bird\services\Api;
use justinholtweb\bird\services\Contacts;
use justinholtweb\bird\services\Documents;
use justinholtweb\bird\services\Invoices;
use justinholtweb\bird\services\Log;
use justinholtweb\bird\services\Payments;
use justinholtweb\bird\services\Sync;
use justinholtweb\bird\services\Vat;
use justinholtweb\bird\services\Webhooks;
use justinholtweb\bird\twig\BirdVariable;
use yii\base\Event;

/**
 * Bird — Moneybird bookkeeping for Craft Commerce.
 *
 * @property-read Api $api
 * @property-read Contacts $contacts
 * @property-read Documents $documents
 * @property-read Invoices $invoices
 * @property-read Log $log
 * @property-read Payments $payments
 * @property-read Sync $sync
 * @property-read Vat $vat
 * @property-read Webhooks $webhooks
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'bird';

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
                'api' => ['class' => Api::class],
                'contacts' => ['class' => Contacts::class],
                'documents' => ['class' => Documents::class],
                'invoices' => ['class' => Invoices::class],
                'log' => ['class' => Log::class],
                'payments' => ['class' => Payments::class],
                'sync' => ['class' => Sync::class],
                'vat' => ['class' => Vat::class],
                'webhooks' => ['class' => Webhooks::class],
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

        // Bird can be installed while Commerce is disabled or mid-upgrade, and everything below
        // this line touches an order.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderPanel();
        $this->_registerTriggers();
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

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getContacts(): Contacts
    {
        return $this->get('contacts');
    }

    public function getDocuments(): Documents
    {
        return $this->get('documents');
    }

    public function getInvoices(): Invoices
    {
        return $this->get('invoices');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    public function getPayments(): Payments
    {
        return $this->get('payments');
    }

    public function getSync(): Sync
    {
        return $this->get('sync');
    }

    public function getVat(): Vat
    {
        return $this->get('vat');
    }

    public function getWebhooks(): Webhooks
    {
        return $this->get('webhooks');
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
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('bird/settings', [
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
        $item['label'] = Craft::t('bird', 'Bird');

        $user = Craft::$app->getUser();
        $subNav = [];

        if ($user->checkPermission('bird-viewDocuments')) {
            $subNav['documents'] = [
                'label' => Craft::t('bird', 'Documents'),
                'url' => 'bird/documents',
            ];
        }

        if ($this->isPro() && $user->checkPermission('bird-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('bird', 'Log'),
                'url' => 'bird/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subNav['settings'] = [
                'label' => Craft::t('bird', 'Settings'),
                'url' => 'settings/plugins/bird',
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
                $variable->set('bird', BirdVariable::class);
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
                    'heading' => Craft::t('bird', 'Bird'),
                    'permissions' => [
                        'bird-viewDocuments' => [
                            'label' => Craft::t('bird', 'View Moneybird documents'),
                            'nested' => [
                                'bird-pushOrders' => [
                                    'label' => Craft::t('bird', 'Send orders to Moneybird'),
                                ],
                            ],
                        ],
                        'bird-viewLog' => [
                            'label' => Craft::t('bird', 'View the connection log'),
                        ],
                        'bird-manageConnection' => [
                            'label' => Craft::t('bird', 'Test the connection and manage the webhook'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['bird'] = 'bird/documents/index';
                $event->rules['bird/documents'] = 'bird/documents/index';
                $event->rules['bird/documents/<documentId:\d+>'] = 'bird/documents/detail';
                $event->rules['bird/log'] = 'bird/log/index';
                $event->rules['bird/log/<entryId:\d+>'] = 'bird/log/detail';
            }
        );
    }

    /**
     * Bird's panel on Commerce's own order edit screen.
     */
    private function _registerOrderPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order || !$order->id) {
                return null;
            }

            if (!Craft::$app->getUser()->checkPermission('bird-viewDocuments')) {
                return null;
            }

            return Craft::$app->getView()->renderTemplate('bird/_order-panel', [
                'order' => $order,
                'documents' => $this->getDocuments()->getDocumentsForOrder($order->id),
                'vat' => $this->getVat()->describeOrder($order),
                'isPro' => $this->isPro(),
                'isReady' => $this->getSync()->isReady(),
                'canPush' => Craft::$app->getUser()->checkPermission('bird-pushOrders'),
            ], View::TEMPLATE_MODE_CP);
        });
    }

    /**
     * The three moments an order can become an invoice.
     */
    private function _registerTriggers(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            static function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                Plugin::getInstance()->getSync()->handleTrigger($order, Settings::TRIGGER_COMPLETE);
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_ORDER_PAID,
            static function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                Plugin::getInstance()->getSync()->handleTrigger($order, Settings::TRIGGER_PAID);
            }
        );

        Event::on(
            OrderHistories::class,
            OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            static function(OrderStatusEvent $event) {
                Plugin::getInstance()->getSync()->handleTrigger($event->order, Settings::TRIGGER_STATUS);
            }
        );
    }
}
