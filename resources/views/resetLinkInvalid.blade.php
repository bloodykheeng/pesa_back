@extends('auth-pages.layout')
@section('title', 'Link expired')
@section('content')
    <h1>This link has expired</h1>
    <p>Reset links work once, for {{ \App\Http\Controllers\PasswordResetController::LINK_MINUTES }} minutes. Ask for a new one from the app's "Forgot password" screen.</p>
@endsection
