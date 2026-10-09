<?php

namespace justinholtweb\bird\services;

use Craft;
use craft\base\Component;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use justinholtweb\bird\db\Table;
use justinholtweb\bird\events\AlertEvent;
use justinholtweb\bird\helpers\Ip;
use justinholtweb\bird\models\Document;
use justinholtweb\bird\models\Settings;
use justinholtweb\bird\Plugin;
use Throwable;

/**
 * Failure alerts: tell somebody when booking into Moneybird is in trouble, once, and again when it
 * is not.
 *
 * The Documents screen already knows everything that has gone wrong. What it cannot do is make a
 * merchant look at it, and nobody opens Bird on a day the shop seems to be working — which is how
 * an order that never reached Moneybird turns into revenue missing from a VAT return. Three
 * incidents are worth interrupting somebody for:
 *
 * - **Documents** — invoices or credit notes that failed to book: Moneybird refused them, or Bird
 *   refused to send them (an unmapped VAT rate, no contact to invoice). Each is a hole in the books
 *   until a person deals with it.
 * - **Authentication** — Moneybird answered a final 401 or 403: the token was revoked, expired or
 *   lacks the scope a call needs. Nothing reaches Moneybird until somebody makes a new one.
 * - **Stalled** — completed orders that are due a booking and still have none after
 *   `alertStallHours`, or a push that has sat in "pending" that long. The quietest failure: no
 *   errors at all, because the queue is not running.
 *
 * This is Erpy's alert pattern (the Theme 1 reference), with the connection taken out as in Lexies:
 * Bird books into one administration per install, so each incident is one latch row in
 * `bird_alerts`, unique on `incident`. Opening it sends one alert; it stays open — and silent —
 * however many checks see the same trouble; clearing it sends one recovery. A send is claimed with
 * a conditional update before it goes out and released if it fails, so a queue worker and cron
 * checking in the same minute cannot both send, and a mail outage does not swallow the alert.
 *
 * Detection runs from three places: {@see afterDocument()} whenever `Documents::save()` writes a
 * row (documents, and the recovery of a stall), {@see noteAuthFailure()}/{@see noteAuthSuccess()}
 * from `Api::request()` (authentication, immediately), and {@see check()} from `bird/alerts/check`
 * and the other cron-shaped commands — the only path that can notice a stall when nothing is
 * running at all.
 *
 * Every path is fail-open. An invoice must never fail to book because an alert could not.
 */
class Alerts extends Component
{
    public const INCIDENT_DOCUMENTS = 'documents';
    public const INCIDENT_AUTH = 'auth';
    public const INCIDENT_STALLED = 'stalled';

    public const STATE_OK = 'ok';
    public const STATE_OPEN = 'open';

    /** @event AlertEvent before an alert or a recovery is sent; set `isValid` false to swallow it. */
    public const EVENT_BEFORE_NOTIFY = 'beforeNotify';

    /** How often one process re-checks that an authentication incident is still clear. */
    private const AUTH_SUCCESS_TTL = 60;

    /** How far back the stall check looks for orders that never got booked. */
    private const STALL_LOOKBACK_DAYS = 7;

    /**
     * The HTTP client for the webhook. Null means a curl-only client built per send; tests put a
     * Guzzle `MockHandler` client here. Never a client on Guzzle's default stack: it can hand a
     * request to PHP's stream wrapper, which ignores `CURLOPT_RESOLVE` — the address pin.
     */
    public ?ClientInterface $webhookClient = null;

    /** When this process last confirmed the auth incident is clear. */
    private ?int $authClearAt = null;

    /**
     * @return array<string,string> incident => label
     */
    public static function incidents(): array
    {
        return [
            self::INCIDENT_DOCUMENTS => Craft::t('bird', 'Orders failed to book in Moneybird'),
            self::INCIDENT_AUTH => Craft::t('bird', 'Moneybird refused the API token'),
            self::INCIDENT_STALLED => Craft::t('bird', 'Booking into Moneybird has stalled'),
        ];
    }

    public static function incidentLabel(string $incident): string
    {
        return self::incidents()[$incident] ?? $incident;
    }

