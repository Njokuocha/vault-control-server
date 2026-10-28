<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#080d14">
        <meta name="description" content="{{ config('app.name') }} departmental access portal.">
        <meta name="application-acronym" content="{{ config('app.acronym') }}">
        <title>{{ config('app.name') }} — Department Access</title>
        @livewireStyles
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="agency-portal">
        <main class="agency-portal__main">
            <header class="agency-portal__masthead">
                <span class="agency-portal__symbol" aria-hidden="true">{{ config('app.symbol') }}</span>
                <div>
                    <p class="agency-portal__eyebrow">INTELLIGENCE &amp; SECURITY SYSTEM</p>
                    <h1>{{ config('app.name') }}</h1>
                    <p class="agency-portal__subtitle">Select your department to continue.</p>
                </div>
                <span class="agency-portal__status"><i></i> SECURE ACCESS PORTAL</span>
            </header>

            <section class="agency-network" aria-label="Agency department access">
                <a class="agency-network__server" href="{{ route('login') }}" wire:navigate aria-label="Administrator sign in">
                    <i class="agency-network__server-watermark ri ri-server-line" aria-hidden="true"></i>
                    <span class="agency-network__server-icon ri ri-shield-keyhole-line" aria-hidden="true"></span>
                    <span class="agency-network__server-copy">
                        <small>CENTRAL ADMINISTRATION</small>
                        <strong>{{ config('app.acronym') }} SERVER</strong>
                        <span>Administrator sign in</span>
                    </span>
                    <span class="agency-network__server-action ri ri-arrow-right-up-line" aria-hidden="true"></span>
                </a>

                <svg class="agency-network__connections" viewBox="0 0 1000 110" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M500 0V50M500 50H166V104M500 50V104M500 50H834V104" />
                    <circle class="agency-network__junction" cx="500" cy="50" r="5" />
                    <circle cx="166" cy="104" r="5" />
                    <circle cx="500" cy="104" r="5" />
                    <circle cx="834" cy="104" r="5" />
                </svg>

                <nav class="agency-departments" aria-label="Select department">
                    <a class="agency-department" href="{{ route('cfm.login') }}" wire:navigate data-portal-navigation="Case File Management">
                        <i class="agency-department__watermark ri ri-folder-shield-2-line" aria-hidden="true"></i>
                        <span class="agency-department__code">CFM <span>01</span></span>
                        <strong>Case File Management</strong>
                        <small>CASE MANAGER ACCESS</small>
                        <span class="agency-department__enter">OPEN DEPARTMENT <i class="ri ri-arrow-right-line" aria-hidden="true"></i></span>
                    </a>
                    <a class="agency-department" href="{{ route('pm.login') }}" wire:navigate data-portal-navigation="Profile Management">
                        <i class="agency-department__watermark ri ri-user-search-line" aria-hidden="true"></i>
                        <span class="agency-department__code">PM <span>02</span></span>
                        <strong>Profile Management</strong>
                        <small>PERSON RECORDS ACCESS</small>
                        <span class="agency-department__enter">OPEN DEPARTMENT <i class="ri ri-arrow-right-line" aria-hidden="true"></i></span>
                    </a>
                    <a class="agency-department" href="{{ route('fm.login') }}" wire:navigate data-portal-navigation="File Management">
                        <i class="agency-department__watermark ri ri-archive-stack-line" aria-hidden="true"></i>
                        <span class="agency-department__code">FM <span>03</span></span>
                        <strong>File Management</strong>
                        <small>SECURE ARCHIVE ACCESS</small>
                        <span class="agency-department__enter">OPEN DEPARTMENT <i class="ri ri-arrow-right-line" aria-hidden="true"></i></span>
                    </a>
                </nav>
            </section>

            <footer class="agency-portal__footer">
                <span><i></i> RESTRICTED PERSONNEL ACCESS</span>
                <span>{{ config('app.acronym') }} · PRIVATE NETWORK · MONITORED SESSION</span>
            </footer>
        </main>
        <x-alert-host />
        @livewireScripts
    </body>
</html>
