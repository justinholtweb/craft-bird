<?php

namespace justinholtweb\bird\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use justinholtweb\bird\Plugin;

/**
 * Push one order to Moneybird from the queue.
 *
 * The queue is the default because everything this does is somebody else's server: a slow
 * Moneybird, a rate limit, a retried request. None of that belongs in the request that just took
 * a customer's money.
 */
class PushOrderJob extends BaseJob
{
    public int $orderId;

    /**
     * Credit any refunds on the same pass (Pro). Off for the ordinary order-complete push, which
     * cannot have refunds yet.
     */
    public bool $includeRefunds = false;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $order = Order::find()->id($this->orderId)->status(null)->one();

        if (!$order instanceof Order) {
            // The order was deleted while the job waited. Nothing to book.
            return;
        }

        $this->setProgress($queue, 0.1);

        $result = Plugin::getInstance()->getSync()->pushOrder($order);

        $this->setProgress($queue, $this->includeRefunds ? 0.6 : 1);

        if ($this->includeRefunds) {
            Plugin::getInstance()->getSync()->pushRefunds($order);
            $this->setProgress($queue, 1);
        }

        // A failure the queue can usefully retry is thrown; a mapping error is not, because the
        // next attempt would hit exactly the same wrong setting and Craft would keep the job
        // sitting in the queue looking like an outage.
        if (!$result->success && $result->retryable) {
            throw new \RuntimeException($result->message);
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('bird', 'Sending an order to Moneybird');
    }
}