    // Detection
    // -------------------------------------------------------------------------

    /**
     * Evaluate every incident, or only some.
     *
     * An install with no token or no administration is left exactly as it is: Bird is not
     * switched on yet, which is neither an incident nor a recovery.
     *
     * @param string[]|null $only limit to these incidents
     * @return array<int,array{incident:string,state:string,transition:?string,notified:bool,detail:?string}>
     */
    public function check(?array $only = null): array
    {
        if (!Plugin::getInstance()->getSettings()->isConfigured() || !Plugin::commerceIsReady()) {
            return [];
        }

        $results = [];

        foreach (array_keys(self::incidents()) as $incident) {
            if ($only !== null && !in_array($incident, $only, true)) {
                continue;
            }

            $row = $this->row($incident);
            [$open, $detail] = $this->measure($incident, $row);
            $results[] = $this->transition($incident, $open, $detail);
        }

        return $results;
    }

    /**
     * A document row was just written. Called from `Documents::save()`, the single write point, so
     * the CP buttons, the queue, the console and the webhook handler are all covered.
     *
     * Cheap on purpose — one grouped count and one latch read. The stall query only runs while a
     * stall is open, because a booking going out is the thing that ends one.
     */
    public function afterDocument(Document $document): void
    {
        if ($document->state === Document::STATE_PENDING) {
            return;
        }

        try {
            $only = [self::INCIDENT_DOCUMENTS];

            if (($this->row(self::INCIDENT_STALLED)['state'] ?? null) === self::STATE_OPEN) {
                $only[] = self::INCIDENT_STALLED;
            }

            $this->check($only);
        } catch (Throwable $e) {
            Craft::warning('Bird could not evaluate alerts after a document changed: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Moneybird refused the token: a final 401 or 403.
     *
     * Recorded as a signal rather than measured, because nothing else remembers it — the log can
     * be switched off, and a refused token does not make a document row.
     */
    public function noteAuthFailure(string $reason): void
    {
        try {
            $this->ensureRow(self::INCIDENT_AUTH);

            Craft::$app->getDb()->createCommand()->update(Table::ALERTS, [
                'signalledAt' => $this->now(),
                'signalClearedAt' => null,
                'detail' => $this->redact($reason),
                'dateUpdated' => $this->now(),
            ], ['incident' => self::INCIDENT_AUTH])->execute();

            $this->authClearAt = null;

            $this->check([self::INCIDENT_AUTH]);
        } catch (Throwable $e) {
            Craft::warning('Bird could not record an authentication failure: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * An authenticated request succeeded. Called on every 2xx from Moneybird, so it costs at most
     * one conditional UPDATE per minute per process, and usually nothing.
     */
    public function noteAuthSuccess(): void
    {
        if ($this->authClearAt !== null && time() - $this->authClearAt < self::AUTH_SUCCESS_TTL) {
            return;
        }

        $this->authClearAt = time();

        try {
            $cleared = Craft::$app->getDb()->createCommand()->update(Table::ALERTS, [
                'signalClearedAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], [
                'and',
                ['incident' => self::INCIDENT_AUTH, 'signalClearedAt' => null],
                ['not', ['signalledAt' => null]],
            ])->execute();

            if ($cleared > 0) {
                $this->check([self::INCIDENT_AUTH]);
            }
        } catch (Throwable $e) {
            Craft::warning('Bird could not clear an authentication alert: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Whether an incident is happening right now, and a redacted line saying what was seen.
     *
     * @param array<string,mixed> $row
     * @return array{0:bool,1:?string}
     */
    private function measure(string $incident, array $row): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $wasOpen = ($row['state'] ?? self::STATE_OK) === self::STATE_OPEN;

        switch ($incident) {
            case self::INCIDENT_DOCUMENTS:
                if (!$settings->alertOnDocuments) {
                    return [false, null];
                }

                $counts = $this->problemCounts();
                $total = array_sum($counts);

                // It takes the threshold to open and nothing left to close. Closing the moment the
                // count dipped below the threshold would announce "recovered" while orders are
                // still missing from the books.
                $open = $wasOpen ? $total > 0 : $total >= max(1, $settings->alertDocumentThreshold);

                if (!$open) {
                    return [false, null];
                }

                $latest = (new Query())
                    ->select(['orderId', 'lastError'])
                    ->from(Table::DOCUMENTS)
                    ->where(['state' => Document::STATE_FAILED])
                    ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
                    ->one();

                $line = Craft::t('bird', '{invoices} invoices and {credits} credit notes failed to book.', [
                    'invoices' => $counts[Document::KIND_INVOICE],
                    'credits' => $counts[Document::KIND_CREDIT],
                ]);

                if ($latest) {
                    $line .= ' ' . Craft::t('bird', 'Latest: order {reference}: {reason}', [
                        'reference' => $this->orderReference((int)$latest['orderId']),
                        'reason' => (string)($latest['lastError'] ?: '—'),
                    ]);
                }

                return [true, $this->redact($line)];

            case self::INCIDENT_AUTH:
                $open = $settings->alertOnAuthFailure
                    && !empty($row['signalledAt'])
                    && empty($row['signalClearedAt']);

                return [$open, $open ? ($row['detail'] ?? null) : null];

            case self::INCIDENT_STALLED:
                $stalled = $this->stalled();

                if ($stalled['orders'] === [] && $stalled['pending'] === 0) {
                    return [false, null];
                }

                $lines = [];

                if ($stalled['orders'] !== []) {
                    $oldest = $stalled['orders'][0];
                    $lines[] = Craft::t('bird', '{count} completed orders have not been booked in Moneybird for more than {hours} hours (oldest: order {reference}, due since {since} UTC). Is the queue running?', [
                        'count' => count($stalled['orders']),
                        'hours' => $settings->alertStallHours,
                        'reference' => $this->orderReference($oldest['id']),
                        'since' => $oldest['since']->format('Y-m-d H:i'),
                    ]);
                }

                if ($stalled['pending'] > 0) {
                    $lines[] = Craft::t('bird', '{count} documents have been pending for more than {hours} hours.', [
                        'count' => $stalled['pending'],
                        'hours' => $settings->alertStallHours,
                    ]);
                }

                return [true, $this->redact(implode(' ', $lines))];
        }

        return [false, null];
    }

    /**
     * Documents that failed to book, by kind.
     *
     * @return array<string,int>
     */
    public function problemCounts(): array
    {
        $counts = [Document::KIND_INVOICE => 0, Document::KIND_CREDIT => 0];
        $rows = (new Query())
            ->select(['kind', 'count' => 'COUNT(*)'])
            ->from(Table::DOCUMENTS)
            ->where(['state' => Document::STATE_FAILED])
            ->groupBy(['kind'])
            ->all();

        foreach ($rows as $row) {
            $counts[(string)$row['kind']] = (int)$row['count'];
        }

        return $counts;
    }

    /**
     * Orders that should be in Moneybird by now and are not, and pushes stuck in `pending`.
     *
     * "By now" is `alertStallHours` after the order became due under the chosen trigger: when it was
     * placed, when it was paid, or — for the status trigger — its last change while in that status
     * (the status can be reached days after the order was placed). Only orders from the last week
     * and after Bird was installed are looked at: a shop's history before Bird is what
     * `bird/sync/backfill` is for, not news.
     *
     * Orders with an invoice row in any state are not stalled: a failed one is the documents
     * incident, and a pending one is counted on its own.
     *
     * @return array{orders:array<int,array{id:int,since:DateTime}>,pending:int}
     */
    public function stalled(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $hours = $settings->alertStallHours;
        $none = ['orders' => [], 'pending' => 0];

        if ($hours <= 0 || !$settings->isConfigured() || $settings->trigger === Settings::TRIGGER_MANUAL || !Plugin::commerceIsReady()) {
            return $none;
        }

        $utc = new DateTimeZone('UTC');
        $cutoff = (new DateTime('now', $utc))->modify("-$hours hours");
        $floor = (clone $cutoff)->modify('-' . self::STALL_LOOKBACK_DAYS . ' days');
        $installed = Craft::$app->getPlugins()->getStoredPluginInfo(Plugin::HANDLE)['installDate'] ?? null;
        $installedAt = $installed instanceof DateTime ? $installed : (is_string($installed) ? DateTimeHelper::toDateTime($installed) : null);

        if ($installedAt instanceof DateTime && $installedAt > $floor) {
            $floor = $installedAt;
        }

        $pending = (int)(new Query())
            ->from(Table::DOCUMENTS)
            ->where(['state' => Document::STATE_PENDING])
            ->andWhere(['<', 'dateUpdated', Db::prepareDateForDb($cutoff)])
            ->count();

        /** @var Order[] $candidates */
        $candidates = Order::find()
            ->isCompleted(true)
            ->status(null)
            ->dateOrdered(['and', '>= ' . $floor->format(DATE_ATOM), '< ' . $cutoff->format(DATE_ATOM)])
            ->andWhere(['not in', 'commerce_orders.id', (new Query())
                ->select(['orderId'])
                ->from(Table::DOCUMENTS)
                ->where(['kind' => Document::KIND_INVOICE]),
            ])
            ->orderBy(['commerce_orders.dateOrdered' => SORT_ASC])
            ->limit(200)
            ->all();

        $orders = [];

        foreach ($candidates as $order) {
            $since = $this->dueSince($order, $settings);

            if ($since === null || $since > $cutoff) {
                continue;
            }

            $orders[] = ['id' => (int)$order->id, 'since' => (clone $since)->setTimezone($utc)];
        }

        return ['orders' => $orders, 'pending' => $pending];
    }

    /**
     * When Bird's own trigger would have booked this order, or null if it never would.
     *
     * Mirrors `Sync::handleTrigger()` and `pushOrder()`'s skips: the paid trigger needs a paid
     * order, the status trigger an order sitting in that status, and a zero total is skipped when
     * the shop asked for that.
     */
    private function dueSince(Order $order, Settings $settings): ?DateTime
    {
        if ($settings->skipZeroTotalOrders && abs(round($order->getTotalPrice(), 2)) < 0.01) {
            return null;
        }

        return match ($settings->trigger) {
            Settings::TRIGGER_COMPLETE => $order->dateOrdered,
            Settings::TRIGGER_PAID => $order->getIsPaid() ? ($order->datePaid ?? $order->dateOrdered) : null,
            Settings::TRIGGER_STATUS => $settings->triggerStatusHandle !== '' && $order->getOrderStatus()?->handle === $settings->triggerStatusHandle
                ? ($order->dateUpdated ?? $order->dateOrdered)
                : null,
            default => null,
        };
    }

    private function orderReference(int $orderId): string
    {
        $row = (new Query())
            ->select(['reference', 'number'])
            ->from(CommerceTable::ORDERS)
            ->where(['id' => $orderId])
            ->one();

        if (!$row) {
            return (string)$orderId;
        }

        return (string)($row['reference'] ?: substr((string)$row['number'], 0, 7) ?: $orderId);
    }

    // The latch
    // -------------------------------------------------------------------------

    /**
     * Move the latch, then send whatever it says is owed.
     *
     * @return array{incident:string,state:string,transition:?string,notified:bool,detail:?string}
     */
    private function transition(string $incident, bool $open, ?string $detail): array
    {
        $db = Craft::$app->getDb();
        $row = $this->row($incident);
        $transition = null;
        $where = ['id' => $row['id']];

        if ($open && $row['state'] !== self::STATE_OPEN) {
            // Conditional on the old state, so two checks racing open it once.
            $won = $db->createCommand()->update(Table::ALERTS, [
                'state' => self::STATE_OPEN,
                'openedAt' => $this->now(),
                'notifiedAt' => null,
                'recoveryNotifiedAt' => null,
                'detail' => $detail,
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OK])->execute();
            $transition = $won ? 'opened' : null;
        } elseif ($open && $detail !== null && $detail !== $row['detail']) {
            $db->createCommand()->update(Table::ALERTS, ['detail' => $detail, 'dateUpdated' => $this->now()], $where)->execute();
        } elseif (!$open && $row['state'] === self::STATE_OPEN) {
            $won = $db->createCommand()->update(Table::ALERTS, [
                'state' => self::STATE_OK,
                'recoveredAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OPEN])->execute();
            $transition = $won ? 'recovered' : null;
        }

        $notified = $this->deliverOwed($incident);
        $row = $this->row($incident);

        return [
            'incident' => $incident,
            'state' => (string)$row['state'],
            'transition' => $transition,
            'notified' => $notified,
            'detail' => $row['detail'],
        ];
    }

    /**
     * Send what the latch says is owed: an opening alert nobody has had yet, or the recovery for
     * one somebody has. Claimed by a conditional update first, released again if every channel
     * failed — so it is sent once, and a mail outage delays it rather than losing it.
     */
    private function deliverOwed(string $incident): bool
    {
        $db = Craft::$app->getDb();
        $row = $this->row($incident);
        $where = ['id' => $row['id']];

        if ($row['state'] === self::STATE_OPEN && $row['notifiedAt'] === null) {
            // A flapping incident gets one alert and one recovery per cooldown, not one each per
            // check. A reopening inside the cooldown is told about once the cooldown ends, if it
            // is still open by then.
            if ($row['quietUntil'] !== null && $row['quietUntil'] > $this->now()) {
                return false;
            }

            $claimed = $db->createCommand()->update(Table::ALERTS, ['notifiedAt' => $this->now()], $where + ['notifiedAt' => null, 'state' => self::STATE_OPEN])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($incident, false, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(Table::ALERTS, ['notifiedAt' => null], $where)->execute();

            return false;
        }

        // A recovery is only owed for an incident somebody was told about.
        if ($row['state'] === self::STATE_OK && $row['notifiedAt'] !== null && $row['recoveryNotifiedAt'] === null) {
            $cooldown = max(0, Plugin::getInstance()->getSettings()->alertCooldownMinutes);
            $claimed = $db->createCommand()->update(Table::ALERTS, [
                'recoveryNotifiedAt' => $this->now(),
                'quietUntil' => $cooldown > 0 ? Db::prepareDateForDb((new DateTime())->modify("+$cooldown minutes")) : null,
            ], $where + ['state' => self::STATE_OK, 'recoveryNotifiedAt' => null])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($incident, true, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(Table::ALERTS, [
                'recoveryNotifiedAt' => null,
                'quietUntil' => $row['quietUntil'],
            ], $where)->execute();
        }

        return false;
    }

    /**
     * The latch row, created on first sight.
     *
     * @return array<string,mixed>
     */
    private function row(string $incident): array
    {
        $this->ensureRow($incident);

        return (array)(new Query())
            ->from(Table::ALERTS)
            ->where(['incident' => $incident])
            ->one();
    }

    private function ensureRow(string $incident): void
    {
        $exists = (new Query())
            ->from(Table::ALERTS)
            ->where(['incident' => $incident])
            ->exists();

        if ($exists) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::ALERTS, [
                'incident' => $incident,
                'state' => self::STATE_OK,
                'dateCreated' => $this->now(),
                'dateUpdated' => $this->now(),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\yii\db\IntegrityException) {
            // Another process created it between the check and the insert. The unique index is
            // the point; there is nothing to do.
        }
    }

    /**
     * Every open incident, newest first, labelled — for the widget, the CP banner and the console.
     *
     * @return array<int,array<string,mixed>>
     */
    public function openIncidents(): array
    {
        // Without a connection nothing is checked, so whatever the latch last said is stale.
        if (!Plugin::getInstance()->getSettings()->isConfigured()) {
            return [];
        }

        $rows = (new Query())
            ->from(Table::ALERTS)
            ->where(['state' => self::STATE_OPEN])
            ->orderBy(['openedAt' => SORT_DESC])
            ->all();

        return array_map(static fn(array $row) => $row + ['label' => self::incidentLabel((string)$row['incident'])], $rows);
    }

    /**
     * What the Dashboard widget shows: document counts by state, open incidents, and the last
     * time a document was booked in or read back from Moneybird.
     *
     * @return array{counts:array<string,int>,incidents:array<int,array<string,mixed>>,lastSynced:?DateTime}
     */
    public function overview(): array
    {
        $lastSynced = (new Query())
            ->from(Table::DOCUMENTS)
            ->where(['not', ['moneybirdId' => null]])
            ->max('dateSynced');

        return [
            'counts' => Plugin::getInstance()->getDocuments()->countsByState(),
            'incidents' => $this->openIncidents(),
            'lastSynced' => is_string($lastSynced) ? (DateTimeHelper::toDateTime($lastSynced) ?: null) : null,
        ];
    }

    // Delivery
    // -------------------------------------------------------------------------

    /**
     * Send one alert to every configured channel. True when at least one channel took it — a
     * second email because the webhook failed would be worse than a missing Slack message.
     */
    public function notify(string $incident, bool $recovered, string $detail): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $hasWebhook = $webhookUrl !== '' && !str_starts_with($webhookUrl, '$');

        if ($recipients === [] && !$hasWebhook) {
            return false;
        }

        $message = $this->compose($incident, $recovered, $detail);

        $event = new AlertEvent([
            'incident' => $incident,
            'recovered' => $recovered,
            'detail' => $message['detail'],
            'subject' => $message['subject'],
            'body' => $message['body'],
            'payload' => $this->payload($settings->alertWebhookFormat, $message),
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_NOTIFY)) {
            $this->trigger(self::EVENT_BEFORE_NOTIFY, $event);

            if (!$event->isValid) {
                return true;
            }
        }

        $sent = false;

        if ($recipients !== []) {
            try {
                $sent = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($event->subject)
                    ->setTextBody($event->body)
                    ->send();
            } catch (Throwable $e) {
                Craft::error('Bird could not email an alert: ' . $e->getMessage(), __METHOD__);
            }
        }

        if ($hasWebhook) {
            $result = $this->postWebhook($webhookUrl, $event->payload);

            if ($result === true) {
                $sent = true;
            } else {
                Craft::error('Bird could not post an alert webhook: ' . $result, __METHOD__);
            }
        }

        return $sent;
    }

    /**
     * Send a sample through every channel, for the settings screen's "Send a test alert".
     *
     * @return array{email:?bool,webhook:bool|string|null}
     */
    public function sendTest(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $message = $this->compose('test', false, Craft::t('bird', 'This is a test. If you can read it, Bird alerts will reach you here.'));
        $result = ['email' => null, 'webhook' => null];

        if ($recipients !== []) {
            try {
                $result['email'] = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($message['subject'])
                    ->setTextBody($message['body'])
                    ->send();
            } catch (Throwable $e) {
                Craft::error('Bird could not email a test alert: ' . $e->getMessage(), __METHOD__);
                $result['email'] = false;
            }
        }

        if ($webhookUrl !== '' && !str_starts_with($webhookUrl, '$')) {
            $result['webhook'] = $this->postWebhook($webhookUrl, $this->payload($settings->alertWebhookFormat, $message));
        }

        return $result;
    }

    /**
     * Subject, plain-text body and links for one alert.
     *
     * Plain text on purpose: it may be read on a phone at an inconvenient hour, and it should say
     * what happened and where to go — nothing that needs a rendering engine. `UrlHelper::cpUrl()`
     * rather than a hand-assembled host, because this runs from the queue and the console, where
     * there is no request to read a host from.
     *
     * @return array{subject:string,body:string,title:string,detail:string,url:string,documentsUrl:string,incident:string,recovered:bool,site:string}
     */
    public function compose(string $incident, bool $recovered, string $detail): array
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();
        $label = $incident === 'test' ? Craft::t('bird', 'Test alert') : self::incidentLabel($incident);
        $detail = $this->redact($detail);

        $documentsUrl = UrlHelper::cpUrl('bird/documents');
        $url = match ($incident) {
            self::INCIDENT_AUTH => UrlHelper::cpUrl('settings/plugins/bird'),
            self::INCIDENT_DOCUMENTS => $this->documentsUrl(),
            default => $documentsUrl,
        };

        $title = $recovered
            ? Craft::t('bird', 'Recovered: {label}', ['label' => $label])
            : $label;

        $lines = [
            $recovered
                ? Craft::t('bird', 'Bird on {site}: this has cleared. No action is needed.', ['site' => $site])
                : Craft::t('bird', 'Bird on {site} needs attention.', ['site' => $site]),
            '',
            Craft::t('bird', 'Incident: {label}', ['label' => $label]),
        ];

        if (!$recovered && $detail !== '') {
            $lines[] = '';
            $lines[] = $detail;
        }

        if (!$recovered) {
            $advice = match ($incident) {
                self::INCIDENT_AUTH => Craft::t('bird', 'Nothing reaches Moneybird until the token works again. Create a new token in Moneybird under your profile → Applications, put it in Bird’s settings, and press Test connection.'),
                self::INCIDENT_DOCUMENTS => Craft::t('bird', 'Each one is missing from your books until it is dealt with. The Documents screen says why each failed — usually an unmapped VAT rate, a missing contact or a field Moneybird refused. Fix the cause, then press “Send again” on the order or run `php craft bird/sync/retry`.'),
                self::INCIDENT_STALLED => Craft::t('bird', 'Check that Craft’s queue is running. Orders that were missed can be booked with `php craft bird/sync/backfill`.'),
                default => null,
            };

            if ($advice !== null) {
                $lines[] = '';
                $lines[] = $advice;
            }
        }

        $lines[] = '';
        $lines[] = Craft::t('bird', 'Open: {url}', ['url' => $url]);

        if ($url !== $documentsUrl) {
            $lines[] = Craft::t('bird', 'Documents: {url}', ['url' => $documentsUrl]);
        }

        $lines[] = '';
        $lines[] = Craft::t('bird', 'You get one message when this starts and one when it clears. Change who gets them in Bird → Settings.');

        return [
            'subject' => '[' . $site . '] ' . Craft::t('bird', 'Bird: {title}', ['title' => $title]),
            'body' => implode("\n", $lines) . "\n",
            'title' => $title,
            'detail' => $recovered ? '' : $detail,
            'url' => $url,
            'documentsUrl' => $documentsUrl,
            'incident' => $incident,
            'recovered' => $recovered,
            'site' => $site,
        ];
    }

    /**
     * The Documents screen filtered to the failures.
     */
    private function documentsUrl(): string
    {
        return UrlHelper::cpUrl('bird/documents', ['state' => Document::STATE_FAILED]);
    }

    /**
     * The webhook body in the receiver's own shape.
     *
     * @param array{subject:string,body:string,title:string,detail:string,url:string,documentsUrl:string,incident:string,recovered:bool,site:string} $m
     */
    public function payload(string $format, array $m): array
    {
        $title = 'Bird: ' . $m['title'];
        $text = $title . ($m['detail'] !== '' ? "\n" . $m['detail'] : '');

        return match ($format) {
            'teams' => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => array_values(array_filter([
                            ['type' => 'TextBlock', 'size' => 'Large', 'weight' => 'Bolder', 'color' => $m['recovered'] ? 'Good' : 'Attention', 'text' => $title, 'wrap' => true],
                            $m['detail'] !== '' ? ['type' => 'TextBlock', 'text' => $m['detail'], 'wrap' => true] : null,
                            ['type' => 'FactSet', 'facts' => [['title' => 'Site', 'value' => $m['site']]]],
                        ])),
                        'actions' => [['type' => 'Action.OpenUrl', 'title' => Craft::t('bird', 'Open in Craft'), 'url' => $m['url']]],
                    ],
                ]],
            ],
            'json' => [
                'event' => $m['recovered'] ? 'bird.alert.recovered' : 'bird.alert.opened',
                'incident' => $m['incident'],
                'site' => $m['site'],
                'title' => $m['title'],
                'detail' => $m['detail'],
                'url' => $m['url'],
                'documentsUrl' => $m['documentsUrl'],
                'at' => (new DateTime('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
            default => [
                'text' => $text,
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . $title . '*' . ($m['detail'] !== '' ? "\n" . $m['detail'] : '')]],
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $m['site'] . ' · <' . $m['url'] . '|' . Craft::t('bird', 'Open in Craft') . '>']]],
                ],
            ],
        };
    }

    /**
     * Where an alert webhook may be sent, or why it may not. The family SSRF rules, as in Erpy:
     *
     * 1. `http` and `https` only, with no credentials in the URL.
     * 2. Every address the host resolves to must be public ({@see Ip::resolvePublic()}).
     * 3. The send pins the connection to those addresses with `CURLOPT_RESOLVE`, so a second
     *    lookup at connect time cannot rebind the host somewhere private.
     * 4. Redirects are never followed.
     *
     * `allowPrivateAlertWebhookHosts` (config file only) skips 2 and 3; 1 and 4 still hold.
     *
     * @return array{host:string,port:int,addresses:string[]}|string the pinned target, or the refusal
     */
    public function webhookTarget(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)(is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = (string)(is_array($parts) ? ($parts['host'] ?? '') : '');

        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return Craft::t('bird', 'Only http:// and https:// webhook URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return Craft::t('bird', 'Webhook URLs may not carry a username or password.');
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (Plugin::getInstance()->getSettings()->allowPrivateAlertWebhookHosts) {
            return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => []];
        }

        $addresses = Ip::resolvePublic($host);

        if ($addresses === []) {
            return Craft::t('bird', 'That host doesn’t resolve, or resolves to a private, loopback or link-local address. Alert webhooks only go to public addresses.');
        }

        return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => $addresses];
    }

    /**
     * POST the payload. True on a 2xx, otherwise the reason — never an exception.
     */
    public function postWebhook(string $url, array $payload): bool|string
    {
        $target = $this->webhookTarget($url);

        if (is_string($target)) {
            return $target;
        }

        $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Bird alerts'];
        $secret = trim((string)App::parseEnv(Plugin::getInstance()->getSettings()->alertWebhookSecret));

        if ($secret !== '' && !str_starts_with($secret, '$')) {
            $timestamp = (string)time();
            $headers['X-Bird-Timestamp'] = $timestamp;
            $headers['X-Bird-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }

        $options = [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
        ];

        if ($target['addresses'] !== []) {
            // One entry per host:port, addresses comma-joined — one entry per address would leave
            // only the last one pinned.
            $options['curl'][CURLOPT_RESOLVE] = [sprintf(
                '%s:%d:%s',
                $target['host'],
                $target['port'],
                implode(',', array_map(static fn(string $ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $target['addresses'])),
            )];
        }

        try {
            $client = $this->webhookClient ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
            $response = $client->request('POST', $url, $options);
            $status = $response->getStatusCode();

            return $status >= 200 && $status < 300 ? true : 'HTTP ' . $status;
        } catch (Throwable $e) {
            // A Slack or Teams webhook URL is itself the credential; the reason ends up in logs.
            return str_replace($url, $target['host'], $e->getMessage());
        }
    }

    /**
     * Take anything secret out of a line that is about to leave the building.
     *
     * An alert quotes Moneybird's error messages, and goes somewhere — a mailbox, a chat channel —
     * that outlives the token. So: the API token, the Moneybird webhook's signing secret and the
     * alert webhook's secret by value, anything shaped like a credential by pattern, tags stripped,
     * and a length cap so a stack trace or a payload cannot ride along.
     */
    public function redact(string $text): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $secrets = [
            $settings->getParsedApiToken(),
            trim((string)App::parseEnv($settings->alertWebhookSecret)),
        ];

        try {
            $secrets[] = Plugin::getInstance()->getWebhooks()->signingSecret();
        } catch (Throwable) {
            // An undecryptable secret is not one that can leak.
        }

        foreach ($secrets as $secret) {
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, '••••', $text);
            }
        }

        $text = (string)preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9\-._~+\/=]{6,}/i', '$1 ••••', $text);
        $text = (string)preg_replace(
            '/(["\']?\b(?:password|passwd|pwd|secret|client_secret|api[_-]?key|apikey|access_token|refresh_token|token|signature|sig)\b["\']?\s*[:=]\s*["\']?)[^"\'&\s,;}]+/i',
            '$1••••',
            $text,
        );
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 499) . '…' : $text;
    }

    private function now(): string
    {
        return (string)Db::prepareDateForDb(new DateTime());
    }
}
