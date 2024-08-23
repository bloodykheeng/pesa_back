<?php

namespace App\Http\Controllers\API;

use App\Events\Example;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatMessageController extends Controller
{
    /**
     * Display a listing of the chat messages for a specific chat.
     */
    public function index(Request $request)
    {
        // Define variables from request
        $chatId = $request->query('chat_id');
        $isRead = $request->query('is_read');
        $senderId = $request->query('sender_id');
        $receiverId = $request->query('reciver_id');
        $name = $request->query('name');
        $status = $request->query('status');
        $createdBy = $request->query('created_by');
        $updatedBy = $request->query('updated_by');

        // Start query
        $query = ChatMessage::query();

        // Eager load relationships
        $query->with('sender', 'receiver', 'updatedBy');

        // Filter messages by chat_id
        if (isset($chatId)) {
            $query->where('chat_id', $chatId);
        }

        // Optional: Filter by read status
        if (isset($isRead)) {
            $query->where('is_read', $isRead);
        }

        // Filter by sender and receiver, considering the reverse scenario
        if (isset($senderId) && isset($receiverId)) {
            $query->where(function ($query) use ($senderId, $receiverId) {
                $query->where('sender_id', $senderId)
                    ->where('reciver_id', $receiverId)
                    ->orWhere(function ($query) use ($senderId, $receiverId) {
                        $query->where('sender_id', $receiverId)
                            ->where('reciver_id', $senderId);
                    });
            });
        }

        // Filter by sender_id if only sender_id is set
        if (isset($senderId) && !isset($receiverId)) {
            $query->where('sender_id', $senderId);
        }

        // Filter by reciver_id if only reciver_id is set
        if (isset($receiverId) && !isset($senderId)) {
            $query->where('reciver_id', $receiverId);
        }

        // Apply other filters if the parameters are provided
        if (isset($name)) {
            $query->where('name', 'like', "%$name%");
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

        // Execute the query and get the results
        $messages = $query->get();

        return response()->json(['data' => $messages]);
    }

    /**
     * Display the specified chat message.
     */
    public function show($id)
    {
        $message = ChatMessage::with('sender', 'receiver', 'updatedBy')->find($id);

        if (!$message) {
            return response()->json(['message' => 'Message not found'], 404);
        }

        return response()->json(['data' => $message]);
    }

    /**
     * Store a newly created chat message in storage.
     */
    public function store(Request $request)
    {
        // Validate the request data
        $validated = $request->validate([
            'chat_id' => 'nullable|exists:chats,id',
            'sender_id' => 'required|exists:users,id',
            'reciver_id' => 'required|exists:users,id',
            'content' => 'nullable|string',
        ]);

        try {
            $receiver = User::find($validated['reciver_id']);
            $sender = User::find($validated['sender_id']);

            // //first send before sending to databses
            // broadcast(new MessageSent($sender, $receiver, $validated['content']));

            // Check if there are any chat messages with the same sender and receiver
            // This approach ensures that you find a message between the two users, regardless of who sent the message.
            $existingMessage = ChatMessage::where(function ($query) use ($validated) {
                $query->where('sender_id', $validated['sender_id'])
                    ->where('reciver_id', $validated['reciver_id']);
            })->orWhere(function ($query) use ($validated) {
                $query->where('sender_id', $validated['reciver_id'])
                    ->where('reciver_id', $validated['sender_id']);
            })->first();

            // If no such chat message exists, create a new chat
            if (!$existingMessage) {
                $chat = Chat::create([
                    'name' => null, // Set the chat name if required
                    'is_group' => false,
                    'created_by' => $validated['sender_id'],
                    'updated_by' => $validated['sender_id'], // Or set this to the reciver_id based on your requirement
                ]);
            } else {
                // If a chat message exists, use the associated chat
                $chat = Chat::find($existingMessage->chat_id);
            }

            // Create the ChatMessage
            $message = ChatMessage::create([
                'chat_id' => $chat->id,
                'sender_id' => $validated['sender_id'],
                'reciver_id' => $validated['reciver_id'],
                'content' => $validated['content'],
                'is_read' => false,
                'updated_by' => Auth::id(),
            ]);

            broadcast(new MessageSent($sender, $receiver, $validated['content'], $chat));
            broadcast(new Example($sender, $validated['content']));

            return response()->noContent();

            // return response()->json(['message' => 'Message sent successfully', 'data' => $message], 201);
        } catch (\Exception $e) {
            // Handle the exception, log it, or return an appropriate error response
            return response()->json(['message' => 'Failed to send message', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified chat message in storage.
     */
    public function update(Request $request, $id)
    {
        $message = ChatMessage::find($id);

        if (!$message) {
            return response()->json(['message' => 'Message not found'], 404);
        }

        $validated = $request->validate([
            'content' => 'nullable|string',
            'is_read' => 'nullable|boolean',
            'sender_id' => 'nullable|exists:users,id',
            'reciver_id' => 'nullable|exists:users,id',
        ]);

        try {
            // Check if a chat message exists with the same sender and receiver, if provided
            if (isset($validated['sender_id']) && isset($validated['reciver_id'])) {
                $existingMessage = ChatMessage::where('sender_id', $validated['sender_id'])
                    ->where('reciver_id', $validated['reciver_id'])
                    ->first();

                // If no such chat message exists, create a new chat
                if (!$existingMessage) {
                    $chat = Chat::create([
                        'name' => null, // Set the chat name if required
                        'is_group' => false,
                        'created_by' => $validated['sender_id'],
                        'updated_by' => $validated['sender_id'], // Or set this to the reciver_id based on your requirement
                    ]);

                    // Update the existing message with the new chat_id
                    $message->update([
                        'chat_id' => $chat->id,
                        'sender_id' => $validated['sender_id'],
                        'reciver_id' => $validated['reciver_id'],
                    ]);
                } else {
                    // If a chat message exists, use the associated chat
                    $message->chat_id = $existingMessage->chat_id;
                }
            }

            // Update the ChatMessage
            $message->update([
                'content' => $validated['content'] ?? $message->content,
                'is_read' => $validated['is_read'] ?? $message->is_read,
                'updated_by' => Auth::id(),
            ]);

            return response()->json(['message' => 'Message updated successfully', 'data' => $message]);
        } catch (\Exception $e) {
            // Handle the exception, log it, or return an appropriate error response
            return response()->json(['message' => 'Failed to update message', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified chat message from storage.
     */
    public function destroy($id)
    {
        $message = ChatMessage::find($id);

        if (!$message) {
            return response()->json(['message' => 'Message not found'], 404);
        }

        try {
            $message->delete(); // Delete the message

            return response()->json(['message' => 'Message deleted successfully'], 204);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete message', 'error' => $e->getMessage()], 500);
        }
    }
}