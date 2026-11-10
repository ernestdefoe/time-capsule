<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-time-capsule.email.opened.html.body', [
            '{date}' => $blueprint->capsule->sealed_at->isoFormat('LL'),
            '{url}' => $url->to('forum')->route('time-capsule.letter', ['id' => $blueprint->capsule->id]),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
