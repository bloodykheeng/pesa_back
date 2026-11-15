<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Catalog;
use App\Http\Controllers\Admin\Concerns\StoresPhotos;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Support\AdminList;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * List, view, create, edit and bulk-delete for every table in App\Admin\Catalog.
 */
class CatalogController extends Controller
{
    use StoresPhotos;

    public function index(Request $request, string $resource)
    {
        $config = Catalog::get($resource);

        $query = QueryBuilder::for($config['model'])
            ->with($this->includes($config))
            ->withCount($config['counts'])
            ->allowedFilters([
                AdminList::search($config['search']),
                ...array_map(fn ($f) => AllowedFilter::exact($f), $config['filters']),
                ...AdminList::dateRange(),
            ])
            ->allowedSorts([...$config['sorts'], 'id', 'created_at', 'updated_at', ...array_map(fn ($c) => "{$c}_count", $config['counts'])])
            ->defaultSort('-id');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    public function show(string $resource, int $id)
    {
        $config = Catalog::get($resource);
        $record = $config['model']::with([...$this->includes($config), 'updatedBy:id,name,email'])->withCount($config['counts'])->findOrFail($id);

        return new AdminResource($record);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $config = $this->writable($resource);
        $data = $request->validate($config['rules'] + ($config['photo'] ? ['photo' => self::PHOTO_RULE] : []));

        $record = new $config['model'];
        $record->forceFill($this->only($data, $config) + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        if ($config['photo']) {
            $this->applyPhoto($request, $record, $config['photo']);
        }
        $record->save();

        return response()->json(['message' => ucfirst($config['label']) . ' created.', 'data' => new AdminResource($record->refresh()->load($this->includes($config)))], 201);
    }

    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        $config = $this->writable($resource);
        $record = $config['model']::findOrFail($id);
        $data = $request->validate($config['rules'] + ($config['photo'] ? ['photo' => self::PHOTO_RULE, 'remove_photo' => 'nullable|boolean'] : []));

        $record->forceFill($this->only($data, $config) + ['updated_by' => $request->user()->id]);
        if ($config['photo']) {
            $this->applyPhoto($request, $record, $config['photo']);
        }
        $record->save();

        return response()->json(['message' => ucfirst($config['label']) . ' saved.', 'data' => new AdminResource($record->refresh()->load($this->includes($config)))]);
    }

    /** POST /api/admin/<resource>/bulk-delete { ids: [..] } */
    public function destroyMany(Request $request, string $resource): JsonResponse
    {
        $config = Catalog::get($resource);
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer'])['ids'];
        $records = $config['model']::whereIn('id', $ids)->get();

        foreach ($config['dependents'] ?? [] as [$table, $column, $label]) {
            $used = DB::table($table)->whereIn($column, $ids)->count();
            if ($used > 0) {
                return response()->json(['message' => "Still used by {$used} {$label}, so nothing was deleted. Move or delete those first, or make this inactive."], 422);
            }
        }

        try {
            DB::transaction(function () use ($records, $config) {
                foreach ($records as $record) {
                    $record->delete();
                    if ($config['photo']) {
                        $this->deletePhoto($record->photo_url);
                    }
                }
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return response()->json(['message' => "Some of these are still in use (for example by products or orders), so nothing was deleted. Make them inactive instead."], 422);
            }
            throw $e;
        }

        $count = $records->count();

        return response()->json(['message' => $count . ' ' . $config['label'] . ($count === 1 ? '' : 's') . ' deleted.']);
    }

    private function writable(string $resource): array
    {
        $config = Catalog::get($resource);
        abort_if(! empty($config['readonly']), 405, 'These are created in the app.');

        return $config;
    }

    private function includes(array $config): array
    {
        $hasCreator = collect($config['include'])->contains(fn ($i) => str_starts_with($i, 'createdBy'));

        return $hasCreator ? $config['include'] : [...$config['include'], 'createdBy:id,name,email'];
    }

    private function only(array $data, array $config): array
    {
        return array_intersect_key($data, $config['rules']);
    }
}
