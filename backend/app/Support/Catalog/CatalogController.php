<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Small configurable lists (order types, product categories): code + name + active flag.
 * Entries are deactivated, never deleted, because orders reference them. Codes never change.
 *
 * @template TModel of Model
 */
abstract class CatalogController
{
    /** @return class-string<TModel> */
    abstract protected function model(): string;

    abstract protected function auditPrefix(): string;

    /**
     * @param  TModel  $entry
     * @return array<string, mixed>
     */
    abstract protected function present(Model $entry): array;

    /** @return array<string, mixed> extra attributes for new entries */
    protected function defaults(): array
    {
        return [];
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', $this->model());
        $request->validate(['filter.active' => ['sometimes', 'boolean']]);

        $query = $this->model()::query()->orderBy($this->sortColumn())->orderBy('name');
        if ($request->has('filter.active')) {
            $query->where('is_active', $request->boolean('filter.active'));
        }

        return ApiResponse::data(['items' => $query->limit(200)->get()->map(fn (Model $m): array => $this->present($m))->all()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', $this->model());
        $table = (new ($this->model()))->getTable();
        $request->merge(['code' => is_string($request->input('code')) ? strtolower(trim($request->input('code'))) : $request->input('code')]);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:2', 'max:30', 'regex:/^[a-z0-9][a-z0-9_]*$/', Rule::unique($table, 'code')],
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'Use lower-case letters, numbers and underscores.']);

        /** @var User $actor */
        $actor = $request->user();
        $entry = DB::transaction(function () use ($data, $audit, $actor): Model {
            $entry = $this->model()::query()->create([...$this->defaults(), ...$data]);
            $audit->record($this->auditPrefix().'.created', $entry, ['after' => $data], actor: $actor);

            return $entry;
        });

        return ApiResponse::data($this->present($entry->refresh()), 201);
    }

    public function update(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $this->model());
        $entry = $this->model()::query()->findOrFail($id);
        $data = $request->validate([
            'code' => ['prohibited'],
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        DB::transaction(function () use ($entry, $data, $audit, $actor): void {
            $before = $entry->only(array_keys($data));
            $entry->fill($data);
            $dirty = $entry->getDirty();
            if ($dirty !== []) {
                $entry->save();
                $audit->record($this->auditPrefix().'.updated', $entry, ['before' => array_intersect_key($before, $dirty), 'after' => $dirty], actor: $actor);
            }
        });

        return ApiResponse::data($this->present($entry));
    }

    protected function sortColumn(): string
    {
        return 'name';
    }
}
