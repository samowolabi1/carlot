@extends('admin.two-factor-layout')
@section('title', 'Recovery codes')
@section('content')
    <h1 class="text-[22px] font-bold">Two-step sign-in is on</h1>
    <p class="text-[14px] text-muted">Keep these recovery codes somewhere safe, like a password manager. Each works once if you lose your phone. They won't be shown again.</p>
    <ul class="grid grid-cols-2 gap-2 rounded-xl bg-ivory p-4 font-mono text-[15px]">
        @foreach ($codes as $code)<li>{{ $code }}</li>@endforeach
    </ul>
    <a href="/admin" class="btn btn-primary">Continue to the admin panel</a>
@endsection
