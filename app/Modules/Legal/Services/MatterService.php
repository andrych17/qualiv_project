<?php

namespace App\Modules\Legal\Services;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\CustomFields\Services\CustomLogicEngine;
use App\Modules\Legal\Contracts\MatterCodeGenerator;
use App\Modules\Legal\Models\Matter;
use Illuminate\Support\Facades\DB;

class MatterService
{
    public const ENTITY = 'legal_matter';

    public function __construct(
        protected CustomFieldService $customFields,
        protected CustomLogicEngine $logic,
        protected MatterCodeGenerator $codes,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Matter
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
            $matter = Matter::query()->create($data);
            $this->customFields->sync(self::ENTITY, $matter->id, $custom);

            return $matter;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Matter $matter, array $data): Matter
    {
        $custom = $this->customFields->validateAndNormalize(
            self::ENTITY,
            $data['custom_fields'] ?? [],
        );
        unset($data['custom_fields']);

        $data = $this->logic->beforeSave(self::ENTITY, $data, $custom);

        return DB::transaction(function () use ($matter, $data, $custom) {
            $matter->update($data);
            $this->customFields->sync(self::ENTITY, $matter->id, $custom);

            return $matter->refresh();
        });
    }

    public function delete(Matter $matter): void
    {
        DB::transaction(function () use ($matter) {
            $this->customFields->deleteFor(self::ENTITY, $matter->id);
            $matter->delete();
        });
    }
}
