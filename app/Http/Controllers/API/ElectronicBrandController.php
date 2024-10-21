<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ElectronicBrand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class ElectronicBrandController extends Controller
{
    // Index function to get a list of brands
    public function index(Request $request)
    {
        // Build the query with eager loading
        $query = ElectronicBrand::with(['electronicCategory', 'createdBy', 'updatedBy']);

        // Filters
        $categoryId = $request->query('electronic_categories_id');
        $createdBy = $request->query('created_by');
        $updatedBy = $request->query('updated_by');

        if (isset($categoryId)) {
            $query->where('electronic_categories_id', $categoryId);
        }

        if (isset($createdBy)) {
            $query->where('created_by', $createdBy);
        }

        if (isset($updatedBy)) {
            $query->where('updated_by', $updatedBy);
        }

        // Execute the query and return the results
        $electronicBrands = $query->latest()->get();

        return response()->json(['data' => $electronicBrands]);
    }

    // Index function for app to get a list of electronic brands
    public function indexForApp(Request $request)
    {
        // Build the query with eager loading
        $query = ElectronicBrand::with(['electronicCategory', 'createdBy', 'updatedBy']);

        // Filters
        $categoryId = $request->query('electronic_categories_id');
        $createdBy = $request->query('created_by');
        $updatedBy = $request->query('updated_by');

        if (isset($categoryId)) {
            $query->where('electronic_categories_id', $categoryId);
        }

        if (isset($createdBy)) {
            $query->where('created_by', $createdBy);
        }

        if (isset($updatedBy)) {
            $query->where('updated_by', $updatedBy);
        }

        // Execute the query and return the results
        $electronicBrands = $query->latest()->get();

        return response()->json(['data' => $electronicBrands]);
    }

    // Show function to get a single brand by ID
    public function show($id)
    {
        $electronicBrand = ElectronicBrand::with(['electronicCategory', 'createdBy', 'updatedBy'])->find($id);

        if (!$electronicBrand) {
            return response()->json(['message' => 'Electronic brand not found'], 404);
        }

        return response()->json($electronicBrand);
    }

    // Store function to create a new brand
    public function store(Request $request)
    {
        // Validate the input
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:electronic_brands,code',
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'details' => 'nullable|string',
            'electronic_categories_id' => 'required|exists:electronic_categories,id',
        ]);

        // Handle photo upload if provided
        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $photoUrl = $this->handlePhotoUpload($request->file('photo'), 'brand_photos');
        }

        // Create the electronic brand
        $electronicBrand = ElectronicBrand::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'status' => $validated['status'] ?? 'active',
            'photo_url' => $photoUrl,
            'details' => $validated['details'],
            'electronic_categories_id' => $validated['electronic_categories_id'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Electronic brand created successfully', 'data' => $electronicBrand], 201);
    }

    // Update function to edit an existing brand
    public function update(Request $request, $id)
    {
        $electronicBrand = ElectronicBrand::find($id);

        if (!$electronicBrand) {
            return response()->json(['message' => 'Electronic brand not found'], 404);
        }

        // Validate the input
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:electronic_brands,code,' . $id,
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'details' => 'nullable|string',
            'electronic_categories_id' => 'sometimes|required|exists:electronic_categories,id',
        ]);

        // Handle photo upload if provided
        if ($request->hasFile('photo')) {
            // Delete the old photo if it exists
            if ($electronicBrand->photo_url) {
                $this->deleteLocalPhoto($electronicBrand->photo_url);
            }
            $validated['photo_url'] = $this->handlePhotoUpload($request->file('photo'), 'brand_photos');
        }

        // Update the electronic brand
        $validated['updated_by'] = Auth::id();
        $electronicBrand->update($validated);

        return response()->json(['message' => 'Electronic brand updated successfully', 'data' => $electronicBrand]);
    }

    // Destroy function to delete a brand
    public function destroy($id)
    {
        $electronicBrand = ElectronicBrand::find($id);

        if (!$electronicBrand) {
            return response()->json(['message' => 'Electronic brand not found'], 404);
        }

        // Delete the photo if it exists
        if ($electronicBrand->photo_url) {
            $this->deleteLocalPhoto($electronicBrand->photo_url);
        }

        // Delete the brand
        $electronicBrand->delete();

        return response()->json(null, 204);
    }

    // Helper functions

    private function handlePhotoUpload($photo, $folderPath)
    {
        // Handle local photo upload
        $publicPath = public_path($folderPath);
        if (!File::exists($publicPath)) {
            File::makeDirectory($publicPath, 0777, true, true);
        }

        $fileName = time() . '_' . $photo->getClientOriginalName();
        $photo->move($publicPath, $fileName);

        return '/' . $folderPath . '/' . $fileName; // Return the URL of the uploaded photo
    }

    private function deleteLocalPhoto($photoUrl)
    {
        // Delete the photo from the local storage
        $photoPath = parse_url($photoUrl, PHP_URL_PATH);
        $photoPath = public_path($photoPath);
        if (File::exists($photoPath)) {
            File::delete($photoPath);
        }
    }
}
