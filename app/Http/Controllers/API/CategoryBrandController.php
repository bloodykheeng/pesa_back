<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Models\CategoryBrand;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class CategoryBrandController extends Controller
{
    public function index()
    {
        $brands = CategoryBrand::with(['accessories', 'options'])->get();
        return response()->json(['data' => $brands]);
    }

    public function show($id)
    {
        $brand = CategoryBrand::with(['accessories', 'options'])->find($id);
        if (!$brand) {
            return response()->json(['message' => 'Category Brand not found'], 404);
        }
        return response()->json($brand);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|string|max:255',
            'details' => 'nullable|string',
            'product_categories_id' => 'required|exists:product_categories,id',
        ]);
    
        $photoData = null;
        if ($request->hasFile('photo')) {
            $photoData = $this->handlePhotoUpload($request->file('photo'), 'brand_photos');
        }
    
        $brand = CategoryBrand::create([
            'name' => $validated['name'],
            'photo_url' => $photoData['photo_url'] ?? null,
            'cloudinary_photo_public_id' => $photoData['cloudinary_photo_public_id'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'details' => $validated['details'],
            'product_categories_id' => $validated['product_categories_id'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);
    
        return response()->json(['message' => 'Category Brand created successfully', 'data' => $brand], 201);
    }
    
    public function update(Request $request, $id)
    {
        $brand = CategoryBrand::find($id);
        if (!$brand) {
            return response()->json(['message' => 'Category Brand not found'], 404);
        }
    
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|string|max:255',
            'details' => 'nullable|string',
            'product_categories_id' => 'sometimes|required|exists:product_categories,id',
        ]);
    
        if ($request->hasFile('photo')) {
            if ($brand->cloudinary_photo_public_id) {
                $this->deleteCloudinaryPhoto($brand->cloudinary_photo_public_id);
            } elseif ($brand->photo_url) {
                $this->deleteLocalPhoto($brand->photo_url);
            }
    
            $photoData = $this->handlePhotoUpload($request->file('photo'), 'brand_photos');
            $validated['photo_url'] = $photoData['photo_url'];
            $validated['cloudinary_photo_public_id'] = $photoData['cloudinary_photo_public_id'];
        }
    
        $validated['updated_by'] = Auth::id();
    
        $brand->update($validated);
    
        return response()->json(['message' => 'Category Brand updated successfully', 'data' => $brand]);
    }
    
    public function destroy($id)
    {
        $brand = CategoryBrand::find($id);
        if (!$brand) {
            return response()->json(['message' => 'Category Brand not found'], 404);
        }
    
        if ($brand->cloudinary_photo_public_id) {
            $this->deleteCloudinaryPhoto($brand->cloudinary_photo_public_id);
        } elseif ($brand->photo_url) {
            $this->deleteLocalPhoto($brand->photo_url);
        }
    
        $brand->delete();
    
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