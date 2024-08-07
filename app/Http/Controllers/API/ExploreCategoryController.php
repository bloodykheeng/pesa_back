<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Models\ExploreCategory;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ExploreCategoryController extends Controller
{
    public function index()
    {
        $categories = ExploreCategory::get();
        return response()->json(['data' => $categories]);
    }

    public function show($id)
    {
        $category = ExploreCategory::with(['blogs', 'createdBy', 'updatedBy'])->find($id);
        if (!$category) {
            return response()->json(['message' => 'Explore Category not found'], 404);
        }
        return response()->json($category);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);

        $category = ExploreCategory::create([
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'active',
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Explore Category created successfully', 'data' => $category], 201);
    }

    public function update(Request $request, $id)
    {
        $category = ExploreCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Explore Category not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status' => 'nullable|string|max:255',
        ]);

        $validated['updated_by'] = Auth::id();

        $category->update($validated);

        return response()->json(['message' => 'Explore Category updated successfully', 'data' => $category]);
    }

    public function destroy($id)
    {
        $category = ExploreCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Explore Category not found'], 404);
        }

        $category->delete();

        return response()->json(null, 204);
    }
}
