<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CategoryBrandOptionProduct;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

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

        $photoData = null;
        if ($request->hasFile('photo')) {
            $photoData = $this->handlePhotoUpload($request->file('photo'), 'product_photos');
        }

        $product = CategoryBrandOptionProduct::create([
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'active',
            'photo_url' => $photoData['photo_url'] ?? null,
            'cloudinary_photo_public_id' => $photoData['cloudinary_photo_public_id'] ?? null,
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
            if ($product->cloudinary_photo_public_id) {
                $this->deleteCloudinaryPhoto($product->cloudinary_photo_public_id);
            } elseif ($product->photo_url) {
                $this->deleteLocalPhoto($product->photo_url);
            }

            $photoData = $this->handlePhotoUpload($request->file('photo'), 'product_photos');
            $validated['photo_url'] = $photoData['photo_url'];
            $validated['cloudinary_photo_public_id'] = $photoData['cloudinary_photo_public_id'];
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

        if ($product->cloudinary_photo_public_id) {
            $this->deleteCloudinaryPhoto($product->cloudinary_photo_public_id);
        } elseif ($product->photo_url) {
            $this->deleteLocalPhoto($product->photo_url);
        }

        $product->delete();

        return response()->json(null, 204);
    }

    //=================== upload Photos Helper functions ==========================

    private function handlePhotoUpload($photo, $folderPath)
    {
        if (env('MEDIA_STORAGE_METHOD') === 'cloudinary') {
            return $this->uploadToCloudinary($photo, $folderPath);
        } else {
            return $this->uploadToLocal($photo, $folderPath);
        }
    }

    private function uploadToCloudinary($photo, $folderPath)
    {
        $uploadedFile = Cloudinary::upload($photo->getRealPath(), [
            'folder' => $folderPath,
        ]);
        return [
            'cloudinary_photo_url' => $uploadedFile->getSecurePath(),
            'cloudinary_photo_public_id' => $uploadedFile->getPublicId(),
        ];
    }

    private function uploadToLocal($photo, $folderPath)
    {
        $publicPath = public_path($folderPath);
        if (!File::exists($publicPath)) {
            File::makeDirectory($publicPath, 0777, true, true);
        }

        $fileName = time() . '_' . $photo->getClientOriginalName();
        $photo->move($publicPath, $fileName);

        return [
            'photo_url' => '/' . $folderPath . '/' . $fileName,
        ];
    }

    private function deleteCloudinaryPhoto($publicId)
    {
        Cloudinary::destroy($publicId);
    }

    private function deleteLocalPhoto($photoUrl)
    {
        $photoPath = parse_url($photoUrl, PHP_URL_PATH);
        $photoPath = public_path($photoPath);
        if (File::exists($photoPath)) {
            File::delete($photoPath);
        }
    }

}