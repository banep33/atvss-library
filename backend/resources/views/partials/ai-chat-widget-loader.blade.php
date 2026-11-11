@php
    $aiUser         = auth()->user();
    $aiIsClan       = $aiUser?->isClan() ?? false;
    $aiIsRestricted = $aiUser?->isAdminOrBibliotekar() ?? false;

    $aiGuestMode = !$aiIsClan;
@endphp

@if(!$aiIsRestricted)
    @include('partials.ai-chat-widget', ['guestMode' => $aiGuestMode])
@endif
