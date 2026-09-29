<x-mail::message>
# Welcome to LotLink, {{ $ownerName }}

We've set up **{{ $lot->name }}** for you. Sign in with this email address (we'll send you a code, no password needed) and:

- add your cars with photos,
- add your bank details so customers can pay you directly,
- share your lot's page and QR code with buyers.

<x-mail::button :url="$url">
Sign in to LotLink
</x-mail::button>

Questions? Reply to this email or use Help & support once you're signed in.
</x-mail::message>
