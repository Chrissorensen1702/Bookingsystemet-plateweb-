<div class="header-center">
    @hasSection('header-center')
        @yield('header-center')
    @else
        @include('layouts.partials.header.header-center')
    @endif
</div>
