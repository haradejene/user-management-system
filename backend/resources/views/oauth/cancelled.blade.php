@extends('oauth.layout')
@section('title', 'Authorization closed')
@section('content')
    <h1 id="page-title">This request is already closed</h1>
    <p class="muted">The authorization page expired, was already used, or belongs to an earlier session. No access was approved by this cancellation.</p>
    <p>Close this page and return to your application. Start a new sign-in there if you want to continue.</p>
@endsection
