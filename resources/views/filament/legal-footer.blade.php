{{-- Inline styles: the admin panel uses Filament's own stylesheet, which doesn't carry every utility class. --}}
<footer style="display: flex; flex-wrap: wrap; align-items: center; gap: 4px 16px; padding: 16px 24px; font-size: 12px; color: #6b7280;">
    <nav aria-label="Legal" style="display: flex; flex-wrap: wrap; gap: 4px 16px;">
        @foreach (\App\Domain\Legal\LegalDocuments::TITLES as $key => $title)
            <a href="{{ route('legal.show', $key) }}" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; min-height: 32px; color: inherit; text-decoration: underline; text-underline-offset: 2px;">{{ $title }}</a>
        @endforeach
    </nav>
    <span>Staff access is audit-logged. Handle personal data only as the Privacy Policy and your role allow.</span>
</footer>
