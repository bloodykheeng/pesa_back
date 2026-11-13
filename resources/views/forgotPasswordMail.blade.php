@extends('emails.layouts.branded')
@section('title', $data['title'])
@section('content')
    <h1 style="margin:0 0 12px;font-size:20px;">{{ $data['title'] }}</h1>
    <p style="margin:0 0 20px;">{{ $data['body'] }}</p>
    <p style="margin:0 0 24px;">
        <a href="{{ $data['url'] }}" style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:10px;">Choose a new password</a>
    </p>
    <p style="margin:0 0 8px;font-size:13px;color:#64748b;">The link works once, for {{ \App\Http\Controllers\PasswordResetController::LINK_MINUTES }} minutes. If you did not ask for it, ignore this email: your password stays the same.</p>
@endsection
