<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
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
            'code' => 'required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|string|max:255',
            'details' => 'nullable|string',
        ]);

        $photoData = $this->handlePhotoUpload($request->file('photo'), 'category_photos');

        $category = ProductCategory::create(array_merge([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'status' => $validated['status'] ?? 'active',
            'details' => $validated['details'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ], $photoData));

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
            'code' => 'required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|string|max:255',
            'details' => 'nullable|string',
        ]);

        if ($request->hasFile('photo')) {
            // Delete existing photo
            if ($category->cloudinary_photo_public_id) {
                $this->deleteCloudinaryPhoto($category->cloudinary_photo_public_id);
            } elseif ($category->photo_url) {
                $this->deleteLocalPhoto($category->photo_url);
            }

            // Upload new photo
            $photoData = $this->handlePhotoUpload($request->file('photo'), 'category_photos');
            $validated = array_merge($validated, $photoData);
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

        if ($category->cloudinary_photo_public_id) {
            $this->deleteCloudinaryPhoto($category->cloudinary_photo_public_id);
        } elseif ($category->photo_url) {
            $this->deleteLocalPhoto($category->photo_url);
        }

        $category->delete();

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