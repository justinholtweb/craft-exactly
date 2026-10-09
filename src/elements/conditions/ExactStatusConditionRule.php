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
     * Keeps what was chosen, as strings — including a status a later release renamed or removed.
     * Stripping it here would turn "is one of [a removed status]" into "no filter" (a saved custom
     * source silently widening to every order), and the next save of the source would lose the
     * value for good. Only {@see knownValues()} ever reaches the query.
     *
     * @param string|string[] $values
     */
    public function setValues(array|string $values): void
    {
        $values = array_filter((array)$values, static fn(mixed $value): bool => is_scalar($value) && (string)$value !== '');

        parent::setValues(array_values(array_unique(array_map('strval', $values))));
    }

    /**
     * The chosen statuses Exactly still knows. A hand-edited or stale condition cannot smuggle
     * anything else into the query.
     *
     * @return string[]
     */
    private function knownValues(): array
    {
        return array_values(array_intersect($this->getValues(), array_keys(Documents::orderStatusOptions())));
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
        // Nothing chosen is no filter — Craft's convention for an empty rule.
        if ($this->getValues() === []) {
            return;
        }

        $values = $this->knownValues();

        // Chosen, but none of it exists any more: "is one of" matches nothing, "is not one of"
        // excludes nothing. Never no filter for "is one of".
        if ($values === []) {
            if ($this->operator !== self::OPERATOR_NOT_IN) {
                $query->andWhere('0=1');
            }

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
        if ($this->getValues() === []) {
            return true;
        }

        // Agrees with modifyQuery(): only known statuses count.
        $known = $this->knownValues();

        if (!$element instanceof Order || !$element->id) {
            $status = Document::ORDER_NONE;
        } else {
            $status = Plugin::getInstance()->getDocuments()->orderStatuses([$element->id])[$element->id] ?? Document::ORDER_NONE;
        }

        $in = in_array($status, $known, true);

        return $this->operator === self::OPERATOR_NOT_IN ? !$in : $in;
    }
}
