<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\BrandAccessory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class BrandAccessoryController extends Controller
{
    public function index()
    {
        $accessories = BrandAccessory::get();
        return response()->json(['data' => $accessories]);
    }

    public function show($id)
    {
        $accessory = BrandAccessory::with(['categoryBrand', 'createdBy', 'updatedBy'])->find($id);
        if (!$accessory) {
            return response()->json(['message' => 'Brand Accessory not found'], 404);
        }
        return response()->json($accessory);
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
            'category_brands_id' => 'required|exists:category_brands,id',
        ]);

        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $photoUrl = $this->uploadPhoto($request->file('photo'), 'accessory_photos');
        }

        $accessory = BrandAccessory::create([
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'active',
            'photo_url' => $photoUrl,
            'price' => $validated['price'],
            'quantity' => $validated['quantity'],
            'details' => $validated['details'],
            'category_brands_id' => $validated['category_brands_id'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Brand Accessory created successfully', 'data' => $accessory], 201);
    }

    public function update(Request $request, $id)
    {
        $accessory = BrandAccessory::find($id);
        if (!$accessory) {
            return response()->json(['message' => 'Brand Accessory not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status' => 'nullable|string|max:255',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'price' => 'sometimes|required|numeric',
            'quantity' => 'sometimes|required|integer',
            'details' => 'nullable|string',
            'category_brands_id' => 'sometimes|required|exists:category_brands,id',
        ]);

        if ($request->hasFile('photo')) {
            if ($accessory->photo_url) {
                $this->deletePhoto($accessory->photo_url);
            }
            $photoUrl = $this->uploadPhoto($request->file('photo'), 'accessory_photos');
            $validated['photo_url'] = $photoUrl;
        }

        $validated['updated_by'] = Auth::id();

        $accessory->update($validated);

        return response()->json(['message' => 'Brand Accessory updated successfully', 'data' => $accessory]);
    }

    public function destroy($id)
    {
        $accessory = BrandAccessory::find($id);
        if (!$accessory) {
            return response()->json(['message' => 'Brand Accessory not found'], 404);
        }

        if ($accessory->photo_url) {
            $this->deletePhoto($accessory->photo_url);
        }

        $accessory->delete();

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