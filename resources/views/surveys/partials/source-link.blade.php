@php
    $sourceUrl = config('app.source_url');
    $hasSourceUrl = is_string($sourceUrl)
        && filter_var($sourceUrl, FILTER_VALIDATE_URL)
        && parse_url($sourceUrl, PHP_URL_SCHEME) === 'https'
        && parse_url($sourceUrl, PHP_URL_USER) === null
        && parse_url($sourceUrl, PHP_URL_PASS) === null;
@endphp
@if ($hasSourceUrl)
    @if ($explanation ?? false)
        <h2>Lietotnes pirmkods</h2>
        <p>Aptauju lietotnes darbību varat pārskatīt <a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">publiskajā pirmkoda repozitorijā <span aria-hidden="true">↗</span></a>.</p>
    @else
        <a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">Pirmkods <span aria-hidden="true">↗</span></a>
    @endif
@endif
