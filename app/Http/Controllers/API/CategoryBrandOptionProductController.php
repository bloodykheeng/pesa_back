<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use App\Models\CategoryBrandOptionProduct;

class CategoryBrandOptionProductController extends Controller
{
    public function index()
    {
        $products = CategoryBrandOptionProduct::get();
        return response()->json(['data' => $products]);
    }

    public function show($id)
    {
        $product = CategoryBrandOptionProduct::with(['categoryBrandOption', 'createdBy', 'updatedBy'])->find($id);
        if (!$product) {
            return response()->json(['message' => 'Category Brand Option Product not found'], 404);
        }
        return response()->json($product);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'price' => 'required|numeric',
            'quantity' => 'required|integer',
            'details' => 'nullable|string',
            'category_brand_options_id' => 'required|exists:category_brand_options,id',
        ]);

        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $photoUrl = $this->uploadPhoto($request->file('photo'), 'product_photos');
        }

        $product = CategoryBrandOptionProduct::create([
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'active',
            'photo_url' => $photoUrl,
            'price' => $validated['price'],
            'quantity' => $validated['quantity'],
            'details' => $validated['details'],
            'category_brand_options_id' => $validated['category_brand_options_id'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Category Brand Option Product created successfully', 'data' => $product], 201);
    }

    public function update(Request $request, $id)
    {
        $product = CategoryBrandOptionProduct::find($id);
        if (!$product) {
            return response()->json(['message' => 'Category Brand Option Product not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'price' => 'sometimes|required|numeric',
            'quantity' => 'sometimes|required|integer',
            'details' => 'nullable|string',
            'category_brand_options_id' => 'sometimes|required|exists:category_brand_options,id',
        ]);

        if ($request->hasFile('photo')) {
            if ($product->photo_url) {
                $this->deletePhoto($product->photo_url);
            }
            $photoUrl = $this->uploadPhoto($request->file('photo'), 'product_photos');
            $validated['photo_url'] = $photoUrl;
        }

        $validated['updated_by'] = Auth::id();

        $product->update($validated);

        return response()->json(['message' => 'Category Brand Option Product updated successfully', 'data' => $product]);
    }

    public function destroy($id)
    {
        $product = CategoryBrandOptionProduct::find($id);
        if (!$product) {
            return response()->json(['message' => 'Category Brand Option Product not found'], 404);
        }

        if ($product->photo_url) {
            $this->deletePhoto($product->photo_url);
        }

        $product->delete();

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