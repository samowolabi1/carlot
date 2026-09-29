@extends('admin.two-factor-layout')
@section('title', 'Enter your code')
@section('content')
    <h1 class="text-[22px] font-bold">Enter your code</h1>
    <p class="text-[14px] text-muted">Open your authenticator app and enter the 6-digit code for LotLink Admin, or one of your recovery codes.</p>
    <form method="POST" action="{{ route('admin.2fa.verify') }}" class="flex flex-col gap-2">
        @csrf
        <label class="field-label">Code
            <input name="code" autocomplete="one-time-code" maxlength="12" class="field" required autofocus>
        </label>
        @error('code')<p class="text-[13px] text-danger">{{ $message }}</p>@enderror
        <button class="btn btn-primary mt-1">Continue</button>
    </form>
@endsection
