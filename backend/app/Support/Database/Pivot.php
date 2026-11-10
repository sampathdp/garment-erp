<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Model;

/** Typed access to belongs-to-many pivot columns (`$model->pivot` is a magic relation). */
final class Pivot
{
    public static function flag(Model $related, string $column): bool
    {
        $pivot = $related->relationLoaded('pivot') ? $related->getRelation('pivot') : null;

        return $pivot instanceof Model && (bool) $pivot->getAttribute($column);
    }
}
