@extends('emails.layouts.branded')
@section('title', $staff ? 'Your Elevate Pesa admin account' : 'Welcome to Elevate Pesa')
@section('content')
    <h1 style="margin:0 0 12px;font-size:20px;">Hello {{ $user->name }},</h1>
    @if ($staff)
        <p style="margin:0 0 12px;">{{ $byName ? $byName . ' made' : 'You have' }} an account for you on the Elevate Pesa admin dashboard, as <b>{{ $user->getRoleNames()->first() }}</b>.</p>
        <p style="margin:0 0 20px;">Choose your password to sign in. You sign in with <b>{{ $user->email }}</b>.</p>
    @else
        <p style="margin:0 0 12px;">Welcome to Elevate Pesa: gas, water and electronics delivered, paid on delivery or bit by bit.</p>
        <p style="margin:0 0 20px;">Your account uses <b>{{ $user->email }}</b>{{ $user->phone ? ' and ' . $user->phone : '' }}. If you did not choose a password yet, set one here, then sign in in the app.</p>
    @endif
    <p style="margin:0 0 24px;">
        <a href="{{ $link }}" style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:10px;">Choose your password</a>
    </p>
    <p style="margin:0 0 8px;font-size:13px;color:#64748b;">The link works once, for {{ \App\Services\AccountEmails::WELCOME_LINK_DAYS }} days. After that, use "Forgot password" on the sign-in page.</p>
@endsection
@section('footer', 'If you did not expect this account, ignore this email or tell us at admin@elevatepesa.com.')
