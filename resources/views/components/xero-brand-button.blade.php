@props([
    'href' => null,
    'type' => 'button',
    'wireClick' => null,
    'wireTarget' => null,
    'blue',
    'white',
    'title',
])

@php
    $tag = filled($href) ? 'a' : 'button';
@endphp

<style>
    .xero-brand-button {
        display: inline-flex;
        border-radius: 4px;
        transition: transform 150ms ease, opacity 150ms ease;
        position: relative;
    }

    .xero-brand-button:hover {
        transform: translateY(-2px);
    }

    .xero-brand-button:focus-visible {
        outline: 2px solid rgba(59, 130, 246, 0.6);
        outline-offset: 2px;
    }

    .xero-brand-button[disabled] {
        cursor: wait;
        opacity: 0.7;
    }

    .xero-brand-button__blue {
        display: block;
    }

    .xero-brand-button__blue svg {
        display: block;
        height: 43px;
        width: auto;
        max-width: 100%;
    }
</style>

<{{ $tag }}
    @if (filled($href))
        href="{{ $href }}"
    @endif
    @if (blank($href))
        type="{{ $type }}"
    @endif
    @if (filled($wireClick))
        wire:click="{{ $wireClick }}"
    @endif
    @if (filled($wireTarget))
        wire:target="{{ $wireTarget }}"
    @endif
    wire:loading.attr="disabled"
    {{ $attributes->class('xero-brand-button') }}
>
    <span style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">
        {{ $title }}
    </span>
    <span class="xero-brand-button__blue">
        @include($blue)
    </span>
</{{ $tag }}>
