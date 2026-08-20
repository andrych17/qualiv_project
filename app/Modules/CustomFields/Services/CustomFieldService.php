<?php

namespace App\Modules\CustomFields\Services;

use App\Modules\CustomFields\Models\FieldDef;
use App\Modules\CustomFields\Models\FieldValue;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CustomFieldService
{
    /** @return Collection<int, FieldDef> */
    public function defsFor(string $entityType): Collection
    {
        return FieldDef::query()->active()->forEntity($entityType)->get();
    }

    /**
     * @return list<array{code: string, label: string, field_type: string, options: list<array{label: string, value: string}>|null, is_required: bool, seq: int, value: string|null}>
     */
    public function formPayload(string $entityType, ?int $entityId = null): array
    {
        $defs = $this->defsFor($entityType);
        $values = [];

        if ($entityId !== null) {
            $values = FieldValue::query()
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->pluck('value', 'field_def_id')
                ->all();
        }

        return $defs->map(function (FieldDef $def) use ($values) {
            return [
                'code' => $def->code,
                'label' => $def->label,
                'field_type' => $def->field_type,
                'options' => $def->options,
                'is_required' => $def->is_required,
                'seq' => $def->seq,
                'value' => $values[$def->id] ?? null,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $submitted  code => value
     * @return array<string, string|null> normalized code => string value
     */
    public function validateAndNormalize(string $entityType, array $submitted): array
    {
        $defs = $this->defsFor($entityType);
        $out = [];
        $errors = [];

        foreach ($defs as $def) {
            $raw = $submitted[$def->code] ?? null;
            $value = is_string($raw) || is_numeric($raw) ? trim((string) $raw) : '';
            $value = $value === '' ? null : $value;

            if ($def->is_required && $value === null) {
                $errors['custom_fields.'.$def->code] = $def->label.' is required.';

                continue;
            }

            if ($value !== null && $def->field_type === 'number' && ! is_numeric($value)) {
                $errors['custom_fields.'.$def->code] = $def->label.' must be a number.';
            } elseif ($value !== null && $def->field_type === 'date' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $errors['custom_fields.'.$def->code] = $def->label.' must be YYYY-MM-DD.';
            } elseif ($value !== null && $def->field_type === 'select' && ! $this->optionAllowed($def, $value)) {
                $errors['custom_fields.'.$def->code] = $def->label.' has an invalid option.';
            }

            $out[$def->code] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** @param  array<string, string|null>  $values */
    public function sync(string $entityType, int $entityId, array $values): void
    {
        $defs = $this->defsFor($entityType)->keyBy('code');

        foreach ($defs as $def) {
            $value = $values[$def->code] ?? null;
            $keys = [
                'field_def_id' => $def->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ];
            if ($value === null) {
                FieldValue::query()->where($keys)->delete();

                continue;
            }

            FieldValue::query()->updateOrCreate($keys, ['value' => $value]);
        }
    }

    public function deleteFor(string $entityType, int $entityId): void
    {
        FieldValue::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->delete();
    }

    private function optionAllowed(FieldDef $def, string $value): bool
    {
        $options = $def->options ?? [];
        foreach ($options as $opt) {
            if (($opt['value'] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }
}
