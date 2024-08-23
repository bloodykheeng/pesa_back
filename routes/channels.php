<?php

use Illuminate\Support\Facades\Broadcast;

// Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
//     return (int) $user->id === (int) $id;
// });

// So when we connect to this channel we gonna replace this id
// users.{id} with the id of the user trying to connect to the channel
// Then we will get the current authenticated user function (User $user, $id) { and make
//     sure there id is matches the id we passed in there users.{id}
Broadcast::channel('chat.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('example-chat', function () {});