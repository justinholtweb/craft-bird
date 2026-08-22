<?php

namespace justinholtweb\bird\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\bird\exceptions\MappingException;
use justinholtweb\bird\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Documents screen, and the buttons on Commerce's order edit page.
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

        $this->requirePermission('bird-viewDocuments');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $documents = Plugin::getInstance()->getDocuments();

        $criteria = [
            'state' => $request->getParam('state'),
            'kind' => $request->getParam('kind'),
            'search' => $request->getParam('search'),
        ];

        return $this->renderTemplate('bird/documents/_index', [
            'documents' => $documents->find($criteria, 200),
            'criteria' => $criteria,
            'counts' => $documents->countsByState(),
            'total' => $documents->count(),
            'isReady' => Plugin::getInstance()->getSync()->isReady(),
        ]);
    }

    public function actionDetail(int $documentId): Response
    {
        $document = Plugin::getInstance()->getDocuments()->getDocumentById($documentId);

        if ($document === null) {
            throw new NotFoundHttpException('Document not found');
        }

        $order = Order::find()->id($document->orderId)->status(null)->one();

        return $this->renderTemplate('bird/documents/_detail', [
            'document' => $document,
            'order' => $order,
            'entries' => Plugin::getInstance()->getLog()->getEntries(['orderId' => $document->orderId], 50),
            'canPush' => Craft::$app->getUser()->checkPermission('bird-pushOrders'),
            'isPro' => Plugin::getInstance()->isPro(),
        ]);
    }

    /**
     * Book one order now.
     */
    public function actionPush(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('bird-pushOrders');

        $order = $this->orderFromRequest();
        $force = (bool)Craft::$app->getRequest()->getBodyParam('force');

        $result = Plugin::getInstance()->getSync()->pushOrder($order, $force);

        if (!$result->success) {
            return $this->asFailure($result->message);
        }

        return $this->asSuccess($result->message, [
            'documentId' => $result->document?->id,
            'skipped' => $result->skipped,
        ]);
    }

    /**
     * Credit whatever has been refunded since the invoice was booked.
     */
    public function actionCreditRefunds(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('bird-pushOrders');

        if (!Plugin::getInstance()->isPro()) {
            return $this->asFailure(Craft::t('bird', 'Crediting refunds requires Bird Pro.'));
        }

        $order = $this->orderFromRequest();
        $results = Plugin::getInstance()->getSync()->pushRefunds($order);

        if ($results === []) {
            return $this->asSuccess(Craft::t('bird', 'Nothing to credit.'));
        }

        $failures = array_filter($results, static fn($result) => !$result->success);

        if ($failures !== []) {
            return $this->asFailure(implode(' ', array_map(static fn($result) => $result->message, $failures)));
        }

        return $this->asSuccess(implode(' ', array_map(static fn($result) => $result->message, $results)));
    }

    /**
     * Re-read a document from Moneybird.
     */
    public function actionRefresh(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('bird-pushOrders');

        $documentId = (int)Craft::$app->getRequest()->getBodyParam('documentId');
        $document = Plugin::getInstance()->getDocuments()->getDocumentById($documentId);

        if ($document === null) {
            throw new NotFoundHttpException('Document not found');
        }

        $result = Plugin::getInstance()->getSync()->refreshDocument($document);

        return $result->success ? $this->asSuccess($result->message) : $this->asFailure($result->message);
    }

    /**
     * Forget a document locally, so the next push books a fresh one.
     *
     * What is in Moneybird stays in Moneybird — deleting a booked invoice out of a shop's
     * accounts is not something this button is allowed to do, and Moneybird would refuse anyway
     * once the invoice has a number.
     */
    public function actionForget(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('bird-pushOrders');

        $documentId = (int)Craft::$app->getRequest()->getBodyParam('documentId');

        if (!Plugin::getInstance()->getDocuments()->deleteById($documentId)) {
            return $this->asFailure(Craft::t('bird', 'Nothing was removed.'));
        }

        return $this->asSuccess(Craft::t('bird', 'Bird has forgotten this document. The invoice is still in Moneybird.'));
    }

    /**
     * The exact JSON that would be posted to Moneybird for this order.
     *
     * The same `Invoices::buildPayload()` the push uses, so a preview cannot flatter the real
     * thing — including the tax rate ids, which is where mistakes actually live.
     */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();

        $order = $this->orderFromRequest();
        $plugin = Plugin::getInstance();

        try {
            $contactId = $plugin->getSettings()->syncContacts && $plugin->getSettings()->isConfigured()
                ? $plugin->getContacts()->contactIdForOrder($order)
                : null;
        } catch (\Throwable) {
            // A preview must work before the connection does.
            $contactId = null;
        }

        $contactId = $contactId ?: ($plugin->getSettings()->fallbackContactId ?: null);

        try {
            $payload = $plugin->getInvoices()->buildPayload($order, $contactId);
        } catch (MappingException $e) {
            return $this->asFailure($e->getMessage());
        }

        $wrapper = $payload['type'] === \justinholtweb\bird\models\Settings::DOCUMENT_EXTERNAL_SALES_INVOICE
            ? 'external_sales_invoice'
            : 'sales_invoice';

        return $this->asJson([
            'success' => true,
            'json' => Json::encode([$wrapper => $payload['attributes']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'treatment' => $payload['treatment'],
            'orderTotal' => $payload['orderTotal'],
            'invoiceTotal' => $payload['expectedTotal'],
            'rounding' => $payload['rounding'],
        ]);
    }

    private function orderFromRequest(): Order
    {
        $orderId = (int)Craft::$app->getRequest()->getParam('orderId');
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            throw new NotFoundHttpException('Order not found');
        }

        return $order;
    }
}
