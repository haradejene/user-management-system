<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Authorize application</title></head>
<body>
<main>
    <h1>Authorize {{ $client->name }}</h1>
    <p>Application: {{ $client->application->name }}</p>
    <p>{{ $client->name }} is requesting access to your IAM account.</p>
    @if(count($scopes) > 0)
        <ul>
            @foreach($scopes as $scope)<li>{{ $scope->description }}</li>@endforeach
        </ul>
    @else
        <p>No additional permissions were requested.</p>
    @endif
    <form method="POST" action="{{ route('passport.authorizations.approve') }}">
        @csrf
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit">Approve</button>
    </form>
    <form method="POST" action="{{ route('passport.authorizations.deny') }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit">Deny</button>
    </form>
</main>
</body>
</html>
