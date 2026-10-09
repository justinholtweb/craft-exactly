<?php

namespace justinholtweb\exactly\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\exactly\Plugin;

/**
 * A Dashboard tile: whether Exactly is connected, how much of the store is in the books, and any
 * open alert.
 *
 * The Documents screen is where the detail lives; this is what makes somebody go there. It shows
 * the same latch rows the alert emails come from, so the tile and the inbox cannot disagree.
 */
class HealthWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('exactly', 'Exact Online health');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/exactly/icon-mask.svg');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('exactly-viewDocuments');
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): string
    {
        return Craft::t('exactly', 'Exact Online health');
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        // A widget outlives the permission that let somebody add it.
        if (!Craft::$app->getUser()->checkPermission('exactly-viewDocuments')) {
            return null;
        }

        return Craft::$app->getView()->renderTemplate('exactly/_widgets/health', [
            'overview' => Plugin::getInstance()->getAlerts()->overview(),
            'canSeeDocuments' => Craft::$app->getUser()->checkPermission('commerce-manageOrders'),
        ]);
    }
}
