<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/**
 * The company chosen in the header switcher: one visible company, or null for "All companies".
 * Every company-scoped page reads its scope from here instead of offering its own company filter.
 */
class CompanyContext
{
    public const SESSION_KEY = 'company_context';

    /** @var Collection<int, Company>|null */
    private ?Collection $visible = null;

    /** @return Collection<int, Company> Companies the signed-in user can see, by name. */
    public function options(): Collection
    {
        $user = auth()->user();

        return $this->visible ??= $user ? Company::visibleTo($user)->orderBy('name')->get() : collect();
    }

    /** The selected company id, or null for all. A user who can see exactly one company is pinned to it. */
    public function selectedId(): ?int
    {
        $options = $this->options();
        if ($options->count() === 1) {
            return $options->first()->id;
        }
        $selected = (int) session(self::SESSION_KEY);

        return $options->contains('id', $selected) ? $selected : null;
    }

    public function isAll(): bool
    {
        return $this->selectedId() === null;
    }

    public function company(): ?Company
    {
        return $this->options()->firstWhere('id', $this->selectedId());
    }

    /** @return list<int> Company ids in scope for reading: the selected company, or every visible company. */
    public function companyIds(): array
    {
        $selected = $this->selectedId();

        return $selected ? [$selected] : $this->options()->pluck('id')->all();
    }

    /**
     * The page to reload after the header company changes, keeping its filters. Livewire's original URL has no
     * query string, so the same-site admin Referer of the update request is preferred.
     */
    public static function returnUrl(): string
    {
        $referer = request()->headers->get('referer');
        $parts = is_string($referer) ? parse_url($referer) : false;

        return is_array($parts) && ($parts['host'] ?? null) === request()->getHost() && str_starts_with($parts['path'] ?? '', '/admin')
            ? $referer : Livewire::originalUrl();
    }

    /** Selects a visible company, or all companies with null. Returns false for a company the user can't see. */
    public function select(?int $companyId): bool
    {
        if ($companyId !== null && ! $this->options()->contains('id', $companyId)) {
            return false;
        }
        session([self::SESSION_KEY => $companyId]);

        return true;
    }
}
