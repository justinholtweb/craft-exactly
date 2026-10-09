<?php

namespace justinholtweb\exactly\elements\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;
use justinholtweb\exactly\services\Documents;

/**
 * "Exact Online status" on Commerce's Orders index filters (and anywhere else an order condition
 * is built: custom sources, discounts, shipping rules) — so a merchant can build a "Not yet
 * invoiced" or "Failed in Exact" source.
 *
 * The query side and the element side are both {@see Documents}' order-status sets, so a custom
 * source and the Exact column on the same rows can never disagree.
 *
 * Registered unconditionally — never behind a setting or a permission: Craft drops an
 * unregistered rule from a saved condition, and a custom source would silently widen to every
 * order.
 */
class ExactStatusConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('exactly', 'Exact Online status');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    /**
     * Only the statuses Exactly knows: a hand-edited or stale condition cannot smuggle anything
     * else into the query.
     *
     * @param string|string[] $values
     */
    public function setValues(array|string $values): void
    {
        $known = array_keys(Documents::orderStatusOptions());

        parent::setValues(array_values(array_intersect((array)$values, $known)));
    }

    /**
     * @inheritdoc
     */
    protected function options(): array
    {
        $options = [];

        foreach (Documents::orderStatusOptions() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        $values = $this->getValues();

        if ($values === []) {
            return;
        }

        $documents = Plugin::getInstance()->getDocuments();
        $condition = ['or'];

        foreach ($values as $status) {
            $condition[] = $documents->orderStatusCondition($status);
        }

        $query->andWhere($this->operator === self::OPERATOR_NOT_IN ? ['not', $condition] : $condition);
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        if (!$element instanceof Order || !$element->id) {
            return $this->matchValue(Document::ORDER_NONE);
        }

        $status = Plugin::getInstance()->getDocuments()->orderStatuses([$element->id])[$element->id] ?? Document::ORDER_NONE;

        return $this->matchValue($status);
    }
}
