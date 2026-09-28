<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare draft without lines. Tests that need priced, issued or posted documents go through DocumentService.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'type' => DocumentType::Invoice, 'status' => DocumentStatus::Draft,
            'issue_date' => today()->toDateString(), 'created_by' => User::factory()];
    }
}
