<x-mail::message>
# Your sign-in code

<x-mail::panel>
<span style="font-size: 28px; font-weight: 700; letter-spacing: 6px;">{{ $code }}</span>
</x-mail::panel>

Enter it on LotLink to sign in. It expires in {{ $minutes }} minutes.

Don't share this code with anyone. LotLink will never ask you for it.

If you didn't ask for a code, you can ignore this email.
</x-mail::message>
