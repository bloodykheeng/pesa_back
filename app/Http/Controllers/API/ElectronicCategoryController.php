<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ElectronicCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class ElectronicCategoryController extends Controller
{
    // Index function to get a list of categories
    public function index(Request $request)
    {
        // Build the query with eager loading
        $query = ElectronicCategory::with(['createdBy', 'updatedBy']);

        // Filters
        $createdBy = $request->query('created_by');
        $updatedBy = $request->query('updated_by');

        if (isset($createdBy)) {
            $query->where('created_by', $createdBy);
        }

        if (isset($updatedBy)) {
            $query->where('updated_by', $updatedBy);
        }

        // Execute the query and return the results
        $electronicCategories = $query->latest()->get();

        return response()->json(['data' => $electronicCategories]);
    }

      // Index function for app to get a list of categories
      public function indexForApp(Request $request)
      {
          // Build the query with eager loading
          $query = ElectronicCategory::with(['createdBy', 'updatedBy']);
  
          // Filters
          $createdBy = $request->query('created_by');
          $updatedBy = $request->query('updated_by');
  
          if (isset($createdBy)) {
              $query->where('created_by', $createdBy);
          }
  
          if (isset($updatedBy)) {
              $query->where('updated_by', $updatedBy);
          }
  
          // Execute the query and return the results
          $electronicCategories = $query->latest()->get();
  
          return response()->json(['data' => $electronicCategories]);
      }

    // Show function to get a single category by ID
    public function show($id)
    {
        $electronicCategory = ElectronicCategory::with(['createdBy', 'updatedBy'])->find($id);

        if (!$electronicCategory) {
            return response()->json(['message' => 'Electronic category not found'], 404);
        }

        return response()->json($electronicCategory);
    }

    // Store function to create a new category
    public function store(Request $request)
    {
        // Validate the input
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:electronic_categories,code',
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'details' => 'nullable|string',
        ]);

        // Handle photo upload if provided
        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $photoUrl = $this->handlePhotoUpload($request->file('photo'), 'category_photos');
        }

        // Create the electronic category
        $electronicCategory = ElectronicCategory::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'status' => $validated['status'] ?? 'active',
            'photo_url' => $photoUrl,
            'details' => $validated['details'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Electronic category created successfully', 'data' => $electronicCategory], 201);
    }

    // Update function to edit an existing category
    public function update(Request $request, $id)
    {
        $electronicCategory = ElectronicCategory::find($id);

        if (!$electronicCategory) {
            return response()->json(['message' => 'Electronic category not found'], 404);
        }

        // Validate the input
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255|unique:electronic_categories,code,' . $id,
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'details' => 'nullable|string',
        ]);

        // Handle photo upload if provided
        if ($request->hasFile('photo')) {
            // Delete the old photo if it exists
            if ($electronicCategory->photo_url) {
                $this->deleteLocalPhoto($electronicCategory->photo_url);
            }
            $validated['photo_url'] = $this->handlePhotoUpload($request->file('photo'), 'category_photos');
        }

        // Update the electronic category
        $validated['updated_by'] = Auth::id();
        $electronicCategory->update($validated);

        return response()->json(['message' => 'Electronic category updated successfully', 'data' => $electronicCategory]);
    }

    // Destroy function to delete a category
    public function destroy($id)
    {
        $electronicCategory = ElectronicCategory::find($id);

        if (!$electronicCategory) {
            return response()->json(['message' => 'Electronic category not found'], 404);
        }

        // Delete the photo if it exists
        if ($electronicCategory->photo_url) {
            $this->deleteLocalPhoto($electronicCategory->photo_url);
        }

        // Delete the category
        $electronicCategory->delete();

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