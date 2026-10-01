<?php

namespace App\Services\DataQuality;

use App\Models\Country;
use App\Models\Degree;
use App\Models\RawObservation;
use App\Models\Subject;
use App\Models\University;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** Deterministic, evidence-preserving HTML extraction. No network access is performed. */
class HtmlScholarshipExtractor
{
    public const VERSION = 'html-v1';

    private const FIELD_PATHS = [
        'scholarship.title', 'scholarship.description', 'scholarship.deadline', 'scholarship.opening_date',
        'scholarship.application_url', 'scholarship.university', 'scholarship.country', 'scholarship.region',
        'scholarship.degree', 'scholarship.subject', 'funding.tuition', 'funding.stipend', 'funding.living_expenses',
        'funding.accommodation', 'funding.insurance', 'funding.travel', 'funding.visa', 'funding.research_grant',
        'funding.application_fee', 'funding.other', 'eligibility.nationality', 'eligibility.degree',
        'eligibility.subject', 'eligibility.gpa', 'eligibility.percentage', 'eligibility.ielts', 'eligibility.toefl',
        'eligibility.pte', 'eligibility.duolingo', 'eligibility.gre', 'eligibility.gmat', 'eligibility.age',
        'eligibility.work_experience', 'eligibility.graduation_year', 'eligibility.other',
    ];

