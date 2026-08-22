<?php

namespace justinholtweb\bird\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\bird\models\LogEntry;
use justinholtweb\bird\Plugin;
use justinholtweb\bird\services\Webhooks;
use yii\web\Response;

/**
 * Moneybird's end of the webhook.
 *
 * Anonymous by necessity and verified by signature. The rule is fail-closed: no secret, no
 * signature, a stale timestamp or a Pro-only feature on a Lite install all end the request before
 * the payload is looked at.
 */
class WebhookController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['receive'];

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    public function actionReceive(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $request = Craft::$app->getRequest();

        if (!$plugin->isPro() || !$settings->webhooksEnabled) {
            return $this->respond(404, ['message' => 'Not found']);
        }

        $secret = $settings->getParsedWebhookSecret();

        if ($secret === '') {
            $this->log(LogEntry::LEVEL_ERROR, Craft::t('bird', 'A webhook arrived but no signing secret is configured.'), null);

            return $this->respond(401, ['message' => 'No signing secret configured']);
        }

        // The raw bytes, exactly as they arrived. Anything re-encoded would hash differently and
        // never verify — which is the whole point of signing the body rather than its meaning.
        $rawBody = $request->getRawBody();
        $signature = $request->getHeaders()->get(Webhooks::SIGNATURE_HEADER, '');

        if (!$plugin->getWebhooks()->verify($rawBody, (string)$signature, $secret)) {
            $this->log(LogEntry::LEVEL_WARNING, Craft::t('bird', 'Rejected a webhook with a bad or stale signature.'), $rawBody);

            return $this->respond(401, ['message' => 'Invalid signature']);
        }

        $payload = Json::decodeIfJson($rawBody);

        if (!is_array($payload)) {
            return $this->respond(400, ['message' => 'Malformed payload']);
        }

        $result = $plugin->getWebhooks()->handle($payload);

        // A 200 either way: an event Bird has no use for is not an error, and answering anything
        // else just makes Moneybird redeliver it forever.
        return $this->respond(200, ['message' => $result['message'], 'handled' => $result['handled']]);
    }

    private function log(string $level, string $summary, ?string $body): void
    {
        Plugin::getInstance()->getLog()->write('webhook', [
            'level' => $level,
            'summary' => $summary,
            'request' => $body,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respond(int $status, array $data): Response
    {
        $response = $this->asJson($data);
        $response->setStatusCode($status);

        return $response;
    }
}
