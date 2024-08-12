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

    public function sendMessage(Request $request)
{
    $message = new Message();
    $message->content = $request->input('content');
    $message->senderId = auth()->user()->id;
    $message->receiverId = $request->input('receiverId'); // Admin's ID for customer, customer's ID for admin
    $message->save();

    return response()->json($message);
}

public function getMessages(Request $request)
{
    $userId = auth()->user()->id;
    $withUserId = $request->query('with_user'); // Admin's ID for customer, customer's ID for admin

    $messages = Message::where(function ($query) use ($userId, $withUserId) {
        $query->where('senderId', $userId)
              ->where('receiverId', $withUserId);
    })->orWhere(function ($query) use ($userId, $withUserId) {
        $query->where('senderId', $withUserId)
              ->where('receiverId', $userId);
    })->orderBy('created_at', 'asc') // Order by timestamp for chat sequence
    ->get();

    return response()->json($messages);
}

public function markAsRead($id)
{
    $message = Message::findOrFail($id);
    $message->is_read = true;
    $message->save();

    return response()->json($message);
}

public function deleteMessage($id)
{
    $message = Message::findOrFail($id);
    $message->delete();

    return response()->json(['success' => true]);
}



}
