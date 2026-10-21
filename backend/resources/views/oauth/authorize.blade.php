@extends('oauth.layout')
@section('title', 'Authorize application')
@section('content')
    <h1 id="page-title">Continue to {{ $client->application->name }}</h1>
    <p class="muted"><strong>{{ $client->name }}</strong> wants to use your Doxa identity.</p>
    <div class="account">
        <span class="avatar" aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span>
        <div class="identity"><strong>{{ $user->name }}</strong><div class="email">{{ $user->email }}</div></div>
    </div>
    @if(collect($scopes)->contains(fn ($scope) => $scope->id === 'openid'))
    <form method="POST" action="{{ route('oauth.change-account') }}">
        @csrf
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button class="text-button" type="submit">Choose an account</button>
    </form>
    @endif
    <h2>Review what you’re sharing</h2>
    @if(count($scopes) > 0)
        <ul class="permissions">
            @foreach($scopes as $scope)
                <li><span class="check" aria-hidden="true">✓</span><span>{{ match ($scope->id) {
                    'openid' => 'Confirm your identity and sign you in',
                    'profile' => 'Read your basic profile, including your name',
                    'email' => 'Read your email address and verification status',
                    'iam:read' => 'Read your central IAM identity',
                    default => $scope->description,
                } }}</span></li>
            @endforeach
        </ul>
    @else
        <p>No additional permissions were requested.</p>
    @endif
    <p class="notice">Approval shares only the requested information. It does not assign application access, change your workplace role, or share your password.</p>
    <div class="actions">
    <form method="POST" action="{{ route('passport.authorizations.approve') }}">
        @csrf
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button class="primary" type="submit">Approve</button>
    </form>
    <form method="POST" action="{{ route('passport.authorizations.deny') }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button class="secondary" type="submit">Cancel</button>
    </form>
    </div>
@endsection