    public function extract(string $html, RawObservation $observation): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return $this->emptyResult($observation, [['code' => 'malformed_html', 'status' => 'invalid', 'field' => 'observation']]);
        }

        $xpath = new DOMXPath($document);
        $jsonLd = $this->jsonLd($xpath);
        foreach ($xpath->query('//script|//style|//nav|//footer|//noscript|//*[contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"cookie")]|//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"tracking")]') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $h1Title = $this->candidate($this->firstText($xpath, '//h1[1]'), 'visible_html', '//h1[1]');
        $titleValues = $this->uniqueCandidates($h1Title !== null
            ? [$h1Title, $this->candidate($jsonLd['name'] ?? $jsonLd['headline'] ?? null, 'json_ld', 'script[type="application/ld+json"]#name')]
            : [$this->candidate($this->meta($xpath, 'property', 'og:title'), 'html_metadata', 'meta[property="og:title"]'),
                $this->candidate($this->firstText($xpath, '//title[1]'), 'html_title', '//title[1]'),
                $this->candidate($jsonLd['name'] ?? $jsonLd['headline'] ?? null, 'json_ld', 'script[type="application/ld+json"]#name')]);
        $metaDescription = $this->candidate($this->meta($xpath, 'name', 'description'), 'html_metadata', 'meta[name="description"]');
        $jsonDescription = $this->candidate($jsonLd['description'] ?? null, 'json_ld', 'script[type="application/ld+json"]#description');
        $descriptionValues = $this->uniqueCandidates($metaDescription !== null
            ? [$metaDescription, $jsonDescription]
            : [$jsonDescription, $this->candidate($this->sectionText($xpath, ['description', 'about this scholarship', 'overview']), 'section_text', 'heading-section:description')]);
        $deadlineCandidates = $this->dateCandidates($xpath, ['deadline', 'application closes', 'closing date', 'applications close', 'last date to apply']);
        $openingCandidates = $this->dateCandidates($xpath, ['opening date', 'applications open', 'application opens']);
        if (is_string($jsonLd['applicationDeadline'] ?? null)) {
            $parsed = $this->parseDate($jsonLd['applicationDeadline']);
            $deadlineCandidates[] = ['value' => $jsonLd['applicationDeadline'], 'normalized' => $parsed['date'], 'status' => $parsed['status'], 'method' => 'json_ld', 'locator' => 'script[type="application/ld+json"]#applicationDeadline'];
        }
        if (is_string($jsonLd['startDate'] ?? null)) {
            $parsed = $this->parseDate($jsonLd['startDate']);
            $openingCandidates[] = ['value' => $jsonLd['startDate'], 'normalized' => $parsed['date'], 'status' => $parsed['status'], 'method' => 'json_ld', 'locator' => 'script[type="application/ld+json"]#startDate'];
        }

        $issues = [];
        $fields = [];
        $resolved = [];
        foreach (self::FIELD_PATHS as $path) {
            $fields[$path] = ['path' => $path, 'raw_value' => null, 'normalized_value' => null, 'status' => 'missing', 'method' => 'deterministic_label_parser', 'parser_version' => self::VERSION, 'evidence' => null];
        }
        foreach (['scholarship.title' => $titleValues, 'scholarship.description' => $descriptionValues, 'scholarship.deadline' => $deadlineCandidates, 'scholarship.opening_date' => $openingCandidates] as $path => $candidates) {
            $chosen = $this->choose($path, $candidates, $issues);
            if ($chosen !== null) {
                $normalized = array_key_exists('normalized', $chosen) ? $chosen['normalized'] : $chosen['value'];
                $resolved[$path] = $this->field($path, $chosen['value'], $normalized, $chosen['status'] ?? 'extracted', $chosen['method'], $chosen['locator']);
                if (($chosen['status'] ?? null) === 'invalid') $resolved[$path]['issue'] = 'invalid_date';
            }
        }

        $universityId = $observation->source?->university_id ?? $observation->source?->scholarship?->university_id;
        $universityText = $this->firstContextValue($xpath, ['university', 'institution', 'host institution']);
        if (! $universityId && $universityText !== null) {
            $universityId = University::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($universityText)])->where('status', 'active')->value('id');
        }
        if ($universityId) $resolved['scholarship.university'] = $this->field('scholarship.university', $universityText ?? $observation->source?->university?->name, (int) $universityId, 'normalized', 'source_catalog_exact', 'source.university_id');
        elseif ($universityText !== null) $resolved['scholarship.university'] = $this->field('scholarship.university', $universityText, null, 'uncertain', 'deterministic_label_parser', 'label-nearby-value:university');
        $country = $observation->source?->university?->country;
        if ($country) {
            $resolved['scholarship.country'] = $this->field('scholarship.country', $country->name, (int) $country->id, 'normalized', 'university_catalog_relationship', 'source.university.country_id');
            if ($country->region) $resolved['scholarship.region'] = $this->field('scholarship.region', $country->region->name, (int) $country->region->id, 'normalized', 'university_catalog_relationship', 'country.region_id');
        }

        $application = $this->applicationLink($xpath, $observation->observed_url);
        if (($application['selected'] ?? null) !== null) {
            $item = $application['selected'];
            $resolved['scholarship.application_url'] = $this->field('scholarship.application_url', $item['value'], $item['value'], 'normalized', $item['method'], $item['locator']);
        } elseif ($application['ambiguous'] ?? false) {
            $issues[] = ['field' => 'scholarship.application_url', 'code' => 'application_url_ambiguous', 'status' => 'uncertain', 'competing_values' => array_column($application['candidates'], 'value'), 'evidence' => array_column($application['candidates'], 'locator')];
            $resolved['scholarship.application_url'] = $this->field('scholarship.application_url', implode(' | ', array_column($application['candidates'], 'value')), null, 'uncertain', 'application_link_ambiguity', array_column($application['candidates'], 'locator'));
        }

        $cycleKey = $this->cycleKey($xpath);
        if ($cycleKey !== null) $resolved['scholarship.cycle_key'] = $this->field('scholarship.cycle_key', $cycleKey['value'], $cycleKey['value'], 'normalized', $cycleKey['method'], $cycleKey['locator']);
        else $issues[] = ['field' => 'cycle.cycle_key', 'code' => 'cycle_key_not_explicit', 'status' => 'missing', 'reason' => 'No explicit application cycle was found.'];

        $funding = $this->funding($xpath);
        foreach ($funding['fields'] as $path => $field) $resolved[$path] = $field;
        array_push($issues, ...$funding['issues']);
        $eligibility = $this->eligibility($xpath);
        foreach ($eligibility['fields'] as $path => $field) $resolved[$path] = $field;

        $subjectText = $this->firstContextValue($xpath, ['subject', 'field of study', 'eligible disciplines', 'eligible fields']);
        $subjectId = $subjectText !== null ? Subject::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($subjectText)])->where('status', 'active')->value('id') : null;
        if ($subjectText !== null) $resolved['scholarship.subject'] = $this->field('scholarship.subject', $subjectText, $subjectId ? (int) $subjectId : null, $subjectId ? 'normalized' : 'uncertain', $subjectId ? 'catalog_exact_match' : 'deterministic_label_parser', 'label-nearby-value:subject');
        if ($subjectText !== null && $subjectId) $eligibility['rules'][] = ['rule_type' => 'subject', 'operator' => 'eq', 'normalized_value' => $subjectText, 'subject_id' => (int) $subjectId, 'display_text' => $subjectText];

        $degreeText = $this->firstContextValue($xpath, ['degree', 'level of study', 'study level']);
        $degree = $degreeText !== null ? $this->degree($degreeText) : null;
        if ($degreeText !== null) $resolved['scholarship.degree'] = $this->field('scholarship.degree', $degreeText, $degree['id'] ?? null, $degree ? 'normalized' : 'uncertain', $degree ? 'degree_alias_exact' : 'deterministic_label_parser', 'label-nearby-value:degree');
        if ($degree) $eligibility['rules'][] = ['rule_type' => 'degree', 'operator' => 'eq', 'normalized_value' => (int) $degree['id'], 'degree_id' => (int) $degree['id'], 'display_text' => $degreeText];
        $resolved = array_replace($resolved, $eligibility['fields']);

        $candidate = [
            'scholarship' => [
                'university_id' => $universityId ? (int) $universityId : null,
                'title' => $resolved['scholarship.title']['normalized_value'] ?? null,
                'official_url' => $observation->observed_url,
            ],
            'cycle' => [
                'cycle_key' => $cycleKey['value'] ?? null,
                'label' => $cycleKey['value'] ?? null,
            ],
            'funding' => $funding['candidate'],
        ];
        if (isset($resolved['scholarship.description']['normalized_value'])) $candidate['scholarship']['description'] = $resolved['scholarship.description']['normalized_value'];
        if (isset($resolved['scholarship.opening_date']['normalized_value'])) $candidate['cycle']['opening_date'] = $resolved['scholarship.opening_date']['normalized_value'];
        if (isset($resolved['scholarship.deadline']['normalized_value'])) $candidate['cycle']['deadline'] = $resolved['scholarship.deadline']['normalized_value'];
        if (($application['selected'] ?? null) !== null) $candidate['cycle']['application_url'] = $application['selected']['value'];
        if ($subjectId) $candidate['subjects'] = [(int) $subjectId];
        if ($eligibility['rules'] !== []) $candidate['eligibility_rules'] = $eligibility['rules'];
        $candidate['field_statuses'] = array_map(fn (array $field): array => ['status' => $field['status'], 'raw_value' => $field['raw_value']], $resolved);
        $provided = [
            'scholarship' => ['official_url'],
            'cycle' => [],
            'subjects' => $subjectId !== null,
            'funding' => array_keys($funding['candidate']),
            'eligibility_rules' => $eligibility['rules'] !== [],
        ];
        if (isset($resolved['scholarship.description'])) $provided['scholarship'][] = 'description';
        if (! empty($resolved['scholarship.title']['normalized_value'])) $provided['scholarship'][] = 'title';
        if ($cycleKey !== null) array_push($provided['cycle'], 'cycle_key', 'label');
        if (! empty($resolved['scholarship.opening_date']['normalized_value'])) $provided['cycle'][] = 'opening_date';
        if (! empty($resolved['scholarship.deadline']['normalized_value'])) $provided['cycle'][] = 'deadline';
        if (($application['selected'] ?? null) !== null) $provided['cycle'][] = 'application_url';
        $candidate['provided_fields'] = $provided;

        foreach ($resolved as $path => $field) $fields[$path] = $field;
        foreach ($fields as &$field) {
            $field['evidence'] = $field['evidence'] ? array_merge($field['evidence'], [
                'source_url' => $observation->observed_url,
                'observation_id' => $observation->id,
                'artifact_ref' => $observation->artifact_ref,
                'content_hash' => $observation->content_hash,
            ]) : null;
        }
        unset($field);
        $fields['scholarship.official_url'] = $this->field('scholarship.official_url', $observation->observed_url, $observation->observed_url, 'extracted', 'observation_url', 'observation.observed_url');
        $fields['scholarship.official_url']['evidence'] = array_merge($fields['scholarship.official_url']['evidence'], ['source_url' => $observation->observed_url, 'observation_id' => $observation->id, 'artifact_ref' => $observation->artifact_ref, 'content_hash' => $observation->content_hash]);

        return ['candidate' => $candidate, 'fields' => array_values($fields), 'issues' => $issues, 'source_url' => $observation->observed_url, 'observation_id' => $observation->id, 'method' => 'deterministic_html', 'parser_version' => self::VERSION];
    }

    private function emptyResult(RawObservation $observation, array $issues): array
    {
        return ['candidate' => ['scholarship' => [], 'cycle' => [], 'funding' => [], 'eligibility_rules' => [], 'subjects' => [], 'provided_fields' => []], 'fields' => [], 'issues' => $issues, 'source_url' => $observation->observed_url, 'observation_id' => $observation->id, 'method' => 'deterministic_html', 'parser_version' => self::VERSION];
    }

    private function jsonLd(DOMXPath $xpath): array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            try { $data = json_decode($node->textContent, true, 64, JSON_THROW_ON_ERROR); } catch (\Throwable) { continue; }
            foreach ($this->walkJsonLd(is_array($data) ? $data : []) as $item) {
                if (isset($item['name']) || isset($item['headline']) || isset($item['description'])) return $item;
            }
        }
        return [];
    }

    private function walkJsonLd(array $data): array
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) return array_merge(...array_map(fn ($item) => $this->walkJsonLd((array) $item), $data['@graph']));
        if (array_is_list($data)) return array_merge(...array_map(fn ($item) => $this->walkJsonLd((array) $item), $data));
        return [$data];
    }

    private function dateCandidates(DOMXPath $xpath, array $labels): array
    {
        $candidates = [];
        $nodes = $xpath->query('//h1|//h2|//h3|//h4|//p|//li|//tr|//dt|//dd');
        foreach ($nodes ?: [] as $node) {
            $text = $this->clean($node->textContent);
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'tr') {
                $cells = $xpath->query('./th|./td', $node);
                if ($cells && $cells->length >= 2) $text = $this->clean($cells->item(0)->textContent).' : '.$this->clean($cells->item(1)->textContent);
            }
            if (! $this->hasLabel($text, $labels)) continue;
            if (in_array(strtolower($node->nodeName), ['h1', 'h2', 'h3', 'h4'], true)) $text .= ' '.$this->nextElementText($node);
            if (in_array(strtolower($node->nodeName), ['dt', 'h1', 'h2', 'h3', 'h4'], true) && $node->nextSibling) {
                $text .= ' '.$this->clean($node->nextSibling->textContent);
            }
            $rawDate = $this->dateText($text);
            if ($rawDate === null) continue;
            $parsed = $this->parseDate($rawDate);
            $candidates[] = ['value' => $rawDate, 'normalized' => $parsed['date'] ?? null, 'status' => $parsed['status'], 'method' => 'deterministic_label_parser', 'locator' => $this->locator($node)];
        }
        return $candidates;
    }

    private function parseDate(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $slash)) {
            if ((int) $slash[1] > 12 && (int) $slash[2] <= 12) $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $raw);
            elseif ((int) $slash[2] > 12 && (int) $slash[1] <= 12) $date = \DateTimeImmutable::createFromFormat('!m/d/Y', $raw);
            else return ['status' => 'uncertain', 'date' => null];
            $errors = \DateTimeImmutable::getLastErrors();
            return $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                ? ['status' => 'normalized', 'date' => $date->format('Y-m-d')]
                : ['status' => 'invalid', 'date' => null];
        }
        if (preg_match('/\b(?:next|this)\s+(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/i', $raw)) return ['status' => 'uncertain', 'date' => null];
        foreach (['!j F Y', '!F j, Y', '!Y-m-d', '!d-m-Y', '!j M Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format(ltrim($format, '!')) === $raw) return ['status' => 'normalized', 'date' => $date->format('Y-m-d')];
        }
        if (preg_match('/^(?:\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}-\d{1,2}-\d{4})$/i', $raw)) return ['status' => 'invalid', 'date' => null];
        return ['status' => 'uncertain', 'date' => null];
    }

    private function dateText(string $text): ?string
    {
        if (preg_match('/\b(\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})\b/i', $text, $match)) return $match[1];
        if (preg_match('/\b((?:next|this)\s+(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday))\b/i', $text, $relative)) return $relative[1];
        return null;
    }

    private function funding(DOMXPath $xpath): array
    {
        $text = $this->contentText($xpath);
        $mapping = ['tuition' => 'tuition', 'stipend' => 'stipend', 'living expenses' => 'living_expenses', 'accommodation' => 'accommodation', 'insurance' => 'health_insurance', 'travel' => 'travel', 'visa' => 'visa', 'research grant' => 'research_grant', 'application fee' => 'application_fee', 'other benefits' => 'other_benefits'];
        $candidate = [];
        $fields = [];
        $issues = [];
        foreach ($mapping as $label => $fieldName) {
            if (! preg_match('/\b'.preg_quote($label, '/').'\b[^\n.;]{0,100}/i', $text, $match, PREG_OFFSET_CAPTURE)) continue;
            $raw = trim($match[0][0]);
            $locator = 'text-offset:'.$match[0][1];
            $amountPath = 'funding.'.$fieldName;
            $parsed = $this->money($raw);
            if (str_contains(mb_strtolower($raw), 'full tuition') || str_contains(mb_strtolower($raw), 'tuition fee waiver')) {
                $candidate['classification'] = 'tuition_only';
                $candidate['notes'] = trim(($candidate['notes'] ?? '').' '.$raw);
                $fields[$amountPath] = $this->field($amountPath, $raw, ['coverage' => 'full', 'maximum' => false], 'normalized', 'deterministic_funding_label', $locator);
            } elseif ($parsed !== null) {
                if ($parsed['invalid']) {
                    $issues[] = ['field' => $amountPath, 'code' => 'funding_amount_out_of_range', 'status' => 'invalid', 'competing_values' => [$raw], 'evidence' => [$locator]];
                    $fields[$amountPath] = $this->field($amountPath, $raw, null, 'invalid', 'deterministic_funding_label', $locator);
                    $fields[$amountPath]['issue'] = 'funding_amount_out_of_range';
                } else {
                    unset($parsed['invalid']);
                    if ($fieldName !== 'living_expenses') {
                        $candidate[$fieldName.'_amount'] = $parsed['amount'];
                        $candidate[$fieldName.'_currency'] = $parsed['currency'];
                        $candidate[$fieldName.'_period'] = $parsed['period'];
                    }
                    if ($parsed['maximum']) $candidate['notes'] = trim(($candidate['notes'] ?? '').' '.$raw.' (maximum; not guaranteed)');
                    $fields[$amountPath] = $this->field($amountPath, $raw, $parsed, $fieldName === 'living_expenses' ? 'uncertain' : 'normalized', 'deterministic_funding_label', $locator);
                }
            } else {
                $issues[] = ['field' => $amountPath, 'code' => 'funding_ambiguous', 'status' => 'uncertain', 'competing_values' => [$raw], 'evidence' => [$locator]];
                $fields[$amountPath] = $this->field($amountPath, $raw, null, 'uncertain', 'deterministic_funding_label', $locator);
            }
        }
        if (preg_match('/\bfully funded\b/i', $text) && isset($candidate['stipend_amount'], $candidate['tuition_amount'])) $candidate['classification'] = 'fully_funded';
        if ($candidate !== [] && ! isset($candidate['classification'])) $candidate['classification'] = 'unknown';
        return ['candidate' => $candidate, 'fields' => $fields, 'issues' => $issues];
    }

    private function money(string $text): ?array
    {
        if (! preg_match('/(?:(£|€|\$|USD|EUR|GBP|CAD|AUD)\s*)?([\d,]+(?:\.\d{1,2})?)\s*(?:per\s+|\/\s*)?(year|month|week|annum|yearly|monthly)?/i', $text, $match)) return null;
        $currencyToken = $match[1] ?? '';
        $currency = match ($currencyToken) { '£' => 'GBP', '€' => 'EUR', '$' => null, default => strtoupper($currencyToken) };
        if ($currency === null || $currency === '') return null;
        $period = strtolower($match[3] ?? '');
        $period = match ($period) { 'annum', 'yearly' => 'year', 'monthly' => 'month', default => $period ?: null };
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '', $match[2]), 2), 2, '');
        $invalid = strlen(ltrim($whole, '0') ?: '0') > 13;
        return ['amount' => $whole.'.'.str_pad($fraction, 2, '0'), 'currency' => $currency, 'period' => $period, 'maximum' => (bool) preg_match('/\b(?:up to|maximum|max\.)\b/i', $text), 'invalid' => $invalid];
    }

    private function eligibility(DOMXPath $xpath): array
    {
        $text = $this->contentText($xpath);
        $fields = [];
        $rules = [];
        if (preg_match('/\bminimum\s+GPA\s+(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)/i', $text, $match)) {
            $valid = (float) $match[1] <= (float) $match[2];
            $fields['eligibility.gpa'] = $this->field('eligibility.gpa', $match[0], $valid ? ['minimum' => $match[1], 'scale' => $match[2]] : null, $valid ? 'normalized' : 'invalid', 'deterministic_eligibility_pattern', 'text:'.(strpos($text, $match[0]) ?: 0));
            if ($valid) $rules[] = ['rule_type' => 'gpa', 'operator' => 'gte', 'normalized_value' => $match[1], 'unit' => $match[2], 'metadata' => ['scale' => $match[2]], 'display_text' => $match[0]];
            else $fields['eligibility.gpa']['issue'] = 'gpa_exceeds_scale';
        }
        $tests = ['IELTS' => ['ielts', 9], 'TOEFL\s+iBT' => ['toefl', 120], 'PTE' => ['pte', 90], 'Duolingo(?:\s+English)?' => ['duolingo', 160], 'GRE' => ['gre', 340], 'GMAT' => ['gmat', 805]];
        foreach ($tests as $pattern => [$type, $maximum]) {
            if (! preg_match('/\b('.$pattern.')\s+(?:overall\s+)?(?:score\s+of\s+)?(\d+(?:\.\d+)?)/i', $text, $match)) continue;
            $valid = (float) $match[2] <= $maximum;
            $path = 'eligibility.'.$type;
            $fields[$path] = $this->field($path, $match[0], $valid ? (float) $match[2] : null, $valid ? 'normalized' : 'invalid', 'deterministic_eligibility_pattern', 'text:'.(strpos($text, $match[0]) ?: 0));
            if ($valid) $rules[] = ['rule_type' => $type, 'operator' => 'gte', 'normalized_value' => $match[2], 'display_text' => $match[0]];
            else $fields[$path]['issue'] = 'score_out_of_range';
        }
        if (preg_match('/\bminimum\s+(?:percentage\s+)?(\d+(?:\.\d+)?)\s*%/i', $text, $match)) {
            $valid = (float) $match[1] <= 100;
            $fields['eligibility.percentage'] = $this->field('eligibility.percentage', $match[0], $valid ? $match[1] : null, $valid ? 'normalized' : 'invalid', 'deterministic_eligibility_pattern', 'text:'.(strpos($text, $match[0]) ?: 0));
            if ($valid) $rules[] = ['rule_type' => 'percentage', 'operator' => 'gte', 'normalized_value' => $match[1], 'unit' => '%', 'display_text' => $match[0]];
            else $fields['eligibility.percentage']['issue'] = 'score_out_of_range';
        }
        if (preg_match('/\bage\s+(?:between\s+(\d{1,2})\s+and\s+(\d{1,2})|(?:maximum|under)\s+(\d{1,2}))/i', $text, $match)) {
            $value = ! empty($match[1]) ? ['minimum' => (int) $match[1], 'maximum' => (int) $match[2]] : ['maximum' => (int) $match[3]];
            $fields['eligibility.age'] = $this->field('eligibility.age', $match[0], $value, 'normalized', 'deterministic_eligibility_pattern', 'text:'.(strpos($text, $match[0]) ?: 0));
            $rules[] = ['rule_type' => 'age', 'operator' => 'gte', 'normalized_value' => json_encode($value, JSON_THROW_ON_ERROR), 'unit' => 'years', 'display_text' => $match[0]];
        }
        if (preg_match('/\b(?:at least|minimum of)\s+(\d+(?:\.\d+)?)\s+years?\s+(?:of\s+)?work experience\b/i', $text, $match)) {
            $fields['eligibility.work_experience'] = $this->field('eligibility.work_experience', $match[0], $match[1], 'normalized', 'deterministic_eligibility_pattern', 'text:'.(strpos($text, $match[0]) ?: 0));
            $rules[] = ['rule_type' => 'work_experience', 'operator' => 'gte', 'normalized_value' => $match[1], 'unit' => 'years', 'display_text' => $match[0]];
        }
        if (preg_match('/\b(?:graduated|graduation)\s+(?:in|year\s*[:\-]?)\s*(20\d{2})\b/i', $text, $match)) {
            $fields['eligibility.graduation_year'] = $this->field('eligibility.graduation_year', $match[0], (int) $match[1], 'normalized', 'deterministic_eligibility_pattern', 'text:'.(strpos($text, $match[0]) ?: 0));
            $rules[] = ['rule_type' => 'graduation_year', 'operator' => 'eq', 'normalized_value' => (int) $match[1], 'display_text' => $match[0]];
        }
        if (preg_match('/\b(?:citizens|nationals)\s+of\s+([A-Z][A-Za-z -]{1,60})/i', $text, $match)) {
            $countryName = trim($match[1]);
            $countryId = Country::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($countryName)])->value('id');
            $fields['eligibility.nationality'] = $this->field('eligibility.nationality', $match[0], $countryName, $countryId ? 'normalized' : 'extracted', 'deterministic_eligibility_pattern', 'text:'.(strpos($text, $match[0]) ?: 0));
            $rules[] = ['rule_type' => 'nationality', 'operator' => 'eq', 'normalized_value' => $countryName, 'country_id' => $countryId ? (int) $countryId : null, 'display_text' => $match[0]];
        }
        return ['fields' => $fields, 'rules' => $rules];
    }

    private function cycleKey(DOMXPath $xpath): ?array
    {
        $text = $this->contentText($xpath);
        if (preg_match('/\b(?:academic\s+year|application\s+cycle|intake|scholarship\s+cycle)\s*[:\-]?\s*((?:20\d{2}\s*[\/-]\s*(?:20)?\d{2})|20\d{2})\b/i', $text, $match, PREG_OFFSET_CAPTURE)) return ['value' => preg_replace('/\s+/', '', $match[1][0]), 'method' => 'explicit_cycle_label', 'locator' => 'text-offset:'.$match[1][1]];
        return null;
    }

    private function applicationLink(DOMXPath $xpath, string $sourceUrl): array
    {
        $sourceHost = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
        $matches = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            $label = $this->clean($link->textContent);
            $href = trim($link->getAttribute('href'));
            if (! preg_match('/\b(apply|application|apply now)\b/i', $label.' '.$href)) continue;
            $absolute = $this->absoluteUrl($href, $sourceUrl);
            if (! $absolute || strtolower((string) parse_url($absolute, PHP_URL_HOST)) !== $sourceHost) continue;
            $matches[] = ['value' => $absolute, 'method' => 'same_origin_application_link', 'locator' => $this->locator($link)];
        }
        $values = array_values(array_unique(array_column($matches, 'value')));
        if (count($values) === 1) return ['selected' => $matches[0], 'ambiguous' => false, 'candidates' => $matches];
        if (count($values) > 1) return ['selected' => null, 'ambiguous' => true, 'candidates' => $matches];
        return [];
    }

    private function absoluteUrl(string $href, string $base): ?string
    {
        if (str_starts_with($href, '//')) $href = (string) parse_url($base, PHP_URL_SCHEME).':'.$href;
        elseif (str_starts_with($href, '/')) $href = rtrim((string) parse_url($base, PHP_URL_SCHEME).'://'.parse_url($base, PHP_URL_HOST), '/').$href;
        elseif (! preg_match('/^https?:\/\//i', $href)) $href = rtrim(dirname($base), '/').'/'.$href;
        $parts = parse_url($href);
        return is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true) ? $href : null;
    }

    private function degree(string $raw): ?array
    {
        $needle = mb_strtolower(trim($raw));
        $aliases = ['bachelor' => ['bachelor', 'bachelors', 'undergraduate'], 'master' => ['master', 'masters', 'msc', 'ma'], 'phd' => ['phd', 'doctoral', 'doctorate']];
        foreach ($aliases as $canonical => $values) {
            if (! in_array($needle, $values, true)) continue;
            $id = Degree::query()->where('level', $canonical)->where('status', 'active')->value('id');
            if ($id) return ['id' => (int) $id, 'canonical' => $canonical];
        }
        return null;
    }

    private function firstContextValue(DOMXPath $xpath, array $labels): ?string
    {
        $nodes = $xpath->query('//tr|//dt|//p|//li');
        foreach ($nodes ?: [] as $node) {
            $text = $this->clean($node->textContent);
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'tr') {
                $cells = $xpath->query('./th|./td', $node);
                if ($cells && $cells->length >= 2) {
                    $rowLabel = $this->clean($cells->item(0)->textContent);
                    foreach ($labels as $expected) if (preg_match('/^'.preg_quote($expected, '/').'$/i', $rowLabel)) return $this->clean($cells->item(1)->textContent);
                }
            }
            foreach ($labels as $label) {
                if (preg_match('/^'.preg_quote($label, '/').'\s*[:\-]\s*(.+)$/i', $text, $match)) return trim($match[1]);
                if (preg_match('/^'.preg_quote($label, '/').'$/i', $text) && $node->nextSibling) return $this->clean($node->nextSibling->textContent);
            }
        }
        return null;
    }

    private function sectionText(DOMXPath $xpath, array $headings): ?string
    {
        foreach ($xpath->query('//h1|//h2|//h3|//h4') ?: [] as $heading) {
            if (! in_array(mb_strtolower($this->clean($heading->textContent)), $headings, true)) continue;
            $parts = [];
            $node = $heading->nextSibling;
            while ($node) {
                if ($node instanceof DOMElement && preg_match('/^h[1-4]$/i', $node->tagName)) break;
                if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['p', 'ul', 'ol', 'table', 'div'], true)) $parts[] = $this->clean($node->textContent);
                $node = $node->nextSibling;
            }
            if ($parts !== []) return implode(' ', $parts);
        }
        return null;
    }

    private function nextElementText(DOMNode $node): string
    {
        $node = $node->nextSibling;
        while ($node && ! ($node instanceof DOMElement)) $node = $node->nextSibling;
        return $node ? $this->clean($node->textContent) : '';
    }

    private function choose(string $path, array $candidates, array &$issues): ?array
    {
        $candidates = array_values(array_filter($candidates, fn ($item) => is_array($item) && is_string($item['value'] ?? null) && trim($item['value']) !== ''));
        if ($candidates === []) return null;
        $values = array_values(array_unique(array_map(fn ($item) => mb_strtolower(trim($item['normalized'] ?? $item['value'])), $candidates)));
        if (count($values) > 1) {
            $issues[] = ['field' => $path, 'code' => 'conflicting_extractions', 'status' => 'uncertain', 'competing_values' => array_map(fn ($item) => $item['value'], $candidates), 'evidence' => array_column($candidates, 'locator')];
            return ['value' => implode(' | ', array_column($candidates, 'value')), 'normalized' => null, 'status' => 'uncertain', 'method' => 'conflict_detection', 'locator' => array_column($candidates, 'locator')];
        }
        $chosen = $candidates[0];
        if (($chosen['status'] ?? null) === 'uncertain') $issues[] = ['field' => $path, 'code' => 'normalization_uncertain', 'status' => 'uncertain', 'competing_values' => [$chosen['value']], 'evidence' => [$chosen['locator']]];
        if (($chosen['status'] ?? null) === 'invalid') $issues[] = ['field' => $path, 'code' => 'invalid_date', 'status' => 'invalid', 'competing_values' => [$chosen['value']], 'evidence' => [$chosen['locator']]];
        return $chosen;
    }

    private function candidate(?string $value, string $method, string $locator): ?array
    {
        $value = $value !== null ? $this->clean($value) : null;
        return $value !== null && $value !== '' ? ['value' => $value, 'normalized' => $value, 'method' => $method, 'locator' => $locator] : null;
    }

    private function field(string $path, ?string $raw, mixed $normalized, string $status, string $method, string|array $locator): array
    {
        return ['path' => $path, 'raw_value' => $raw, 'normalized_value' => $normalized, 'status' => $status, 'method' => $method, 'parser_version' => self::VERSION, 'evidence' => ['locator' => $locator, 'excerpt' => $raw ? mb_substr($raw, 0, 500) : null]];
    }

    private function uniqueCandidates(array $candidates): array { return array_values(array_filter($candidates)); }
    private function hasLabel(string $text, array $labels): bool { foreach ($labels as $label) if (preg_match('/\b'.preg_quote($label, '/').'\b/i', $text)) return true; return false; }
    private function firstText(DOMXPath $xpath, string $query): ?string { $node = $xpath->query($query)?->item(0); return $node ? $this->clean($node->textContent) : null; }
    private function meta(DOMXPath $xpath, string $attribute, string $value): ?string { $node = $xpath->query('//meta[@'.$attribute.'="'.$value.'"]')?->item(0); return $node instanceof DOMElement ? $node->getAttribute('content') : null; }
    private function locator(DOMNode $node): string { return $node instanceof DOMElement ? '//'.$node->tagName.'[@id="'.addslashes($node->getAttribute('id')).'"]' : 'html-node'; }
    private function clean(string $text): string { return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''); }
    private function contentText(DOMXPath $xpath): string
    {
        $lines = [];
        foreach ($xpath->query('//tr') ?: [] as $row) {
            $cells = [];
            foreach ($xpath->query('./th|./td', $row) ?: [] as $cell) $cells[] = $this->clean($cell->textContent);
            if ($cells !== []) $lines[] = implode(': ', array_filter($cells));
        }
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//p|//li|//dt|//dd') ?: [] as $node) {
            $line = $this->clean($node->textContent);
            if ($line !== '') $lines[] = $line;
        }
        return implode("\n", array_unique($lines));
    }
}
