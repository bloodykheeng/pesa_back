<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Message;

class MessageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $messages = Message::with("sender", "receiver")->orderBy('created_at', 'desc')->get();
        return response()->json($messages);
    }

    /**
     * Show the form for creating a new resource.
     */
    // public function create()
    // {
    //     //
    // }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'content' => 'required',
            'senderId' => 'required|exists:users,id',
            'receiverId' => 'required|exists:users,id',
        ]);

        $message = Message::create($request->all());
        return response()->json($message, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $messageId)
    {
        $message = Message::findOrFail($messageId);
        return response()->json($message);
    }

    public function chat(int $senderId, int $receiverId)
    {
        $messages = Message::where('receiverId', $receiverId)->where('senderId', $senderId)->with("sender", "pharmacy")->orderBy('created_at', 'desc')->get();
        return response()->json($messages);
    }

    public function clientMsgs(String $senderId)
    {
        // return response()->json([
        //     'messages' => $senderId
        // ]);
        $messages = Message::where('senderId', $senderId)->with("sender", "pharmacy")->orderBy('created_at', 'desc')->get();
        $unreadMessages = Message::where('senderId', $senderId)->where('senderType', 'Business')->where('is_read', false)->with("sender", "pharmacy")->orderBy('created_at', 'desc')->get();

        $groupedMessages = $messages->groupBy('receiverId');

        // Transform the collection into the desired structure
        $orderedMessages = $groupedMessages->map(function ($messages, $receiverId) {
            // Get the pharmacy details (assuming all messages have the same pharmacy)
            $receiver = $messages->first()->receiver;
            $unread = [];
            if (array() === $messages->where('is_read', false)->where('senderType', 'Business')) {
                $unread = $messages->where('is_read', false)->where('senderType', 'Business');
            } else {
                $unread = $messages->where('is_read', false)->where('senderType', 'Business')->values()->toArray();
            }
            // Return the pharmacy with its messages
            return [
                'pharmacy' => $receiver,

                'messages' => $unread,

                'last' => $messages->first()
            ];
        })->values();

        return response()->json(['orderedMessages' => $orderedMessages, 'unreadMessages' => $unreadMessages]);
    }

    public function businessMsgs(String $receiverId)
    {
        // return response()->json([
        //     'messages' => $senderId
        // ]);
        $messages = Message::where('receiverId', $receiverId)->with("sender", "pharmacy")->orderBy('created_at', 'desc')->get();
        $unreadMessages = Message::where('receiverId', $receiverId)->where('senderType', 'Client')->where('is_read', false)->with("sender", "pharmacy")->orderBy('created_at', 'desc')->get();

        $groupedMessages = $messages->groupBy('senderId');

        // Transform the collection into the desired structure
        $orderedMessages = $groupedMessages->map(function ($messages, $receiverId) {
            // Get the pharmacy details (assuming all messages have the same pharmacy)
            $sender = $messages->first()->sender;
            $unread = [];
            if (array() === $messages->where('is_read', false)->where('senderType', 'Client')) {
                $unread = $messages->where('is_read', false)->where('senderType', 'Client');
            } else {
                $unread = $messages->where('is_read', false)->where('senderType', 'Client')->values()->toArray();
            }
            // Return the pharmacy with its messages
            return [
                'sender' => $sender,

                'messages' => $unread,

                'last' => $messages->first()
            ];
        })->values();

        return response()->json(['orderedMessages' => $orderedMessages, 'unreadMessages' => $unreadMessages]);
    }



    /**
     * Show the form for editing the specified resource.
     */
    // public function edit(string $id)
    // {
    //     //
    // }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $messageId)
    {
        $request->validate([
            'content' => 'sometimes|required',
            'senderId' => 'sometimes|required|exists:users,id',
        ]);

        $message = Message::findOrFail($messageId);
        $message->update($request->all());
        return response()->json($message);
    }

    public function updateMessagetoRead(int $senderId, int $receiverId)
    {
        Message::where('receiverId', $receiverId)->where('senderId', $senderId)->where('is_read', false)->update(['is_read' => true]);
        $unreadMessages = Message::where('receiverId', $receiverId)->where('senderId', $senderId)->where('is_read', false)->get();
        return response()->json(['unread' => $unreadMessages]);
    }
    

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $messageId)
    {
        $message = Message::findOrFail($messageId);
        $message->delete();
        return response()->json(null, 204);
    }
}
