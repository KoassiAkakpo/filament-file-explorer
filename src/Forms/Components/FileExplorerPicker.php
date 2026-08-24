<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Forms\Components;

use Filament\Forms\Components\Field;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * An explorer in a modal, as a form field.
 *
 * The root and the scope key default to the **standalone** library — the one the
 * panel's own explorer page opens. They used to default to `0` and `'picker'`,
 * which is to say to nothing: `FileExplorerPicker::make('files')` mounted the
 * explorer on folder #0, and the `findOrFail` behind it answered with a 404 on
 * whichever page held the form. A field that cannot be added without configuring
 * it has no default; it has a trap.
 */
class FileExplorerPicker extends Field
{
    protected string $view = 'filament-file-explorer::filament.forms.file-explorer-picker';

    protected ?int $rootFolderId = null;

    protected ?string $scopeKey = null;

    protected bool $multiple = false;

    /**
     * @var array{scopeKey: string, rootFolderId: int}|null
     */
    protected ?array $resolvedScope = null;

    public static function make(?string $name = null): static
    {
        return parent::make($name);
    }

    public function rootFolderId(int $id): static
    {
        $this->rootFolderId = $id;
        $this->resolvedScope = null;

        return $this;
    }

    public function scopeKey(string $key): static
    {
        $this->scopeKey = $key;
        $this->resolvedScope = null;

        return $this;
    }

    public function multiple(bool $condition = true): static
    {
        $this->multiple = $condition;

        return $this;
    }

    public function getRootFolderId(): int
    {
        return $this->scope()['rootFolderId'];
    }

    public function getScopeKey(): string
    {
        return $this->scope()['scopeKey'];
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * The pair the explorer is mounted with.
     *
     * A pair, and resolved as one, because the two halves have to describe the
     * same library: abilities are decided per (scope, root) and `ScopeRoots`
     * ties media URLs to the same couple. Inferring one half because the other
     * was given is how a picker ends up authorised against a root it is not
     * browsing — so the standalone default applies only when the field was told
     * neither.
     *
     * @return array{scopeKey: string, rootFolderId: int}
     */
    protected function scope(): array
    {
        if ($this->resolvedScope !== null) {
            return $this->resolvedScope;
        }

        if ($this->rootFolderId !== null || $this->scopeKey !== null) {
            return $this->resolvedScope = [
                'scopeKey' => $this->scopeKey ?? 'picker',
                // Left at 0 on purpose when only the scope key was given:
                // MissingRootFolder then says what is missing, which is better
                // than a library the field was never pointed at.
                'rootFolderId' => $this->rootFolderId ?? 0,
            ];
        }

        return $this->resolvedScope = $this->standaloneScope();
    }

    /**
     * The standalone library, created on first use.
     *
     * The creating path, unlike `StandaloneAccess`: that one is read-only
     * because `canAccess()` runs on every navigation render for every user, and
     * this does not — it runs on a form the application deliberately put the
     * field on, which is the same act as visiting the explorer page for the
     * first time. `ensureRoot()` behind it is idempotent and locked.
     *
     * @return array{scopeKey: string, rootFolderId: int}
     */
    protected function standaloneScope(): array
    {
        try {
            $resolver = app(FileExplorerRootResolver::class);

            return ['scopeKey' => $resolver->scopeKey(), 'rootFolderId' => $resolver->rootFolderId()];
        } catch (HttpExceptionInterface) {
            // The per-user and per-tenant resolvers abort without a user or a
            // tenant. Nothing to browse, and 0 is what says so.
            return ['scopeKey' => StandaloneSettings::scopeKey(), 'rootFolderId' => 0];
        }
    }
}
