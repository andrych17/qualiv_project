<?php

namespace App\Modules\Legal\Services;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\CustomFields\Services\CustomLogicEngine;
use App\Modules\Legal\Contracts\CaseCodeGenerator;
use App\Modules\Legal\Models\LegalCase;
use Illuminate\Support\Facades\DB;

class LegalCaseService
{
    public const ENTITY = 'legal_case';

    public function __construct(
        protected CustomFieldService $customFields,
        protected CustomLogicEngine $logic,
        protected CaseCodeGenerator $codes,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): LegalCase
    {
        $custom = $this->customFields->validateAndNormalize(
            self::ENTITY,
            $data['custom_fields'] ?? [],
        );
        unset($data['custom_fields']);

        if (empty($data['code'])) {
            $data['code'] = $this->codes->next();
        }

        $data = $this->logic->beforeSave(self::ENTITY, $data, $custom);

        return DB::transaction(function () use ($data, $custom) {
            $case = LegalCase::query()->create($data);
            $this->customFields->sync(self::ENTITY, $case->id, $custom);

            return $case;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(LegalCase $case, array $data): LegalCase
    {
        $custom = $this->customFields->validateAndNormalize(
            self::ENTITY,
            $data['custom_fields'] ?? [],
        );
        unset($data['custom_fields']);

        $data = $this->logic->beforeSave(self::ENTITY, $data, $custom);

        return DB::transaction(function () use ($case, $data, $custom) {
            $case->update($data);
            $this->customFields->sync(self::ENTITY, $case->id, $custom);

            return $case->refresh();
        });
    }

    public function delete(LegalCase $case): void
    {
        DB::transaction(function () use ($case) {
            $this->customFields->deleteFor(self::ENTITY, $case->id);
            $case->delete();
        });
    }
}
