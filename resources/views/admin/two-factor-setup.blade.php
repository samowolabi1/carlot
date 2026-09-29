@extends('admin.two-factor-layout')
@section('title', 'Set up two-step sign-in')
@section('content')
    <h1 class="text-[22px] font-bold">Set up two-step sign-in</h1>
    <p class="text-[14px] text-muted">Admin accounts need a code from an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…). Scan this with the app:</p>
    <img src="{{ $qr }}" alt="QR code for your authenticator app" class="mx-auto h-52 w-52">
    <p class="text-center text-[13px] text-muted">Can't scan? Enter this key: <code class="font-semibold text-ink">{{ $secret }}</code></p>
    <form method="POST" action="{{ route('admin.2fa.confirm') }}" class="flex flex-col gap-2">
        @csrf
        <label class="field-label">Code from the app
            <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" class="field" required autofocus>
        </label>
        @error('code')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
        <button class="btn btn-primary mt-1">Turn on</button>
    </form>
@endsection
