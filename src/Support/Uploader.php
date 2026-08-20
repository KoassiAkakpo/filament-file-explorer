<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Who put a file there.
 *
 * The uploader has been recorded in custom_properties since the first upload,
 * but nothing ever read it back — and with a root shared by a whole team, "who
 * added this" is the first question anyone asks.
 *
 * Resolved live rather than denormalised into a stored label, so a renamed
 * account does not leave stale names behind, and memoised for the request: a
 * page costs one query per distinct uploader, not one per file.
 */
final class Uploader
{
    /** @var array<string, string|null> */
    private array $labels = [];

    public function label(Media $media): ?string
    {
        $type = $media->getCustomProperty('uploaded_by_type');
        $id = $media->getCustomProperty('uploaded_by_id') ?? $media->getCustomProperty('user_id');

        if (! is_string($type) || $type === '' || ! is_scalar($id) || $id === '') {
            return null;
        }

        return $this->labels[$type.':'.$id] ??= $this->resolve($type, (string) $id);
    }

    private function resolve(string $type, string $id): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        // The type is a class name read out of a JSON column: resolving anything
        // that is not an Eloquent model would be someone else's bug at best.
        if (! is_string($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        /** @var Model $model */
        $model = new $class;
        $record = $model->newQuery()->find($id);

        if ($record === null) {
            // The account is gone, but the trace is still worth showing.
            return '#'.$id;
        }

        foreach (['name', 'email', 'title', 'label'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return '#'.$id;
    }

    public function flush(): void
    {
        $this->labels = [];
    }
}
