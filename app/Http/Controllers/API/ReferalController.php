<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Referal;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReferalController extends Controller
{

    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $query = Referal::with(['updatedBy', 'createdBy']);

        $query->latest();
        $referals = $query->get();
        return response()->json(['data' => $referals]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        DB::beginTransaction();

        try {
            // Create user
            $referal = Referal::create(array_merge([
                'name' => $validatedData['name'],
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]));

            DB::commit();

            $user = User::find(Auth::user()->id);
            $this->firebaseService->sendNotification($user->device_token, "Referal", "You've successfully added a referal.");

            return response()->json(['message' => 'Referal created successfully!', 'user' => $referal], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Referal creation failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
