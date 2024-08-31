<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\User;
use App\Services\FirebaseService;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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

    public function index(Request $request)
    {
        $query = Package::query();

        // Eager load relationships
        $query->with(['updatedBy', 'createdBy']);

        // Apply filters if present
        if ($request->has('created_by')) {
            $query->where('created_by', $request->input('created_by'));
        }

        if ($request->has('updated_by')) {
            $query->where('updated_by', $request->input('updated_by'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('pickup')) {
            $query->where('pickup', 'like', '%' . $request->input('pickup') . '%');
        }

        if ($request->has('destination')) {
            $query->where('destination', 'like', '%' . $request->input('destination') . '%');
        }

        if ($request->has('order_number')) {
            $query->where('order_number', $request->input('order_number'));
        }

        // Add date range filter
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('created_at', [$request->input('start_date'), $request->input('end_date')]);
        }

        // Add search functionality
        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', '%' . $searchTerm . '%')
                    ->orWhere('pickup', 'like', '%' . $searchTerm . '%')
                    ->orWhere('destination', 'like', '%' . $searchTerm . '%')
                    ->orWhere('order_number', 'like', '%' . $searchTerm . '%');
            });
        }

        // Add sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // // Paginate the results
        // $perPage = $request->input('per_page', 15);
        // $packages = $query->paginate($perPage);

        $query->latest();

        $packages = $query->get();

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
            'charged_amount' => 'nullable|numeric',
            'amount_paid' => 'nullable|numeric',
            'payment_status' => 'nullable|string|max:255',
            'delivery_status' => 'nullable|string|max:255',
            'package_number' => 'nullable|string|max:255',
            'payment_mode' => 'nullable|string|max:255',
        ]);

        // Handle the photo upload
        $photoData = $this->handlePhotoUpload($request->file('photo'), 'package_photos');

        // Generate a unique order number
        do {
            $packageNumber = strtoupper(Str::random(10));
        } while (Package::where('package_number', $packageNumber)->exists());

        $package = Package::create(array_merge([
            'name' => $validated['name'],
            'pickup' => $validated['pickup'],
            'destination' => $validated['destination'],
            'photo_url' => $photoData['photo_url'] ?? null,
            'status' => $validated['status'] ?? 'pending',
            'extraInfo' => $validated['extraInfo'],
            'package_number' => $packageNumber,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
            'charged_amount' => $validated['charged_amount'] ?? 0,
            'amount_paid' => $validated['amount_paid'] ?? 0,
            'balance_due' => $validated['balance_due'] ?? 0,
            // 'balance_due' => $validated['charged_amount'] - ($validated['amount_paid'] ?? 0),
            'payment_status' => $validated['payment_status'] ?? 'pending',
            'delivery_status' => $validated['delivery_status'] ?? 'pending',
            'payment_mode' => $validated['payment_mode'] ?? 'unknown',
        ], $photoData));

        // Send notification to the user
        $user = User::find($package->created_by);
        $this->firebaseService->sendNotification(
            $user->device_token,
            'New Package Order',
            'Package order #' . $packageNumber . ' has been created successfully'
        );

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
            // 'photo' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif,svg',
            'status' => 'nullable|string|max:255',
            'extraInfo' => 'required|string',
            'charged_amount' => 'nullable|numeric',
            'amount_paid' => 'nullable|numeric',
            'payment_status' => 'nullable|string|max:255',
            'delivery_status' => 'nullable|string|max:255',
            'package_number' => 'nullable|string|max:255',
            'payment_mode' => 'nullable|string|max:255',
        ]);

        if ($request->hasFile('photo')) {
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

        // Update balance due if charged amount or amount paid has changed
        if (isset($validated['charged_amount']) || isset($validated['amount_paid'])) {
            $validated['balance_due'] = ($validated['charged_amount'] ?? $package->charged_amount) - ($validated['amount_paid'] ?? $package->amount_paid);
        }

        $package->update($validated);

        $user = User::find($package->created_by);

        if ($package->status || $package->delivery_status  === 'processing') {

            $this->firebaseService->sendNotification($user->device_token, 'Package Status ', 'Your package order# ' . $package->package_number . ' is being processed');
        }

        if ($package->statu || $package->delivery_statuss === 'transit') {

            $this->firebaseService->sendNotification($user->device_token, 'Package Status ', 'Your package order# ' . $package->package_number . ' is being transported');
        }

        if ($package->status || $package->delivery_status === 'delivered') {

            $this->firebaseService->sendNotification($user->device_token, 'Package Status ', 'Your package order# ' . $package->package_number . ' has been delivered');
        }

        if ($package->status || $package->delivery_status === 'cancelled') {

            $this->firebaseService->sendNotification($user->device_token, 'Package Status ', 'Your package order# ' . $package->package_number . ' is being cancelled');
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
        $package->delivery_status = 'cancelled';
        $package->payment_status = 'cancelled';

        // Save the changes
        $package->save();

        $user = User::find($package->created_by);
        $this->firebaseService->sendNotification($user->device_token, 'Order Cancellation ', 'Package Order# ' . $package->package_number . ' has been cancelled.');

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