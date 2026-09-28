<div class="page">
    <x-notices />
    <x-page-header :title="__('Numbering & fields')" :description="__('How each document type is numbered, which template and texts it starts with, and your own extra fields.')" :back="route('admin.sales.dashboard')" :back-label="__('Sales')">
        @if($companyName)<x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta> @endif
    </x-page-header>
    @if(! $companyName)
        <x-card>
            <x-empty-state emoji="🏢" :title="__('Choose a company first')" :description="__('Numbering and fields are set per company. Pick one active company in the header.')">
                <x-button :href="route('admin.choose-company', ['next' => route('admin.sales.settings', [], false)])">{{ __('Choose a company') }}</x-button>
            </x-empty-state>
        </x-card>
    @else
        @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
        <form wire:submit="saveNumbering" class="stack">
            <x-card flush :title="__('Numbering')" :description="__('Drafts get their number when issued, so there are no gaps. Changing a prefix never renumbers issued documents; a new prefix starts again at 1.')">
                <x-table :caption="__('Numbering')">
                    <x-slot:head><th scope="col">{{ __('Document') }}</th><th scope="col">{{ __('Prefix') }}</th><th scope="col">{{ __('Digits') }}</th><th scope="col">{{ __('Next number') }}</th><th scope="col">{{ __('Default template') }}</th></x-slot:head>
                    @foreach($types as $type)
                        <tr wire:key="sequence-{{ $type->value }}">
                            <th scope="row" class="row-label">{{ $type->label() }}</th>
                            <td>
                                <input class="form-control" type="text" wire:model.live.debounce.500ms="rows.{{ $type->value }}.prefix" maxlength="20" autocomplete="off" aria-label="{{ __('Prefix of :type', ['type' => $type->label()]) }}" aria-invalid="{{ $errors->has('rows.'.$type->value.'.prefix') ? 'true' : 'false' }}">
                                @error('rows.'.$type->value.'.prefix')<p class="error">{{ $message }}</p>@enderror
                            </td>
                            <td>
                                <input class="form-control" type="number" min="3" max="8" wire:model.live.debounce.500ms="rows.{{ $type->value }}.padding" aria-label="{{ __('Digits of :type', ['type' => $type->label()]) }}" aria-invalid="{{ $errors->has('rows.'.$type->value.'.padding') ? 'true' : 'false' }}">
                                @error('rows.'.$type->value.'.padding')<p class="error">{{ $message }}</p>@enderror
                            </td>
                            <td class="nowrap">@if($previews[$type->value])<code>{{ $previews[$type->value] }}</code>@else<span class="muted">—</span>@endif</td>
                            <td><x-form.select :name="'rows.'.$type->value.'.templateId'" :id="'template-'.$type->value" :label="__('Default template of :type', ['type' => $type->label()])" wire:model="rows.{{ $type->value }}.templateId" :options="$templates" class="sr-label" /></td>
                        </tr>
                    @endforeach
                </x-table>
            </x-card>
            <x-card :title="__('Default notes and terms')" :description="__('Filled into every new document of the type; you can still change them on the document.')">
                <div class="stack-sm">
                    @foreach($types as $type)
                        <details wire:key="texts-{{ $type->value }}" @if($errors->has('rows.'.$type->value.'.notes') || $errors->has('rows.'.$type->value.'.terms')) open @endif>
                            <summary><strong>{{ $type->label() }}</strong>@if(($rows[$type->value]['notes'] ?? '') !== '' || ($rows[$type->value]['terms'] ?? '') !== '') <x-badge tone="info">{{ __('Set') }}</x-badge>@endif</summary>
                            <div class="form-grid mt-2">
                                @foreach(['notes' => __('Notes'), 'terms' => __('Terms')] as $name => $label)
                                    <div class="field">
                                        <label for="{{ $name }}-{{ $type->value }}">{{ $label }}</label>
                                        <textarea id="{{ $name }}-{{ $type->value }}" class="form-control" rows="3" maxlength="5000" wire:model="rows.{{ $type->value }}.{{ $name }}" aria-invalid="{{ $errors->has('rows.'.$type->value.'.'.$name) ? 'true' : 'false' }}"></textarea>
                                        @error('rows.'.$type->value.'.'.$name)<p class="error">{{ $message }}</p>@enderror
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>
            </x-card>
            <x-form.actions :submit="__('Save numbering and defaults')" />
        </form>

        <x-card flush :title="__('Custom fields')" :description="__('Extra fields such as PO number or Delivery date, filled in on the document and printed in its Custom fields block.')">
            <x-table :caption="__('Custom fields')">
                <x-slot:head><th scope="col">{{ __('Label') }}</th><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('Shown on') }}</th><th scope="col">{{ __('Required') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col" class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                @forelse($fields as $customField)
                    <tr wire:key="field-{{ $customField->id }}">
                        <td><strong>{{ $customField->label }}</strong></td>
                        <td>{{ $kindOptions[$customField->kind] ?? $customField->kind }}</td>
                        <td>{{ $customField->document_type?->pluralLabel() ?? __('All document types') }}</td>
                        <td>{{ $customField->is_required ? __('Yes') : __('No') }}</td>
                        <td><x-badge.active :active="$customField->is_active" /></td>
                        <td><div class="row-actions">
                            <x-button variant="ghost" size="sm" icon="chevron-down" class="rotate-180" wire:click="moveField({{ $customField->id }}, -1)" :disabled="$loop->first" :label="__('Move :name up', ['name' => $customField->label])" />
                            <x-button variant="ghost" size="sm" icon="chevron-down" wire:click="moveField({{ $customField->id }}, 1)" :disabled="$loop->last" :label="__('Move :name down', ['name' => $customField->label])" />
                            <x-button variant="ghost" size="sm" icon="pencil" wire:click="editField({{ $customField->id }})" :label="__('Edit :name', ['name' => $customField->label])">{{ __('Edit') }}</x-button>
                            <x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="deleteField({{ $customField->id }})" wire:confirm="{{ __('Delete :name? Values already entered on documents stop showing. Switch it off instead to keep them.', ['name' => $customField->label]) }}" :label="__('Delete :name', ['name' => $customField->label])" />
                        </div></td>
                    </tr>
                @empty
                    <x-table.empty :colspan="6" emoji="🏷️">{{ __('No custom fields yet.') }}</x-table.empty>
                @endforelse
            </x-table>
            <x-slot:footer>
                <form wire:submit="saveField" class="stack">
                    <h3>{{ $field['id'] ? __('Edit custom field') : __('Add a custom field') }}</h3>
                    <div class="form-grid">
                        <x-form.input name="field.label" :label="__('Label')" wire:model="field.label" required maxlength="60" autocomplete="off" :placeholder="__('PO number, Delivery date…')" />
                        <x-form.select name="field.kind" :label="__('Kind')" wire:model="field.kind" :options="$kindOptions" />
                        <x-form.select name="field.documentType" :label="__('Shown on')" wire:model="field.documentType" :options="$typeOptions" />
                    </div>
                    <x-form.checkbox name="field.isRequired" :label="__('Required: a document can\'t be saved without it')" wire:model="field.isRequired" />
                    <x-form.checkbox name="field.isActive" :label="__('Field is active (offered on documents)')" wire:model="field.isActive" />
                    <div class="btn-group">
                        <x-button type="submit" wire:loading.attr="disabled">{{ $field['id'] ? __('Save field') : __('Add field') }}</x-button>
                        @if($field['id'])<x-button variant="ghost" wire:click="resetField">{{ __('Cancel') }}</x-button>@endif
                    </div>
                </form>
            </x-slot:footer>
        </x-card>
    @endif
</div>
