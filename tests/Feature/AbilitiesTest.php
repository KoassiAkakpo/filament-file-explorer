<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\Abilities;

beforeEach(function (): void {
    Storage::fake('public');
});

function feAbRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feAb(): Abilities
{
    return app(Abilities::class);
}

/**
 * An authorizer written before an ability existed: it answers the published set
 * and nothing else, which is exactly the situation the fallback is for.
 */
function feAbAuthorizerAnswering(array $answers): void
{
    app()->instance(FileExplorerAuthorizer::class, new class($answers) extends AllowAllAuthorizer
    {
        public function __construct(private array $answers) {}

        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return $this->answers;
        }
    });

    app()->forgetScopedInstances();
}

it('answers the published set from the authorizer', function (): void {
    feAbAuthorizerAnswering(array_fill_keys(Abilities::ORIGINAL, true));

    foreach (Abilities::ORIGINAL as $ability) {
        expect(feAb()->allows('library', feAbRootId(), $ability))->toBeTrue();
    }
});

it('denies a published ability the authorizer left out', function (): void {
    // Unchanged behaviour for the original set: silence is a denial, because an
    // authorizer written against the contract was told to answer all of them.
    $partial = array_fill_keys(Abilities::ORIGINAL, true);
    unset($partial['delete']);

    feAbAuthorizerAnswering($partial);

    expect(feAb()->allows('library', feAbRootId(), 'delete'))->toBeFalse()
        ->and(feAb()->allows('library', feAbRootId(), 'upload'))->toBeTrue();
});

it('lets a derived ability inherit when the authorizer says nothing', function (): void {
    // The whole point: an authorizer written before `share` existed cannot have
    // an opinion on it, and must not have the action denied out from under it.
    feAbAuthorizerAnswering(array_fill_keys(Abilities::ORIGINAL, true));

    expect(feAb()->allows('library', feAbRootId(), 'share'))->toBeTrue();
});

it('carries a denial down to what inherits from it', function (): void {
    $answers = array_fill_keys(Abilities::ORIGINAL, true);
    $answers['download'] = false;

    feAbAuthorizerAnswering($answers);

    // Whoever refused downloads meant to refuse sharing too.
    expect(feAb()->allows('library', feAbRootId(), 'share'))->toBeFalse();
});

it('lets an authorizer that answers a derived ability override the inheritance', function (): void {
    $answers = array_fill_keys(Abilities::ORIGINAL, true);
    $answers['download'] = true;
    $answers['share'] = false;

    feAbAuthorizerAnswering($answers);

    expect(feAb()->allows('library', feAbRootId(), 'share'))->toBeFalse()
        ->and(feAb()->allows('library', feAbRootId(), 'download'))->toBeTrue();
});

it('never lets an unknown ability default to allowed', function (): void {
    feAbAuthorizerAnswering(array_fill_keys(Abilities::ORIGINAL, true));

    expect(feAb()->allows('library', feAbRootId(), 'somethingNobodyDeclared'))->toBeFalse();
});

it('declares a fallback for every derived ability', function (): void {
    // The invariant that keeps this class honest: a derived ability with no
    // resolvable parent would silently deny, which is what it exists to stop.
    foreach (Abilities::DERIVED as $ability => $inheritsFrom) {
        expect($inheritsFrom)->toBeIn([...Abilities::ORIGINAL, ...array_keys(Abilities::DERIVED)])
            ->and($ability)->not->toBeIn(Abilities::ORIGINAL);
    }
});

it('keeps a key the authorizer volunteered', function (): void {
    $answers = array_fill_keys(Abilities::ORIGINAL, true);
    $answers['approve'] = true;

    feAbAuthorizerAnswering($answers);

    expect(feAb()->allows('library', feAbRootId(), 'approve'))->toBeTrue();
});

it('asks the authorizer once per scope, not once per read', function (): void {
    $calls = 0;

    app()->instance(FileExplorerAuthorizer::class, new class($calls) extends AllowAllAuthorizer
    {
        public function __construct(public int &$calls) {}

        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            $this->calls++;

            return parent::abilities($scopeKey, $rootFolderId);
        }
    });

    app()->forgetScopedInstances();

    $abilities = feAb();

    foreach (range(1, 20) as $ignored) {
        $abilities->allows('library', feAbRootId(), 'browse');
    }

    expect($calls)->toBe(1);
});

it('keeps two scopes apart', function (): void {
    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [...parent::abilities($scopeKey, $rootFolderId), 'upload' => $scopeKey === 'library'];
        }
    });

    app()->forgetScopedInstances();

    $other = Folder::query()->create(['name' => 'Other', 'slug' => 'other', 'parent_id' => null]);

    expect(feAb()->allows('library', feAbRootId(), 'upload'))->toBeTrue()
        ->and(feAb()->allows('elsewhere', (int) $other->id, 'upload'))->toBeFalse();
});
