<?php

namespace justinholtweb\bird\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bird\Plugin;
use yii\console\ExitCode;

/**
 * Housekeeping for the connection log.
 */
class LogController extends Controller
{
    /**
     * Days to keep. 0 uses the configured retention.
     */
    public int $days = 0;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return $actionID === 'prune' ? array_merge($options, ['days']) : $options;
    }

    /**
     * Drop entries older than the retention period. Worth putting on a cron.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->getLog()->prune($this->days > 0 ? $this->days : null);

        $this->stdout("Removed $deleted log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Empty the log.
     */
    public function actionClear(): int
    {
        $deleted = Plugin::getInstance()->getLog()->clear();

        $this->stdout("Removed $deleted log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
