<?php

declare(strict_types=1);

use App\Actions\Properties\SetProperties;
use App\Enums\AppliesTo;
use App\Enums\PropertyDataType;
use App\Exceptions\UnknownProperty;
use App\Models\Directory;
use App\Models\File;
use App\Models\Property;
use App\Models\PropertyDefinition;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->file = File::factory()->create();
    $this->invoice = PropertyDefinition::factory()->create([
        'key' => 'invoice_no', 'data_type' => PropertyDataType::String_, 'applies_to' => AppliesTo::File,
    ]);
    $this->amount = PropertyDefinition::factory()->create([
        'key' => 'amount', 'data_type' => PropertyDataType::Number, 'applies_to' => AppliesTo::File,
    ]);
    $this->status = PropertyDefinition::factory()->create([
        'key' => 'status', 'data_type' => PropertyDataType::Select,
        'options' => ['draft', 'final'], 'applies_to' => AppliesTo::File,
    ]);
});

it('writes several values at once', function () {
    app(SetProperties::class)->handle($this->file, [
        'invoice_no' => 'ACME-001',
        'amount' => '1500.75',
        'status' => 'final',
    ]);

    expect(Property::count())->toBe(3)
        ->and($this->file->properties()->count())->toBe(3);
});

it('rejects a select value outside its options', function () {
    expect(fn () => app(SetProperties::class)->handle($this->file, ['status' => 'shredded']))
        ->toThrow(ValidationException::class);

    expect(Property::count())->toBe(0);
});

it('rejects a non-numeric number', function () {
    expect(fn () => app(SetProperties::class)->handle($this->file, ['amount' => 'lots']))
        ->toThrow(ValidationException::class);
});

it('rejects an unknown key rather than ignoring it', function () {
    expect(fn () => app(SetProperties::class)->handle($this->file, ['nonsense' => 'x']))
        ->toThrow(UnknownProperty::class);
});

it('refuses a definition that does not apply to this subject', function () {
    $directoryOnly = PropertyDefinition::factory()->create([
        'key' => 'retention', 'data_type' => PropertyDataType::String_, 'applies_to' => AppliesTo::Directory,
    ]);

    expect(fn () => app(SetProperties::class)->handle($this->file, ['retention' => '7y']))
        ->toThrow(UnknownProperty::class);

    // ...and accepts it on the subject it does apply to.
    app(SetProperties::class)->handle(Directory::factory()->create(), ['retention' => '7y']);
    expect(Property::count())->toBe(1);
});

it('enforces a required property', function () {
    $this->invoice->update(['is_required' => true]);

    expect(fn () => app(SetProperties::class)->handle($this->file, ['invoice_no' => '']))
        ->toThrow(ValidationException::class);
});

it('removes an property when its value is cleared', function () {
    app(SetProperties::class)->handle($this->file, ['invoice_no' => 'ACME-001']);
    expect(Property::count())->toBe(1);

    app(SetProperties::class)->handle($this->file, ['invoice_no' => '']);

    expect(Property::count())->toBe(0);
});

it('writes nothing at all when one value is invalid', function () {
    expect(fn () => app(SetProperties::class)->handle($this->file, [
        'invoice_no' => 'ACME-001',
        'amount' => 'lots',
    ]))->toThrow(ValidationException::class);

    // A partial write would leave the subject in a state the operator never
    // asked for and did not see.
    expect(Property::count())->toBe(0);
});
