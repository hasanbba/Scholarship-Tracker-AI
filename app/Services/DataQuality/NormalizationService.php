<?php

namespace App\Services\DataQuality;

use Illuminate\Validation\ValidationException;

class NormalizationService
{
    public const VERSION = '1';

    public function normalize(array $input): array
    {
        $scholarship = $input['scholarship'] ?? [];
        $cycle = $input['cycle'] ?? [];
        $result = [
            'provided_fields' => [
                'scholarship' => array_values(array_intersect(['university_id', 'title', 'description', 'official_url'], array_keys($scholarship))),
                'cycle' => array_values(array_intersect(['cycle_key', 'label', 'opening_date', 'deadline', 'application_url'], array_keys($cycle))),
                'subjects' => array_key_exists('subjects', $input),
                'funding' => array_values(array_intersect(array_keys($input['funding'] ?? []), ['classification', 'notes', 'tuition_amount', 'tuition_currency', 'tuition_period', 'stipend_amount', 'stipend_currency', 'stipend_period', 'accommodation_amount', 'accommodation_currency', 'accommodation_period', 'health_insurance_amount', 'health_insurance_currency', 'health_insurance_period', 'travel_amount', 'travel_currency', 'travel_period', 'visa_amount', 'visa_currency', 'visa_period', 'research_grant_amount', 'research_grant_currency', 'research_grant_period', 'application_fee_amount', 'application_fee_currency', 'application_fee_period', 'other_benefits_amount', 'other_benefits_currency', 'other_benefits_period'])),
                'eligibility_rules' => array_key_exists('eligibility_rules', $input),
            ],
            'scholarship' => [
                'university_id' => isset($scholarship['university_id']) ? (int) $scholarship['university_id'] : null,
                'title' => $this->text($scholarship['title'] ?? null),
                'description' => $this->text($scholarship['description'] ?? null),
                'official_url' => $this->url($scholarship['official_url'] ?? null),
            ],
            'cycle' => [
                'cycle_key' => $this->text($cycle['cycle_key'] ?? null),
                'label' => $this->text($cycle['label'] ?? null),
                'opening_date' => $this->date($cycle['opening_date'] ?? null),
                'deadline' => $this->date($cycle['deadline'] ?? null),
                'application_url' => $this->url($cycle['application_url'] ?? null),
            ],
            'subjects' => array_values(array_unique(array_map('intval', $input['subjects'] ?? []))),
            'funding' => $this->normalizeFunding($input['funding'] ?? []),
            'eligibility_rules' => array_key_exists('eligibility_rules', $input) ? $this->normalizeRules($input['eligibility_rules']) : null,
        ];

        return $result;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function url(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $parts = parse_url(trim((string) $value));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw ValidationException::withMessages(['candidate' => 'Candidate URLs must be absolute.']);
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $authority = $host;
        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $authority .= ':'.$port;
        }

        return $scheme.'://'.$authority.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable((string) $value);
            $formatted = $date->format('Y-m-d');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) && $formatted !== $value) {
                throw new \InvalidArgumentException;
            }

            return $formatted;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['candidate' => 'Dates must be valid calendar dates.']);
        }
    }

    private function normalizeFunding(array $funding): array
    {
        $result = [];
        foreach ($funding as $key => $value) {
            if (str_ends_with($key, '_amount') && $value !== null) {
                $result[$key] = $this->decimal((string) $value);
            } elseif (str_ends_with($key, '_currency') && is_string($value)) {
                $result[$key] = strtoupper(trim($value));
            } elseif (is_string($value)) {
                $result[$key] = $this->text($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function decimal(string $value): string
    {
        if (! preg_match('/^-?\d{1,13}(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['candidate' => 'Funding amounts must be exact decimal values with at most two fractional digits.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    private function normalizeRules(array $rules): array
    {
        return array_values(array_map(function (array $rule): array {
            $rule = array_intersect_key($rule, array_flip(['rule_type', 'operator', 'normalized_value', 'unit', 'display_text', 'degree_id', 'subject_id', 'country_id', 'metadata']));
            $rule['rule_type'] = strtolower(trim((string) ($rule['rule_type'] ?? '')));
            $rule['operator'] = strtolower(trim((string) ($rule['operator'] ?? '')));
            $rule['operator'] = ['eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$rule['operator']] ?? $rule['operator'];
            $rule['unit'] = isset($rule['unit']) ? $this->text($rule['unit']) : null;
            $rule['display_text'] = isset($rule['display_text']) ? $this->text($rule['display_text']) : null;
            foreach (['degree_id', 'subject_id', 'country_id'] as $foreignKey) {
                if (isset($rule[$foreignKey])) {
                    $rule[$foreignKey] = (int) $rule[$foreignKey];
                }
            }
            $rule['metadata'] = isset($rule['metadata']) && is_array($rule['metadata']) ? $rule['metadata'] : null;

            return $rule;
        }, $rules));
    }
}
