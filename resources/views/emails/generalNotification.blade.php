@extends('emails.layouts.branded')
@section('title', 'A message from Elevate Pesa')
@section('content')
    <div style="white-space:pre-line;">{{ $notificationMessage }}</div>
@endsection
@section('footer', 'You get this message because you have an Elevate Pesa account.')
