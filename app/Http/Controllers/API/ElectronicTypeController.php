<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ElectronicType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class ElectronicTypeController extends Controller
{
    // Index function to get a list of types
    public function index(Request $request)
    {
        // Build the query with eager loading
        $query = ElectronicType::with(['electronicBrand.electronicCategory', 'createdBy', 'updatedBy']);

        // Filters
        $brandId = $request->query('electronic_brands_id');
        $createdBy = $request->query('created_by');
        $updatedBy = $request->query('updated_by');

        if (isset($brandId)) {
            $query->where('electronic_brands_id', $brandId);
        }

        if (isset($createdBy)) {
            $query->where('created_by', $createdBy);
        }

        if (isset($updatedBy)) {
            $query->where('updated_by', $updatedBy);
        }

        // Execute the query and return the results
        $electronicTypes = $query->latest()->get();

        return response()->json(['data' => $electronicTypes]);
    }

    // Index function for app to get a list of electronic types
    public function indexForApp(Request $request)
    {
        // Build the query with eager loading
        $query = ElectronicType::with(['electronicBrand.electronicCategory', 'createdBy', 'updatedBy']);

        // Filters
        $brandId = $request->query('electronic_brands_id');
        $createdBy = $request->query('created_by');
        $updatedBy = $request->query('updated_by');

        if (isset($brandId)) {
            $query->where('electronic_brands_id', $brandId);
        }

        if (isset($createdBy)) {
            $query->where('created_by', $createdBy);
        }

        if (isset($updatedBy)) {
            $query->where('updated_by', $updatedBy);
        }

        // Execute the query and return the results
        $electronicTypes = $query->latest()->get();

        return response()->json(['data' => $electronicTypes]);
    }

    // Show function to get a single type by ID
    public function show($id)
    {
        $electronicType = ElectronicType::with(['electronicBrand', 'createdBy', 'updatedBy'])->find($id);

        if (!$electronicType) {
            return response()->json(['message' => 'Electronic type not found'], 404);
        }

        return response()->json($electronicType);
    }

    // Store function to create a new type
    public function store(Request $request)
    {
        // Validate the input
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:electronic_types,code',
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'details' => 'nullable|string',
            'electronic_brands_id' => 'required|exists:electronic_brands,id',
        ]);

        // Handle photo upload if provided
        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $photoUrl = $this->handlePhotoUpload($request->file('photo'), 'type_photos');
        }

        // Create the electronic type
        $electronicType = ElectronicType::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'status' => $validated['status'] ?? 'active',
            'photo_url' => $photoUrl,
            'details' => $validated['details'],
            'electronic_brands_id' => $validated['electronic_brands_id'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Electronic type created successfully', 'data' => $electronicType], 201);
    }

    // Update function to edit an existing type
    public function update(Request $request, $id)
    {
        $electronicType = ElectronicType::find($id);

        if (!$electronicType) {
            return response()->json(['message' => 'Electronic type not found'], 404);
        }

        // Validate the input
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:electronic_types,code,' . $id,
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'details' => 'nullable|string',
            'electronic_brands_id' => 'sometimes|required|exists:electronic_brands,id',
        ]);

        // Handle photo upload if provided
        if ($request->hasFile('photo')) {
            // Delete the old photo if it exists
            if ($electronicType->photo_url) {
                $this->deleteLocalPhoto($electronicType->photo_url);
            }
            $validated['photo_url'] = $this->handlePhotoUpload($request->file('photo'), 'type_photos');
        }

        // Update the electronic type
        $validated['updated_by'] = Auth::id();
        $electronicType->update($validated);

        return response()->json(['message' => 'Electronic type updated successfully', 'data' => $electronicType]);
    }

    // Destroy function to delete a type
    public function destroy($id)
    {
        $electronicType = ElectronicType::find($id);

        if (!$electronicType) {
            return response()->json(['message' => 'Electronic type not found'], 404);
        }

        // Delete the photo if it exists
        if ($electronicType->photo_url) {
            $this->deleteLocalPhoto($electronicType->photo_url);
        }

        // Delete the type
        $electronicType->delete();

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
