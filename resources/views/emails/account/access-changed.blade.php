@extends('emails.layouts.branded')
@section('title', 'Your Elevate Pesa access changed')
@section('content')
    <h1 style="margin:0 0 12px;font-size:20px;">Hello {{ $user->name }},</h1>
    <p style="margin:0 0 12px;">{{ $byName ? $byName . ' changed' : 'An administrator changed' }} your Elevate Pesa account:</p>
    <ul style="margin:0 0 16px;padding-left:20px;">
        @isset($changes['role'])
            <li>Role: {{ $changes['role'][0] ?? 'none' }} &rarr; <b>{{ $changes['role'][1] }}</b></li>
        @endisset
        @isset($changes['status'])
            <li>Status: {{ ucfirst($changes['status'][0] ?? '') }} &rarr; <b>{{ ucfirst($changes['status'][1]) }}</b></li>
        @endisset
    </ul>
    @if (($changes['status'][1] ?? null) === 'inactive')
        <p style="margin:0 0 8px;">An inactive account cannot sign in. If you think this is a mistake, contact admin@elevatepesa.com.</p>
    @elseif (($changes['role'][1] ?? null) && in_array($changes['role'][1], \App\Models\User::STAFF_ROLES, true))
        <p style="margin:0 0 8px;">You can sign in to the admin dashboard with your email and password.</p>
    @endif
@endsection
