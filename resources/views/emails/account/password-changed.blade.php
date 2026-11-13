@extends('emails.layouts.branded')
@section('title', 'Your Elevate Pesa password was changed')
@section('content')
    <h1 style="margin:0 0 12px;font-size:20px;">Hello {{ $user->name }},</h1>
    <p style="margin:0 0 12px;">The password of your Elevate Pesa account was changed {{ $how }}{{ $byName ? ' by ' . $byName : '' }}, on {{ now(config('app.business_timezone'))->format('j M Y \a\t g:i a') }} (Kampala time).</p>
    <p style="margin:0 0 12px;">Devices signed in with the old password have been signed out.</p>
    <p style="margin:0 0 8px;"><b>Not you?</b> {{ $staff ? 'Use "Forgot password" on the admin sign-in page at once, and tell an administrator.' : 'Use "Forgot password" in the app at once, or contact us at admin@elevatepesa.com.' }}</p>
@endsection
