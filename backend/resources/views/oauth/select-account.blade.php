@extends('oauth.layout')
@section('title', 'Choose an account')
@section('content')
    <h1 id="page-title">Choose an account</h1>
    <p class="muted">Continue to <strong>{{ $client->application->name }}</strong> with Doxa.</p>
    <h2>Your signed-in account</h2>
    <form method="POST" action="{{ route('oauth.select-account') }}">
        @csrf
        <input type="hidden" name="choice_token" value="{{ $choiceToken }}">
        <button class="account full" type="submit" name="choice" value="current">
            <span class="avatar" aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span>
            <span class="identity"><strong>{{ $user->name }}</strong><br><span class="email">{{ $user->email }}</span></span>
            <span aria-hidden="true" style="margin-left:auto">→</span>
        </button>
        <button class="secondary full" type="submit" name="choice" value="other">Use another account</button>
        <p class="muted" style="font-size:14px">Using another account signs out this account and asks you to sign in. Only your current session is available here.</p>
        <button class="text-button" type="submit" name="choice" value="cancel">Cancel</button>
    </form>
@endsection
