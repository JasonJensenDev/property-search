@props(['title' => null, 'wide' => false])

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' — ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://assets.utahrealestate.com">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
    <div class="min-h-full">
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
            <div class="mx-auto flex h-14 max-w-[1600px] items-center gap-1 px-4 sm:px-6">
                <a href="{{ route('dashboard') }}" class="mr-3 flex items-center gap-2 font-semibold tracking-tight">
                    <span class="grid size-7 place-items-center rounded-md bg-slate-900 text-sm text-white">⌂</span>
                    <span class="hidden sm:inline">Property Search</span>
                </a>

                <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Overview</x-nav-link>
                <x-nav-link :href="route('review.index')" :active="request()->routeIs('review.*')">
                    Review
                    @if ($queueCount = \App\Models\Listing::reviewQueue()->count())
                        <span class="ml-1.5 rounded-full bg-slate-900 px-1.5 py-0.5 text-[11px] font-semibold text-white">{{ $queueCount }}</span>
                    @endif
                </x-nav-link>
                <x-nav-link :href="route('listings.index')" :active="request()->routeIs('listings.*')">All listings</x-nav-link>
                <x-nav-link :href="route('criteria.edit')" :active="request()->routeIs('criteria.*')">Criteria</x-nav-link>

                <form method="POST" action="{{ route('scrape.store') }}" class="ml-auto">
                    @csrf
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-slate-700">
                        Scrape now
                    </button>
                </form>
            </div>
        </header>

        @if (session('status'))
            <div x-data="{ show: true }" x-show="show" x-transition
                 class="mx-auto mt-4 max-w-[1600px] px-4 sm:px-6">
                <div class="flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">
                    <span class="font-medium">{{ session('status') }}</span>
                    <button type="button" @click="show = false" class="ml-auto text-emerald-600 hover:text-emerald-900">&times;</button>
                </div>
            </div>
        @endif

        @if ($errors->any())
            <div class="mx-auto mt-4 max-w-[1600px] px-4 sm:px-6">
                <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm text-rose-800">
                    {{ $errors->first() }}
                </div>
            </div>
        @endif

        <main class="mx-auto {{ $wide ? 'max-w-[1600px]' : 'max-w-7xl' }} px-4 py-6 sm:px-6">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
