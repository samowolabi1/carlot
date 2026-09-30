<footer class="px-6 py-4 text-xs text-gray-500">
    @foreach (\App\Domain\Legal\LegalDocuments::TITLES as $key => $title)
        <a href="{{ route('legal.show', $key) }}" target="_blank" class="mr-3 hover:underline">{{ $title }}</a>
    @endforeach
    <span>Staff access is audit-logged. Handle personal data only as the Privacy Policy and your role allow.</span>
</footer>
