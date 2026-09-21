@component('mail::message')
# {{ $heading }}

{{ $line }}

@component('mail::panel')
**{{ $number }}**: {!! $ticketSubject !!}

Status: {{ $status }}
@endcomponent

@component('mail::button', ['url' => $url])
View ticket
@endcomponent

This is an automated message. Please sign in to respond; replies to this email are not read.
@endcomponent
