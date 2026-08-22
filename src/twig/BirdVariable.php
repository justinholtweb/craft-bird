<?php

namespace justinholtweb\bird\twig;

use craft\commerce\elements\Order;
use justinholtweb\bird\models\Document;
use justinholtweb\bird\Plugin;
use yii\base\BaseObject;

/**
 * `craft.bird` — what a front-end template can ask about an order's bookkeeping.
 *
 * Note what is *not* here: no push, no refund, no settings. A Twig template renders a page; it
 * does not get to book revenue.
 */
class BirdVariable extends BaseObject
{
    /**
     * Whether Bird is connected to Moneybird.
     */
    public function isConfigured(): bool
    {
        return Plugin::getInstance()->getSettings()->isConfigured();
    }

    /**
     * The document booked for an order, if there is one.
     *
     * @param Order|int $order
     */
    public function documentForOrder(Order|int $order): ?Document
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        if (!$orderId) {
            return null;
        }

        return Plugin::getInstance()->getDocuments()->getDocumentForOrder($orderId);
    }

    /**
     * Every document booked for an order, invoice and credit notes alike.
     *
     * @param Order|int $order
     * @return Document[]
     */
    public function documentsForOrder(Order|int $order): array
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        return $orderId ? Plugin::getInstance()->getDocuments()->getDocumentsForOrder($orderId) : [];
    }

    /**
     * Moneybird's public invoice URL for an order.
     *
     * It is a capability URL: anybody holding it can see the invoice. Only ever render it to the
     * customer whose order it is — the same check you would put around an order summary.
     *
     * @param Order|int $order
     */
    public function invoiceUrl(Order|int $order): ?string
    {
        return $this->documentForOrder($order)?->publicUrl;
    }

    /**
     * Commerce order statuses as `{label, value}` options, for Bird's own settings screen.
     *
     * Craft 5 has no `_includes/statuses` template to import from a plugin — it was removed, and
     * importing it throws a `TemplateLoaderException` that 500s the settings screen.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function orderStatusOptions(): array
    {
        if (!Plugin::commerceIsReady()) {
            return [];
        }

        $options = [];

        foreach (\craft\commerce\Plugin::getInstance()->getOrderStatuses()->getAllOrderStatuses() as $status) {
            $options[] = ['label' => $status->name, 'value' => $status->handle];
        }

        return $options;
    }

    /**
     * How an order's VAT was treated: domestic, reverse charge, OSS, export.
     *
     * Useful for printing "VAT reverse-charged" on a shop's own order confirmation.
     *
     * @return array{treatment: string, label: string, country: string|null, vatNumber: string|null, reverseCharge: bool}
     */
    public function vatTreatment(Order $order): array
    {
        return Plugin::getInstance()->getVat()->describeOrder($order);
    }
}
