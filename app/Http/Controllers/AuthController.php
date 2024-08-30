<?php

namespace App\Http\Controllers;

use App\Models\ThirdPartyAuthProvider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * @OA\Tag(
 *     name="Authentication",
 *     description="Endpoints for user authentication"
 * )
 */
class AuthController extends Controller
{

    public function register(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'nin' => 'nullable|string|max:255|unique:users',
            'role' => 'required|exists:roles,name', // Validate that the role exists
            // 'vendor_id' => 'nullable|exists:vendors,id',
            // 'phone' => 'required|string|regex:/^\+\d{12}$/', // Validate phone number with country code
            'phone' => 'required|string|max:255|unique:users',
            // 'date_of_birth' => 'nullable|date',
            'agree' => 'required|boolean',
        ]);

        try {
            // Check if the role exists before creating the user
            if (!Role::where('name', $request->role)->exists()) {
                return response()->json(['message' => 'Role does not exist'], 400);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'nin' => $request->nin,
                'status' => $request->status,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'date_of_birth' => $request->date_of_birth,
                'agree' => $request->agree,
            ]);

            // Sync the user's role
            $user->syncRoles([$request->role]);

            // Handle UserVendor relationship
            // if (isset($validatedData['vendor_id'])) {
            //     $user->vendors()->create(['vendor_id' => $validatedData['vendor_id']]);
            // }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'data' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function checkLoginStatus()
    {
        // Check if the user is logged in
        if (!Auth::check()) {
            return response()->json(['message' => 'User is not logged in'], 401);
        }

        /** @var \App\Models\User */
        $user = Auth::user();

        // Retrieve the token
        $token = $user->tokens->first()->token ?? ''; // Adjusted to handle potential null value

        $response = [
            'message' => 'Hi ' . $user->name . ', welcome to home',
            'id' => $user->id,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'name' => $user->name,
            'lastlogin' => $user->lastlogin,
            'email' => $user->email,
            'nin' => $user->nin,
            'status' => $user->status,
            'photo_url' => $user->photo_url,
            'permissions' => $user->getAllPermissions()->pluck('name'), // pluck for simplified array
            'role' => $user->getRoleNames()->first() ?? "",
            'phone' => $user->phone,
            'date_of_birth' => $user->date_of_birth,
            'agree' => $user->agree,
        ];

        // Check if the user is a Vendor and include vendor details
        // if ($user->hasRole('Vendor')) {
        //     $vendor = $user->vendors()->first(); // Assuming there's a vendors() relationship
        //     $response['vendor'] = [
        //         'id' => $vendor->vendor_id ?? null,
        //         'name' => $vendor->vendor->name ?? 'Unknown Vendor', // Assuming there's a name attribute on the vendor
        //     ];
        // }

        return response()->json($response);
    }

    public function login(Request $request)
    {
        $loginField = filter_var($request->input('credential'), FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        // return response()->json(['message' => 'testing', ' $loginField' => $loginField], 401);

        if (!Auth::attempt([$loginField => $request->input('credential'), 'password' => $request->input('password')])) {
            return response()->json(['message' => 'Invalid Email/Phone Number Or Password'], 401);
        }

        $user = User::where($loginField, $request->input('credential'))->firstOrFail();

        // Check if the user's status is active
        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account is not active'], 403);
        }

        // Check if the user's role is neither 'admin' nor 'customer'
        $role = $user->getRoleNames()->first();
        if ($role !== 'Admin' && $role !== 'Customer') {
            return response()->json(['message' => 'You are not authorized to login from here'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = [
            'message' => 'Hi ' . $user->name . ', welcome to home',
            'id' => $user->id,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'name' => $user->name,
            'photo_url' => $user->photo_url,
            'lastlogin' => $user->lastlogin,
            'email' => $user->email,
            'nin' => $user->nin,
            'status' => $user->status,
            'cloudinary_photo_url' => $user->cloudinary_photo_url,
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'role' => $user->getRoleNames()->first() ?? "",
            'phone' => $user->phone,
            'date_of_birth' => $user->date_of_birth,
            'agree' => $user->agree,
        ];

        // // Include vendor details if the user is a Vendor
        // if ($user->hasRole('Vendor')) {
        //     $vendor = $user->vendors()->first(); // Assuming there's a vendors() relationship
        //     $response['vendor'] = [
        //         'id' => $vendor->vendor_id ?? null,
        //         'name' => $vendor->vendor->name ?? 'Unknown Vendor',
        //     ];
        // }

        return response()->json($response);
    }

    public function applogin(Request $request)
    {
        $loginField = filter_var($request->input('credential'), FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        // return response()->json(['message' => 'testing', ' $loginField' => $loginField], 401);

        if (!Auth::attempt([$loginField => $request->input('credential'), 'password' => $request->input('password')])) {
            return response()->json(['message' => 'Invalid Email/Phone Number Or Password'], 401);
        }

        $user = User::where($loginField, $request->input('credential'))->firstOrFail();

        // Check if the user's status is active
        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account is not active'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = [
            'message' => 'Hi ' . $user->name . ', welcome to home',
            'id' => $user->id,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'name' => $user->name,
            'photo_url' => $user->photo_url,
            'lastlogin' => $user->lastlogin,
            'email' => $user->email,
            'nin' => $user->nin,
            'status' => $user->status,
            'cloudinary_photo_url' => $user->cloudinary_photo_url,
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'role' => $user->getRoleNames()->first() ?? "",
            'phone' => $user->phone,
            'date_of_birth' => $user->date_of_birth,
            'agree' => $user->agree,
        ];

        // // Include vendor details if the user is a Vendor
        // if ($user->hasRole('Vendor')) {
        //     $vendor = $user->vendors()->first(); // Assuming there's a vendors() relationship
        //     $response['vendor'] = [
        //         'id' => $vendor->vendor_id ?? null,
        //         'name' => $vendor->vendor->name ?? 'Unknown Vendor',
        //     ];
        // }

        return response()->json($response);
    }

    public function thirdPartyLoginAuthentication(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required',
                'email' => 'nullable',
                'picture' => 'required',
                'client_id' => 'required',
                'provider' => 'required',
            ]);

            // Check if the email is provided
            if ($request->has('email')) {
                // Check if the user already exists with the provided email
                $user = User::where('email', $request->email)->first();
                if (empty($user)) {
                    return response()->json(['message' => 'Invalid Email'], 401);
                }

                // Check if the user's role is neither 'admin' nor 'customer'
                $role = $user->getRoleNames()->first();
                if ($role !== 'Admin' && $role !== 'Customer') {
                    return response()->json(['message' => 'You are not authorized to login from here'], 403);
                }

                // If the user exists, associate the provider with this user
                if ($user) {

                    // Create or update the provider entry
                    $user->providers()->updateOrCreate(
                        [
                            'provider' => $request->provider,
                            'provider_id' => $request->client_id,
                        ],
                        [
                            'photo_url' => $request->picture,
                        ]
                    );

                    // Log the user in
                    Auth::login($user);

                    // Retrieve the token
                    $token = $user->createToken('auth_token')->plainTextToken;

                    // Prepare the response
                    $response = [
                        'message' => 'Hi ' . $user->name . ', welcome to home',
                        'id' => $user->id,
                        'access_token' => $token,
                        'token_type' => 'Bearer',
                        'name' => $user->name,
                        'lastlogin' => $user->lastlogin,
                        'email' => $user->email,
                        'nin' => $user->nin,
                        'status' => $user->status,
                        'photo_url' => $user->photo_url,
                        'permissions' => $user->getAllPermissions()->pluck('name'), // pluck for simplified array
                        'role' => $user->getRoleNames()->first() ?? "",
                    ];

                    // Check if the user is a Vendor and include vendor details
                    // if ($user->hasRole('Vendor')) {
                    //     $vendor = $user->vendors()->first(); // Assuming there's a vendors() relationship
                    //     $response['vendor'] = [
                    //         'id' => $vendor->vendor_id ?? null,
                    //         'name' => $vendor->vendor->name ?? 'Unknown Vendor', // Assuming there's a name attribute on the vendor
                    //     ];
                    // }

                    // Return the response
                    return response()->json($response, 200);
                }
            }

            if (empty($request->has('email'))) {
                // If email is not provided or user does not exist with the provided email, check providers table
                $provider = ThirdPartyAuthProvider::where('provider_id', $request->client_id)->first();

                if (empty($provider->user)) {
                    return response()->json(['message' => 'Invalid Credentials'], 401);
                }

                // If provider found, associate the provider with the user
                if (isset($provider->user)) {
                    // Log the user in
                    Auth::login($provider->user);

                    // Retrieve the token
                    $token = $provider->user->createToken('auth_token')->plainTextToken;

                    // Prepare the response
                    $response = [
                        'message' => 'Hi ' . $provider->user->name . ', welcome to home',
                        'id' => $provider->user->id,
                        'access_token' => $token,
                        'token_type' => 'Bearer',
                        'name' => $provider->user->name,
                        'lastlogin' => $provider->user->lastlogin,
                        'email' => $provider->user->email,
                        'nin' => $user->nin,
                        'status' => $provider->user->status,
                        'photo_url' => $provider->user->photo_url,
                        'permissions' => $provider->user->getAllPermissions()->pluck('name'), // pluck for simplified array
                        'role' => $provider->user->getRoleNames()->first() ?? "",
                    ];

                    // Check if the user is a Vendor and include vendor details
                    // if ($provider->user->hasRole('Vendor')) {
                    //     $vendor = $provider->user->vendors()->first(); // Assuming there's a vendors() relationship
                    //     $response['vendor'] = [
                    //         'id' => $vendor->vendor_id ?? null,
                    //         'name' => $vendor->vendor->name ?? 'Unknown Vendor', // Assuming there's a name attribute on the vendor
                    //     ];
                    // }

                    // Return the response
                    return response()->json($response, 200);
                }
            }

            // If no user or provider found, throw error
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function thirdPartyRegisterAuthentication(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required',
                'email' => 'nullable|email|unique:users,email',
                'picture' => 'required',
                'client_id' => 'required',
                'provider' => 'required',
            ]);

            // Check if the email is provided
            if ($request->has('email')) {
                // Check if the user already exists with the provided email
                $user = User::where('email', $request->email)->first();

                if (isset($user)) {
                    return response()->json(['message' => 'Account Already Exists'], 409);
                }

                // $user = User::firstOrCreate(
                //     ['email' => $request->email], // Check by email
                //     [
                //         'email_verified_at' => now(),
                //         'name' => $request->name, // Use name from request
                //         'status' => "active",
                //         'password' => Hash::make($request->client_id),
                //     ]
                // );

                // If the user exists, associate the provider with this user
                if (empty($user)) {

                    $user = User::Create(
                        [
                            'email' => $request->email,
                            'email_verified_at' => now(),
                            'name' => $request->name, // Use name from request
                            'status' => "active",
                            'password' => Hash::make($request->client_id),
                        ]
                    );

                    // Create or update the provider entry
                    $user->providers()->updateOrCreate(
                        [
                            'provider' => $request->provider,
                            'provider_id' => $request->client_id,
                        ],
                        [
                            'photo_url' => $request->picture,
                        ]
                    );

                    // Log the user in
                    // Auth::login($user);

                    // Retrieve the token
                    $token = $user->createToken('auth_token')->plainTextToken;

                    return response()->json([
                        'data' => $user,
                        'access_token' => $token,
                        'token_type' => 'Bearer',
                    ]);
                }
            }

            if (empty($request->has('email'))) {
                // If email is not provided or user does not exist with the provided email, check providers table
                $provider = ThirdPartyAuthProvider::where('provider_id', $request->client_id)->first();

                // If provider found, associate the provider with the user
                if (isset($provider->user)) {
                    return response()->json(['message' => 'Account Already Exists'], 409);
                } else {
                    // If provider is not set, create the user
                    $user = User::create([
                        'name' => $request->name,
                        'email' => null,
                        'status' => 'active', // Assuming default status is true
                        'password' => Hash::make($request->client_id),
                    ]);

                    // Create the provider entry for the new user
                    $newProvider = ThirdPartyAuthProvider::create([
                        'provider' => $request->provider,
                        'provider_id' => $request->client_id,
                        'user_id' => $user->id,
                        'photo_url' => $request->picture,
                    ]);

                    // Log the user in
                    // Auth::login($user);

                    // Retrieve the token
                    $token = $user->createToken('auth_token')->plainTextToken;

                    return response()->json([
                        'data' => $user,
                        'message' => 'Account created successfully',
                        'access_token' => $token,
                        'token_type' => 'Bearer',
                    ]);
                }
            }

            // If no user or provider found, throw error
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    // method for user logout and delete token
    public function logout()
    {
        /** @var \App\Models\User */
        $user = auth()->user(); // Get the authenticated user

        // Delete all tokens for the user
        $user->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * @OA\Post(
     *      path="/logout",
     *      operationId="logout",
     *      tags={"Authentication"},
     *      summary="Logout",
     *      description="Log out the currently authenticated user",
     *      security={
     *          {"bearerAuth": {}}
     *      },
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(
     *              @OA\Property(property="status", type="integer", example=200),
     *              @OA\Property(property="message", type="string", example="You have successfully logged out and your token has been deleted"),
     *          ),
     *      )
     * )
     */
    // public function logout()
    // {
    //     Auth::user()->currentAccessToken()->delete();

    //     return $this->success(['message' => 'You have successfully logged out and your token has been deleted']);
    // }
}