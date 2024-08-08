<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{

    public function index(Request $request)
    {
        // Uncomment and use this if you need authorization check
        // if (!Auth::user()->can('view users')) {
        //     return response()->json(['message' => 'Unauthorized'], 403);
        // }

        $query = User::query();

        // Check if vendor_id is provided and not null
        if ($request->has('vendor_id') && $request->vendor_id !== null) {
            // Filter users by the provided vendor_id
            $query->whereHas('vendors', function ($query) use ($request) {
                $query->where('vendor_id', $request->vendor_id);
            });
        }

        // Filter by role if provided
        if ($request->has('role') && $request->role !== null) {
            $query->role($request->role); // This uses the role scope provided by Spatie's permission package
        }

        // Apply search filter (if provided)
        $search = $request->query('search');
        if ($search) {
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Apply orderBy and orderDirection if both are provided
        $orderBy = $request->query('orderBy');
        $orderDirection = $request->query('orderDirection', 'asc');

        // if (isset($orderBy) && isset($orderDirection)) {
        //     // Validate orderDirection
        //     if (in_array($orderDirection, ['asc', 'desc'])) {
        //         // Check if the column exists in the users table
        //         if (Schema::hasColumn('users', $orderBy)) {
        //             $query->orderBy($orderBy, $orderDirection);
        //         }
        //     }
        // }

        $query->latest();
        // Pagination
        $perPage = $request->query('per_page', 10); // Default to 10 per page
        $page = $request->query('page', 1); // Default to first page

        $paginatedUsers = $query->paginate($perPage);

        // Adding role names and permissions to each user in the data collection
        $paginatedUsers->getCollection()->transform(function ($user) {
            $user->role = $user->getRoleNames()->first() ?? "";
            $user->permissions = $user->getAllPermissions()->pluck('name') ?? null;
            return $user;
        });

        // Return the paginated response
        return response()->json($paginatedUsers);
    }

    public function show($id)
    {
        // if (!Auth::user()->can('view user')) {
        //     return response()->json(['message' => 'Unauthorized'], 403);
        // }

        // $user = User::with(["vendors.vendor"])->findOrFail($id);
        $user = User::findOrFail($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Adding role name
        $user->role = $user->getRoleNames()->first() ?? "";

        return response()->json($user);
    }

    public function store(Request $request)
    {
        // Check permission
        // if (!Auth::user()->can('create user')) {
        //     return response()->json(['message' => 'Unauthorized'], 403);
        // }

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'status' => 'required|string|max:255',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:255',
            'lastlogin' => 'nullable|date',
            'password' => 'required|string|min:8',
            'role' => 'required|exists:roles,name',
            'photo' => 'nullable|file|mimes:jpg,jpeg,png|max:2048', // Expect a file for the photo
        ]);

        $photoData = null;
        if ($request->hasFile('photo')) {
            $photoData = $this->handlePhotoUpload($request->file('photo'), 'user_photos'); // Save the photo in a specific folder
        }

        DB::beginTransaction();

        try {
            // Create user
            $user = User::create(array_merge([
                'name' => $validatedData['name'],
                'phone' => $validatedData['phone'] ?? null,
                'email' => $validatedData['email'],
                'date_of_birth' => $validatedData['date_of_birth'] ?? null,
                'gender' => $validatedData['gender'] ?? null,
                'status' => $validatedData['status'],
                'lastlogin' => $validatedData['lastlogin'] ?? now(),
                'password' => Hash::make($validatedData['password']),
            ], $photoData));

            // Sync the user's role
            $user->syncRoles([$validatedData['role']]);

            DB::commit();
            return response()->json(['message' => 'User created successfully!', 'user' => $user], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'User creation failed: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        // Check permission
        // if (!Auth::user()->can('update user')) {
        //     return response()->json(['message' => 'Unauthorized'], 403);
        // }

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $id,
            'phone' => 'nullable|string|max:255',
            'status' => 'required|string|max:255',
            'gender' => 'nullable|string|max:255',
            'date_of_birth' => 'nullable|date',
            'lastlogin' => 'nullable|date',
            'photo' => 'nullable|file|mimes:jpg,jpeg,png|max:2048', // Validation for photo
            'role' => 'sometimes|exists:roles,name',
        ]);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $photoData = [];
        if ($request->hasFile('photo')) {
            // Delete old photo if exists
            if ($user->cloudinary_photo_public_id) {
                $this->deleteCloudinaryPhoto($user->cloudinary_photo_public_id);
            } elseif ($user->photo_url) {
                $this->deleteLocalPhoto($user->photo_url);
            }

            // Upload new photo
            $photoData = $this->handlePhotoUpload($request->file('photo'), 'user_photos');
        }

        DB::beginTransaction();

        try {
            $user->update(array_merge([
                'name' => $validatedData['name'],
                'phone' => $validatedData['phone'] ?? null,
                'email' => $validatedData['email'],
                'date_of_birth' => $validatedData['date_of_birth'] ?? null,
                'gender' => $validatedData['gender'] ?? null,
                'status' => $validatedData['status'],
                'lastlogin' => $validatedData['lastlogin'] ?? now(),
            ], $photoData));

            if (isset($validatedData['role'])) {
                $user->syncRoles([$validatedData['role']]);
            }

            DB::commit();
            return response()->json(['message' => 'User updated successfully!', 'user' => $user], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    // ========================== destroy ====================

    public function destroy($id)
    {
        // if (!Auth::user()->can('delete user')) {
        //     return response()->json(['message' => 'Unauthorized'], 403);
        // }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Delete user photo if exists
        if ($user->cloudinary_photo_public_id) {
            $this->deleteCloudinaryPhoto($user->cloudinary_photo_public_id);
        } elseif ($user->photo_url) {
            $this->deleteLocalPhoto($user->photo_url);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    // Helper method to handle photo upload to Cloudinary or local storage
    private function handlePhotoUpload($photo, $folderPath)
    {
        if (env('MEDIA_STORAGE_METHOD') === 'cloudinary') {
            $uploadedFileUrl = Cloudinary::upload($photo->getRealPath(), [
                'folder' => $folderPath,
            ])->getSecurePath();

            $publicId = Cloudinary::getPublicId($uploadedFileUrl);

            return [
                'photo_url' => $uploadedFileUrl,
                'cloudinary_photo_public_id' => $publicId,
            ];
        } else {
            $publicPath = public_path($folderPath);
            if (!File::exists($publicPath)) {
                File::makeDirectory($publicPath, 0777, true, true);
            }

            $fileName = time() . '_' . $photo->getClientOriginalName();
            $photo->move($publicPath, $fileName);

            return [
                'photo_url' => '/' . $folderPath . '/' . $fileName,
                'cloudinary_photo_public_id' => null,
            ];
        }
    }

    // Helper method to delete a photo from Cloudinary
    private function deleteCloudinaryPhoto($publicId)
    {
        Cloudinary::destroy($publicId);
    }

    // Helper method to delete a local photo
    private function deleteLocalPhoto($photoUrl)
    {
        $photoPath = parse_url($photoUrl, PHP_URL_PATH);
        $photoPath = ltrim($photoPath, '/');
        if (file_exists(public_path($photoPath))) {
            unlink(public_path($photoPath));
        }
    }

}