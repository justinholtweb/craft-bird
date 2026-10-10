<?php

namespace justinholtweb\bird\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\bird\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log in the control panel.
 *
 * Pro only — Lite still writes a short tail of entries so a support question has an answer, it
 * just has no screen for them.
 */
class LogController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('bird-viewLog');

        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException('The connection log requires Bird Pro.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();

        $criteria = [
            'action' => $request->getParam('action_filter'),
            'level' => $request->getParam('level'),
        ];

        return $this->renderTemplate('bird/log/_index', [
            'entries' => Plugin::getInstance()->getLog()->getEntries($criteria, 200),
            'criteria' => $criteria,
            'total' => Plugin::getInstance()->getLog()->count(),
        ]);
    }

    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->getEntryById($entryId);

        if ($entry === null) {
            throw new NotFoundHttpException('Log entry not found');
        }

        return $this->renderTemplate('bird/log/_detail', [
            'entry' => $entry,
        ]);
    }

    /**
     * Pruning and clearing destroy the audit trail of what was sent to Moneybird and what came
     * back, so they are an admin's call (as in craft-freshh). Before 5.0.2 "View the connection
     * log" was enough to wipe it.
     */
    public function actionPrune(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);

        $days = (int)Craft::$app->getRequest()->getBodyParam('days', 0);
        $deleted = Plugin::getInstance()->getLog()->prune($days > 0 ? $days : null);

        return $this->asSuccess(Craft::t('bird', 'Removed {count} log entries.', ['count' => $deleted]));
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);

        $deleted = Plugin::getInstance()->getLog()->clear();

        return $this->asSuccess(Craft::t('bird', 'Removed {count} log entries.', ['count' => $deleted]));
    }
}
