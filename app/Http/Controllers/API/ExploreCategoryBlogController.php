<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ExploreCategoryBlog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class ExploreCategoryBlogController extends Controller
{
    public function index()
    {
        $blogs = ExploreCategoryBlog::get();
        return response()->json(['data' => $blogs]);
    }

    public function show($id)
    {
        $blog = ExploreCategoryBlog::with(['exploreCategory', 'createdBy', 'updatedBy'])->find($id);
        if (!$blog) {
            return response()->json(['message' => 'Explore Category Blog not found'], 404);
        }
        return response()->json($blog);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'nullable|string|max:255',
            'details' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'explore_categories_id' => 'required|exists:explore_categories,id',
        ]);

        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $photoUrl = $this->uploadPhoto($request->file('photo'), 'blog_photos');
        }

        $blog = ExploreCategoryBlog::create([
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'active',
            'details' => $validated['details'],
            'photo_url' => $photoUrl,
            'explore_categories_id' => $validated['explore_categories_id'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Explore Category Blog created successfully', 'data' => $blog], 201);
    }

    public function update(Request $request, $id)
    {
        $blog = ExploreCategoryBlog::find($id);
        if (!$blog) {
            return response()->json(['message' => 'Explore Category Blog not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status' => 'nullable|string|max:255',
            'details' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'explore_categories_id' => 'sometimes|required|exists:explore_categories,id',
        ]);

        if ($request->hasFile('photo')) {
            if ($blog->photo_url) {
                $this->deletePhoto($blog->photo_url);
            }
            $photoUrl = $this->uploadPhoto($request->file('photo'), 'blog_photos');
            $validated['photo_url'] = $photoUrl;
        }

        $validated['updated_by'] = Auth::id();

        $blog->update($validated);

        return response()->json(['message' => 'Explore Category Blog updated successfully', 'data' => $blog]);
    }

    public function destroy($id)
    {
        $blog = ExploreCategoryBlog::find($id);
        if (!$blog) {
            return response()->json(['message' => 'Explore Category Blog not found'], 404);
        }

        if ($blog->photo_url) {
            $this->deletePhoto($blog->photo_url);
        }

        $blog->delete();

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