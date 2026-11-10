<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-time-capsule.email.opened.plain.body', [
'{date}' => $blueprint->capsule->sealed_at->isoFormat('LL'),
'{url}' => $url->to('forum')->route('time-capsule.letter', ['id' => $blueprint->capsule->id]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
