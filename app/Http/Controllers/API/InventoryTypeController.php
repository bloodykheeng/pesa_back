<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\InventoryType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class InventoryTypeController extends Controller
{
    // Index function to get a list of inventory types
    public function index(Request $request)
    {
        // Build the query with eager loading
        $query = InventoryType::with(['createdBy', 'updatedBy']);

        // Filters
        $createdBy = $request->query('created_by');
        $updatedBy = $request->query('updated_by');

        // Apply filters
        if (isset($createdBy)) {
            $query->where('created_by', $createdBy);
        }

        if (isset($updatedBy)) {
            $query->where('updated_by', $updatedBy);
        }

        // Execute the query and return the results
        $inventoryTypes = $query->latest()->get();

        return response()->json(['data' => $inventoryTypes]);
    }

    // Show function to get a single inventory type by ID
    public function show($id)
    {
        $inventoryType = InventoryType::with(['createdBy', 'updatedBy'])->find($id);

        if (!$inventoryType) {
            return response()->json(['message' => 'Inventory type not found'], 404);
        }

        return response()->json($inventoryType);
    }

    // Store function to create a new inventory type
    public function store(Request $request)
    {
        // Validate the input
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:inventory_types,code',
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'details' => 'nullable|string',
        ]);

        // Handle photo upload if provided
        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $photoUrl = $this->handlePhotoUpload($request->file('photo'), 'inventory_type_photos');
        }

        // Create the inventory type
        $inventoryType = InventoryType::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'status' => $validated['status'] ?? 'active',
            'photo_url' => $photoUrl,
            'details' => $validated['details'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Inventory type created successfully', 'data' => $inventoryType], 201);
    }

    // Update function to edit an existing inventory type
    public function update(Request $request, $id)
    {
        $inventoryType = InventoryType::find($id);

        if (!$inventoryType) {
            return response()->json(['message' => 'Inventory type not found'], 404);
        }

        // Validate the input
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:inventory_types,code,' . $id,
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'details' => 'nullable|string',
        ]);

        // Handle photo upload if provided
        if ($request->hasFile('photo')) {
            // Delete the old photo if it exists
            if ($inventoryType->photo_url) {
                $this->deleteLocalPhoto($inventoryType->photo_url);
            }
            $validated['photo_url'] = $this->handlePhotoUpload($request->file('photo'), 'inventory_type_photos');
        }

        // Update the inventory type
        $validated['updated_by'] = Auth::id();
        $inventoryType->update($validated);

        return response()->json(['message' => 'Inventory type updated successfully', 'data' => $inventoryType]);
    }

    // Destroy function to delete an inventory type
    public function destroy($id)
    {
        $inventoryType = InventoryType::find($id);

        if (!$inventoryType) {
            return response()->json(['message' => 'Inventory type not found'], 404);
        }

        // Delete the photo if it exists
        if ($inventoryType->photo_url) {
            $this->deleteLocalPhoto($inventoryType->photo_url);
        }

        // Delete the inventory type
        $inventoryType->delete();

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
