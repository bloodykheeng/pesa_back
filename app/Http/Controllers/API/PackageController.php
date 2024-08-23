<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\User;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Services\FirebaseService;

class PackageController extends Controller
{
    /**
     * Display a listing of the resource.
     */

     protected $firebaseService;

     public function __construct(FirebaseService $firebaseService)
     {
         $this->firebaseService = $firebaseService;
     }


    public function index()
    {
        $packages = Package::with(['updatedBy', 'createdBy'])->get();
        return response()->json(['data' => $packages]);
    }

    public function myPackages()
    {
        $user = User::find(Auth::user()->id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $packages = Package::with(['createdBy'])
            ->where('created_by', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        if (!$packages) {
            return response()->json(['message' => 'Packages not found'], 404);
        }
        return response()->json(['data' => $packages]);
    }

    public function show($id)
    {
        $package = Package::with(['createdBy', 'updatedBy'])->find($id);
        if (!$package) {
            return response()->json(['message' => 'Package not found'], 404);
        }
        return response()->json($package);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'pickup' => 'required|string|max:255',
            'destination' => 'required|string|max:255',
            // 'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
            'status' => 'nullable|string|max:255',
            'extraInfo' => 'nullable|string',
        ]);

        $photoData = $this->handlePhotoUpload($request->file('photo'), 'package_photos');

        do {
            $orderNumber = strtoupper(Str::random(10));
        } while (Package::where('order_number', $orderNumber)->exists());

        // $orderNumber = strtoupper(Str::uuid()->toString());

        $package = Package::create(array_merge([
            'name' => $validated['name'],
            'pickup' => $validated['pickup'],
            'destination' => $validated['destination'],
            'status' => $validated['status'] ?? 'pending',
            'extraInfo' => $validated['extraInfo'],
            'order_number' => $orderNumber,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ], $photoData));

        $user = User::find($package->created_by);
        $this->firebaseService->sendNotification($user->device_token, 'New Package Order', 'Package order #'.$orderNumber.' has been created successfully',);

        return response()->json(['message' => 'Package created successfully', 'data' => $package], 201);
    }

    public function update(Request $request, $id)
    {
        $package = Package::find($id);
        if (!$package) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'pickup' => 'required|string|max:255',
            'destination' => 'required|string|max:255',
            // 'photo' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|string|max:255',
            'extraInfo' => 'required|string',
        ]);

        if ($request->hasFile('photo')) {
            // return response()->json(['message' => 'testing'], 404);
            // Delete existing photo
            if ($package->cloudinary_photo_public_id) {
                $this->deleteCloudinaryPhoto($package->cloudinary_photo_public_id);
            } elseif ($package->photo_url) {
                $this->deleteLocalPhoto($package->photo_url);
            }

            // Upload new photo
            $photoData = $this->handlePhotoUpload($request->file('photo'), 'package_photos');
            $validated = array_merge($validated, $photoData);
        }

        $validated['updated_by'] = Auth::id();

        // return response()->json(['message' => 'testing', ' $validated' => $validated], 404);

        $package->update($validated);

        $user = User::find($package->created_by);

        if($package->status === 'processing'){

            $this->firebaseService->sendNotification($user->device_token, 'Package Status ', 'Your package order# '.$package->order_number.' is being processed');
        }

        if($package->status === 'transit'){

            $this->firebaseService->sendNotification($user->device_token, 'Package Status ', 'Your package order# '.$package->order_number.' is being transported');
        }

        if($package->status === 'delivered' ){

            $this->firebaseService->sendNotification($user->device_token, 'Package Status ', 'Your package order# '.$package->order_number.' has been delivered');
        }

        if($package->status === 'cancelled' ){

            $this->firebaseService->sendNotification($user->device_token, 'Package Status ', 'Your package order# '.$package->order_number.' is being cancelled');
        }

        return response()->json(['message' => 'Package updated successfully', 'data' => $package]);
    }



    public function cancelPackageOrder(Request $request, $id)
    {
        // Find the order by ID
        $package = Package::find($id);

        // Check if the order exists
        if (!$package) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        // Update the delivery status to 'received'
        $package->status = 'cancelled';

        // Save the changes
        $package->save();

        $user = User::find($package->created_by);
        $this->firebaseService->sendNotification($user->device_token, 'Order Cancellation ', 'Package Order# '.$package->order_number.' has been cancelled.');

        // Return a success response
        return response()->json(['message' => 'Package status updated to cancelled'], 200);
    }

    public function destroy($id)
    {
        $package = Package::find($id);
        if (!$package) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        if ($package->cloudinary_photo_public_id) {
            $this->deleteCloudinaryPhoto($package->cloudinary_photo_public_id);
        } elseif ($package->photo_url) {
            $this->deleteLocalPhoto($package->photo_url);
        }

        $package->delete();

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
