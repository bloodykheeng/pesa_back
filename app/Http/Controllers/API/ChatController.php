<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    /**
     * Display a listing of the chats.
     */
    public function index(Request $request)
    {
        $query = Chat::query();

        // Eager load relationships
        $query->with('createdBy', 'updatedBy', 'messages');

        // Apply filters if present
        if ($request->has('created_by')) {
            $query->where('created_by', $request->input('created_by'));
        }

        if ($request->has('is_group')) {
            $query->where('is_group', $request->input('is_group'));
        }

        // Add more filters as needed

        $chats = $query->get();

        return response()->json(['data' => $chats]);
    }

    /**
     * Display the specified chat.
     */
    public function show($id)
    {
        $chat = Chat::with('createdBy', 'updatedBy', 'messages')->find($id);

        if (!$chat) {
            return response()->json(['message' => 'Chat not found'], 404);
        }

        return response()->json(['data' => $chat]);
    }

    /**
     * Store a newly created chat in storage.
     */
    public function store(Request $request)
    {
        // Validate the request data
        $validated = $request->validate([
            'name' => 'nullable|string',
            'is_group' => 'required|boolean',
        ]);

        try {
            // Create the Chat
            $chat = Chat::create([
                'name' => $validated['name'],
                'is_group' => $validated['is_group'],
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            return response()->json(['message' => 'Chat created successfully', 'data' => $chat], 201);
        } catch (\Exception $e) {
            // Handle the exception, log it, or return an appropriate error response
            return response()->json(['message' => 'Failed to create chat', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified chat in storage.
     */
    public function update(Request $request, $id)
    {
        $chat = Chat::find($id);

        if (!$chat) {
            return response()->json(['message' => 'Chat not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'nullable|string',
            'is_group' => 'nullable|boolean',
        ]);

        try {
            // Update the Chat
            $chat->update([
                'name' => $validated['name'] ?? $chat->name,
                'is_group' => $validated['is_group'] ?? $chat->is_group,
                'updated_by' => Auth::id(),
            ]);

            return response()->json(['message' => 'Chat updated successfully', 'data' => $chat]);
        } catch (\Exception $e) {
            // Handle the exception, log it, or return an appropriate error response
            return response()->json(['message' => 'Failed to update chat', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified chat from storage.
     */
    public function destroy($id)
    {
        $chat = Chat::find($id);

        if (!$chat) {
            return response()->json(['message' => 'Chat not found'], 404);
        }

        try {
            $chat->messages()->delete(); // Delete related messages
            $chat->delete(); // Delete the chat itself

            return response()->json(['message' => 'Chat deleted successfully'], 204);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete chat', 'error' => $e->getMessage()], 500);
        }
    }
}