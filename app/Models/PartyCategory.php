<?php

namespace App\Models;

use Database\Factories\PartyCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A company's own label for grouping parties (Customer, Supplier, Landlord…). Every company also has the built-in
 * Employee category (`is_system`), which User::syncParties() keeps on employee parties; it is for tracking only and
 * is never edited, deleted or picked by hand. `is_system` is deliberately not fillable.
 */
#[Fillable(['company_id', 'name', 'is_active'])]
class PartyCategory extends Model
{
    /** @use HasFactory<PartyCategoryFactory> */
    use HasFactory;

    public const EMPLOYEE = 'Employee';

    protected $attributes = ['is_active' => true, 'is_system' => false];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean'];
    }

    /**
     * The company's built-in Employee category, created on first use. A custom category already named Employee
     * becomes the built-in one. Uses the query builder so migrations can call it.
     */
    public static function employeeCategoryId(int $companyId): int
    {
        $id = DB::table('party_categories')->where('company_id', $companyId)->where('name', self::EMPLOYEE)->value('id');
        if ($id === null) {
            return (int) DB::table('party_categories')->insertGetId(['company_id' => $companyId, 'name' => self::EMPLOYEE, 'is_active' => true,
                'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('party_categories')->whereKey($id)->update(['is_system' => true, 'is_active' => true]);

        return (int) $id;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class);
    }

    /** Categories a custom party can be put in: the company's own, never the built-in one. */
    public function scopeCustom(Builder $query): void
    {
        $query->where('is_system', false);
    }

    /** Categories of the companies the user may access. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('company_id', $user->accessibleCompanyIds());
    }
}
