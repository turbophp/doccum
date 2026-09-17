{{--
    The Files/Search shell: full-bleed, no page container, no `flux:main`
    padding (design plan §3). `layouts::app` is untouched and keeps serving
    settings and admin pages; this is the layout the three-pane file browser
    and search adopt instead.
--}}
<x-layouts::app.shell :title="$title ?? null">
    {{ $slot }}
</x-layouts::app.shell>
