<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class ProductCategoryController extends Controller
{
    public function index()
    {
        $categories = ProductCategory::with('brands')->get();
        return response()->json(['data' => $categories]);
    }

    public function show($id)
    {
        $category = ProductCategory::with(['brands'])->find($id);
        if (!$category) {
            return response()->json(['message' => 'Product Category not found'], 404);
        }
        return response()->json($category);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|string|max:255',
            'details' => 'nullable|string',
        ]);

        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $photoUrl = $this->uploadPhoto($request->file('photo'), 'category_photos');
        }

        $category = ProductCategory::create([
            'name' => $validated['name'],
            'photo_url' => $photoUrl,
            'status' => $validated['status'] ?? 'active',
            'details' => $validated['details'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Product Category created successfully', 'data' => $category], 201);
    }

    public function update(Request $request, $id)
    {
        $category = ProductCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Product Category not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|string|max:255',
            'details' => 'nullable|string',
        ]);

        if ($request->hasFile('photo')) {
            if ($category->photo_url) {
                $this->deletePhoto($category->photo_url);
            }
            $photoUrl = $this->uploadPhoto($request->file('photo'), 'category_photos');
            $validated['photo_url'] = $photoUrl;
        }

        $validated['updated_by'] = Auth::id();

        $category->update($validated);

        return response()->json(['message' => 'Product Category updated successfully', 'data' => $category]);
    }

    public function destroy($id)
    {
        $category = ProductCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Product Category not found'], 404);
        }

        if ($category->photo_url) {
            $this->deletePhoto($category->photo_url);
        }

        $category->delete();

        return response()->json(null, 204);
    }

    private function uploadPhoto($photo, $folderPath)
    {
        $publicPath = public_path($folderPath);
        if (!File::exists($publicPath)) {
            File::makeDirectory($publicPath, 0777, true, true);
        }

        $fileName = time() . '_' . $photo->getClientOriginalName();
        $photo->move($publicPath, $fileName);

        return '/' . $folderPath . '/' . $fileName;
    }

    private function deletePhoto($photoUrl)
    {
        $photoPath = parse_url($photoUrl, PHP_URL_PATH);
        $photoPath = public_path($photoPath);
        if (File::exists($photoPath)) {
            File::delete($photoPath);
        }
    }
}