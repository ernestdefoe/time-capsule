<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-time-capsule.email.community.html.body', [
            '{title}' => e($blueprint->community->title),
            '{url}' => $url->to('forum')->route('time-capsule.community', ['id' => $blueprint->community->id]),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
