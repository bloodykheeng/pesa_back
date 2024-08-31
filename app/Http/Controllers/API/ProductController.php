<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        // Build the query with eager loading
        $query = Product::with(['categoryBrand', 'productType', 'createdBy', 'updatedBy']);

        // Get the query parameters
        $categoryBrandId = $request->query('category_brands_id');
        $productTypeId = $request->query('product_types_id');
        $createdBy = $request->query('created_by');
        $updatedBy = $request->query('updated_by');
        $categoryBrands = $request->query('categoryBrands');
        $productTypes = $request->query('productTypes');

        // Apply filters if the parameters are provided
        if (isset($categoryBrandId)) {
            $query->where('category_brands_id', $categoryBrandId);
        }

        if (isset($productTypeId)) {
            $query->where('product_types_id', $productTypeId);
        }

        if (isset($createdBy)) {
            $query->where('created_by', $createdBy);
        }

        if (isset($updatedBy)) {
            $query->where('updated_by', $updatedBy);
        }

        // Extract the 'id' values from the arrays of objects
        if (isset($categoryBrands)) {
            $categoryBrandIds = collect($categoryBrands)->pluck('id')->toArray();
            $query->whereIn('category_brands_id', $categoryBrandIds);
        }

        if (isset($productTypes)) {
            $productTypeIds = collect($productTypes)->pluck('id')->toArray();
            $query->whereIn('product_types_id', $productTypeIds);
        }
        // Add more filters as needed
        $query->latest();
        // Execute the query and get the results
        $products = $query->get();

        // Return the results as a JSON response
        return response()->json(['data' => $products]);
    }

    public function show($id)
    {
        $product = Product::with(['categoryBrand', 'productType', 'createdBy', 'updatedBy'])->find($id);
        if (!$product) {
            return response()->json(['message' => 'product not found'], 404);
        }
        return response()->json($product);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'nullable|string|max:255',
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'price' => 'required|numeric',
            'quantity' => 'required|integer',
            'details' => 'nullable|string',
            'category_brands_id' => 'required|exists:category_brands,id',
            'product_types_id' => 'required|exists:product_types,id',
        ]);

        $photoData = null;
        if ($request->hasFile('photo')) {
            $photoData = $this->handlePhotoUpload($request->file('photo'), 'product_photos');
        }

        $product = Product::create([
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'active',
            'photo_url' => $photoData['photo_url'] ?? null,
            'cloudinary_photo_url' => $photoData['cloudinary_photo_url'] ?? null,
            'cloudinary_photo_public_id' => $photoData['cloudinary_photo_public_id'] ?? null,
            'price' => $validated['price'],
            'quantity' => $validated['quantity'],
            'details' => $validated['details'],
            'category_brands_id' => $validated['category_brands_id'],
            'product_types_id' => $validated['product_types_id'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Brand product created successfully', 'data' => $product], 201);
    }

    public function update(Request $request, $id)
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Brand product not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status' => 'nullable|string|max:255',
            // 'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'price' => 'sometimes|required|numeric',
            'quantity' => 'sometimes|required|integer',
            'details' => 'nullable|string',
            'category_brands_id' => 'sometimes|required|exists:category_brands,id',
            'product_types_id' => 'required|exists:product_types,id',
        ]);

        if ($request->hasFile('photo')) {
            if (isset($product->cloudinary_photo_public_id)) {
                $this->deleteCloudinaryPhoto($product->cloudinary_photo_public_id);
            } elseif (isset($product->photo_url)) {
                $this->deleteLocalPhoto($product->photo_url);
            }

            $photoData = $this->handlePhotoUpload($request->file('photo'), 'product_photos');
            $validated['photo_url'] = $photoData['photo_url'] ?? null;
            $validated['cloudinary_photo_url'] = $photoData['cloudinary_photo_url'] ?? null;
            $validated['cloudinary_photo_public_id'] = $photoData['cloudinary_photo_public_id'] ?? null;
        }

        $validated['updated_by'] = Auth::id();

        $product->update($validated);

        return response()->json(['message' => 'product updated successfully', 'data' => $product]);
    }

    public function destroy($id)
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'product not found'], 404);
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
        // Cloudinary::destroy($publicId);
        if (isset($publicId) && !is_null($publicId)) {
            Cloudinary::destroy($publicId);
        }
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