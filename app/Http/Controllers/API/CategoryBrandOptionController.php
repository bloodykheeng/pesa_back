<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CategoryBrandOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CategoryBrandOptionController extends Controller
{
    public function index()
    {
        $options = CategoryBrandOption::get();
        return response()->json(['data' => $options]);
    }

    public function show($id)
    {
        $option = CategoryBrandOption::with(['products', 'categoryBrand', 'createdBy', 'updatedBy'])->find($id);
        if (!$option) {
            return response()->json(['message' => 'Category Brand Option not found'], 404);
        }
        return response()->json($option);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'status' => 'nullable|string|max:255',
            'details' => 'nullable|string',
            'category_brands_id' => 'required|exists:category_brands,id',
        ]);

        $option = CategoryBrandOption::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'status' => $validated['status'] ?? 'active',
            'details' => $validated['details'],
            'category_brands_id' => $validated['category_brands_id'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Category Brand Option created successfully', 'data' => $option], 201);
    }

    public function update(Request $request, $id)
    {
        $option = CategoryBrandOption::find($id);
        if (!$option) {
            return response()->json(['message' => 'Category Brand Option not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255',
            'status' => 'nullable|string|max:255',
            'details' => 'nullable|string',
            'category_brands_id' => 'sometimes|required|exists:category_brands,id',
        ]);

        $validated['updated_by'] = Auth::id();

        $option->update($validated);

        return response()->json(['message' => 'Category Brand Option updated successfully', 'data' => $option]);
    }

    public function destroy($id)
    {
        $option = CategoryBrandOption::find($id);
        if (!$option) {
            return response()->json(['message' => 'Category Brand Option not found'], 404);
        }

        $option->delete();

        return response()->json(null, 204);
    }
}