<?php

namespace justinholtweb\bird\models;

use craft\base\Model;
use DateTime;

/**
 * One row in the Moneybird connection log.
 */
class LogEntry extends Model
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public ?int $id = null;

    /**
     * `api`, `invoice`, `credit`, `contact`, `payment`, `webhook` or `sync`.
     */
    public string $action = '';

    public string $level = self::LEVEL_INFO;
    public ?int $statusCode = null;
    public ?int $durationMs = null;
    public ?int $orderId = null;
    public ?string $ip = null;
    public ?string $summary = null;
    public ?string $message = null;
    public ?string $request = null;
    public ?string $response = null;
    public ?DateTime $dateCreated = null;

    /**
     * Present because `Log::getEntryById()` reads the whole row, and Yii treats an unknown key as
     * an unknown property rather than ignoring it.
     */
    public ?DateTime $dateUpdated = null;

    public ?string $uid = null;
}
