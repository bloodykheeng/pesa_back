@extends('auth-pages.layout')
@section('title', 'Choose a new password')
@section('content')
    <h1>Choose a new password</h1>
    <p>For {{ $email }}. At least 6 characters.</p>
    <form method="post" action="{{ url('/api/reset-password') }}">
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="password">New password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required minlength="6">
        @isset($errors)
            @if ($errors->has('password'))<div class="error">{{ $errors->first('password') }}</div>@endif
        @endisset
        <label for="password_confirmation">Repeat the new password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required minlength="6">
        <button type="submit">Save new password</button>
    </form>
@endsection
