<?php

namespace justinholtweb\bird\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\bird\Plugin;

/**
 * A Dashboard tile: documents by state, each linked to the Documents screen filtered to it, plus
 * any open alert.
 *
 * The Documents screen is where the detail lives; this is what makes somebody go there. It reads
 * the same latch rows the alert emails come from, so the tile and the inbox cannot disagree.
 */
class HealthWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('bird', 'Moneybird bookings');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/bird/icon-mask.svg');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('bird-viewDocuments');
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): string
    {
        return Craft::t('bird', 'Moneybird bookings');
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        // A widget outlives the permission that let somebody add it.
        if (!Craft::$app->getUser()->checkPermission('bird-viewDocuments')) {
            return null;
        }

        return Craft::$app->getView()->renderTemplate('bird/_widgets/health', [
            'overview' => Plugin::getInstance()->getAlerts()->overview(),
        ]);
    }
}
