<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ad;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use App\Services\FirebaseService;

class AdsController extends Controller
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
        // Build the query with eager loading
        $query = Ad::with(['createdBy', 'updatedBy']);

        // Get the query parameters
        $title = $request->query('title');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $status = $request->query('status');
        $createdBy = $request->query('created_by');
        $updatedBy = $request->query('updated_by');

        // Apply filters if the parameters are provided
        if (!empty($startDate)) {
            $query->whereDate('start_date', '>=', $startDate); // Or use other comparison as needed
        }

        if (!empty($endDate)) {
            $query->whereDate('end_date', '<=', $endDate);
        }

        if (isset($title)) {
            $query->where('title', 'like', "%$title%");
        }

        if (isset($status)) {
            $query->where('status', $status);
        }

        if (isset($createdBy)) {
            $query->where('created_by', $createdBy);
        }

        if (isset($updatedBy)) {
            $query->where('updated_by', $updatedBy);
        }

        // Add more filters as needed
        $query->latest();
        // Execute the query and get the results
        $ads = $query->get();

        // Return the results as a JSON response
        return response()->json(['data' => $ads]);
    }

    public function show($id)
    {
        $ad = Ad::with(['createdBy', 'updatedBy'])->find($id);
        if (!$ad) {
            return response()->json(['message' => 'Ad not found'], 404);
        }
        return response()->json($ad);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'details' => 'nullable|string',
            'photo' => 'nullable|string',
            'status' => 'required|in:active,inactive,pending',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $photoData = null;
        if ($request->hasFile('photo')) {
            $photoData = $this->handlePhotoUpload($request->file('photo'), 'ad_photos');
        }

        $ad = Ad::create([
            'title' => $validated['title'],
            'details' => $validated['details'],
            'photo_url' => $photoData['photo_url'] ?? null,
            'status' => $validated['status'] ?? 'pending',
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);



        // Check if the status is 'active' and the end date is greater than the current date
        if ($ad->status === 'active' && $ad->end_date > now()) {
            $this->firebaseService->sendNotificationTopic(
                $validated['title'],
                $validated['details']
            );
        }



        return response()->json(['message' => 'Ad created successfully', 'data' => $ad], 201);
    }

    public function update(Request $request, $id)
    {
        $ad = Ad::find($id);
        if (!$ad) {
            return response()->json(['message' => 'Ad not found'], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'details' => 'nullable|string',
            'photo' => 'nullable|string',
            'status' => 'required|in:active,inactive,pending',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($request->hasFile('photo')) {
            if ($ad->photo_url) {
                $this->deleteLocalPhoto($ad->photo_url);
            }

            $photoData = $this->handlePhotoUpload($request->file('photo'), 'ad_photos');
            $validated['photo_url'] = $photoData['photo_url'] ?? null;
        }

        $validated['updated_by'] = Auth::id();

        $ad->update($validated);

        // Check if the status is 'active' and the end date is greater than the current date
        if ($ad->status === 'active' && $ad->end_date > now()) {
            $this->firebaseService->sendNotificationTopic(
                $validated['title'],
                $validated['details']
            );
        }

        return response()->json(['message' => 'Ad updated successfully', 'data' => $ad]);
    }

    public function destroy($id)
    {
        $ad = Ad::find($id);
        if (!$ad) {
            return response()->json(['message' => 'Ad not found'], 404);
        }

        if ($ad->photo_url) {
            $this->deleteLocalPhoto($ad->photo_url);
        }

        $ad->delete();

        return response()->json(null, 204);
    }

    public function get_ads()
    {
        // Fetch only active ads that haven't reached the end date
        $ads = Ad::where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('end_date') // End date is null
                    ->orWhere('end_date', '>', now()); // Or end date is greater than the current date
            })
            ->get();

        return response()->json($ads);
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
