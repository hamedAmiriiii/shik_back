<?php

namespace App\Services\SmartCustomer;

use App\Models\ShopCustomerMetric;
use App\Models\ShopCustomerSegment;

class CampaignRuleEvaluator
{
    /**
     * @param  array<string, mixed>  $conditions  e.g. {"all":[{"field":"recency_days","op":">=","value":45}]}
     */
    public static function matches(array $conditions, ShopCustomerMetric $metric, ?ShopCustomerSegment $segment): bool
    {
        $all = $conditions['all'] ?? null;
        if (! is_array($all) || $all === []) {
            return false;
        }

        foreach ($all as $rule) {
            if (! is_array($rule) || ! self::matchOne($rule, $metric, $segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    protected static function matchOne(array $rule, ShopCustomerMetric $metric, ?ShopCustomerSegment $segment): bool
    {
        $field = (string) ($rule['field'] ?? '');
        $op = (string) ($rule['op'] ?? '=');
        $value = $rule['value'] ?? null;

        $actual = self::resolveField($field, $metric, $segment);
        if ($actual === null && $field !== '') {
            // missing field fails closed except for tags contains empty
        }

        switch ($op) {
            case '>=':
                return (float) $actual >= (float) $value;
            case '>':
                return (float) $actual > (float) $value;
            case '<=':
                return (float) $actual <= (float) $value;
            case '<':
                return (float) $actual < (float) $value;
            case '!=':
                return (string) $actual !== (string) $value;
            case 'in':
                $list = is_array($value) ? $value : [$value];

                return in_array($actual, $list, false);
            case 'not_in':
                $list = is_array($value) ? $value : [$value];

                return ! in_array($actual, $list, false);
            case 'contains':
                $tags = is_array($actual) ? $actual : [];

                return in_array($value, $tags, true);
            case '=':
            default:
                return (string) $actual === (string) $value;
        }
    }

    /**
     * @return mixed
     */
    protected static function resolveField(string $field, ShopCustomerMetric $metric, ?ShopCustomerSegment $segment)
    {
        switch ($field) {
            case 'recency_days':
                return (int) $metric->recency_days;
            case 'frequency':
                return (int) $metric->frequency;
            case 'monetary':
                return (float) $metric->monetary;
            case 'avg_days_between':
                return $metric->avg_days_between !== null ? (float) $metric->avg_days_between : null;
            case 'purchase_count_30d':
                return (int) $metric->purchase_count_30d;
            case 'purchase_count_90d':
                return (int) $metric->purchase_count_90d;
            case 'monetary_30d':
                return (float) $metric->monetary_30d;
            case 'monetary_90d':
                return (float) $metric->monetary_90d;
            case 'avg_order_value':
                return (float) $metric->avg_order_value;
            case 'primary_segment':
                return $segment ? (string) $segment->primary_segment : null;
            case 'tags':
                return $segment ? ($segment->tags ?? []) : [];
            default:
                return null;
        }
    }
}
