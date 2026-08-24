<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * §3O Audit & Compliance — same immutable pattern as DMS.access_logs (see that model's
 * docblock for the rationale): app-layer guard, not a DB REVOKE, since CreateModuleSchemas
 * grants ALL PRIVILEGES schema-wide.
 */
class AuditLog extends Model
{
    protected $table = 'ACCOUNTING.audit_logs';

    public $timestamps = false;

    public const ACTION_JOURNAL_CREATED = 'journal_created';

    public const ACTION_JOURNAL_POSTED = 'journal_posted';

    public const ACTION_JOURNAL_REVERSED = 'journal_reversed';

    public const ACTION_PERIOD_CLOSED = 'period_closed';

    public const ACTION_PERIOD_REOPENED = 'period_reopened';

    public const ACTION_INVOICE_POSTED = 'invoice_posted';

    public const ACTION_BILL_POSTED = 'bill_posted';

    public const ACTION_PAYMENT_POSTED = 'payment_posted';

    public const ACTION_TAX_DOCUMENT_ISSUED = 'tax_document_issued';

    public const ACTION_TAX_DOCUMENT_CANCELLED = 'tax_document_cancelled';

    public const ACTION_MASTER_DATA_CHANGED = 'master_data_changed';

    /** @var list<string> every action this module ever logs — the audit page's filter dropdown. */
    public const ACTIONS = [
        self::ACTION_JOURNAL_CREATED, self::ACTION_JOURNAL_POSTED, self::ACTION_JOURNAL_REVERSED,
        self::ACTION_PERIOD_CLOSED, self::ACTION_PERIOD_REOPENED,
        self::ACTION_INVOICE_POSTED, self::ACTION_BILL_POSTED, self::ACTION_PAYMENT_POSTED,
        self::ACTION_TAX_DOCUMENT_ISSUED, self::ACTION_TAX_DOCUMENT_CANCELLED,
        self::ACTION_MASTER_DATA_CHANGED,
    ];

    protected $fillable = [
        'company_id', 'action', 'subject_type', 'subject_id', 'actor_id',
        'before_snapshot', 'after_snapshot', 'ip_address', 'created_at',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('ACCOUNTING.audit_logs is append-only — rows cannot be updated at the app layer.');
        });
    }

    public function delete()
    {
        throw new LogicException('ACCOUNTING.audit_logs is append-only — rows cannot be deleted at the app layer.');
    }

    /**
     * Every AuditLog::create() call in this module should go through here instead —
     * defaults actor_id to the logged-in user and fills ip_address from the current
     * request automatically, same "console context has neither" handling as
     * DMS\AccessLog::record() (e.g. a background depreciation run has no request to
     * read an IP from, and callers that already carry a $userId — JournalService,
     * ArInvoiceService, etc. — pass actor_id explicitly instead of relying on the guess).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(array $attributes): self
    {
        return static::query()->create([
            ...$attributes,
            'actor_id' => $attributes['actor_id'] ?? (app()->runningInConsole() ? null : auth()->id()),
            'ip_address' => $attributes['ip_address'] ?? (app()->runningInConsole() ? null : request()?->ip()),
        ]);
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['company_id'] ?? null, function ($query, $companyId) {
            $query->where('company_id', $companyId);
        })->when($filters['action'] ?? null, function ($query, $action) {
            $query->where('action', $action);
        })->when($filters['subject_type'] ?? null, function ($query, $subjectType) {
            $query->where('subject_type', $subjectType);
        })->when($filters['subject_id'] ?? null, function ($query, $subjectId) {
            $query->where('subject_id', $subjectId);
        })->when($filters['actor_id'] ?? null, function ($query, $actorId) {
            $query->where('actor_id', $actorId);
        })->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('actor', fn ($q) => $q->where('name', 'ilike', "%{$search}%"));
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
