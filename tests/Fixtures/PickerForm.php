<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tests\Fixtures;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Koassi\FilamentFileExplorer\Forms\Components\FileExplorerPicker;
use Livewire\Component;

/**
 * A form holding nothing but the picker.
 *
 * The picker had no test that rendered it, which is how a field that answered
 * with a 404 the moment it was added to a form shipped: every test around it
 * built the explorer directly, passing the root the field itself was failing to
 * resolve.
 */
class PickerForm extends Component implements HasForms
{
    use InteractsWithForms;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * Configured by the test, so one fixture covers the defaults and an
     * explicitly named root.
     */
    public static ?int $rootFolderId = null;

    public static ?string $scopeKey = null;

    public static bool $multiple = false;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $picker = FileExplorerPicker::make('files');

        if (self::$rootFolderId !== null) {
            $picker->rootFolderId(self::$rootFolderId);
        }

        if (self::$scopeKey !== null) {
            $picker->scopeKey(self::$scopeKey);
        }

        if (self::$multiple) {
            $picker->multiple();
        }

        return $schema->components([$picker])->statePath('data');
    }

    /**
     * Inline, so a fixture does not put a test-only Blade file in the views the
     * package ships.
     */
    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
