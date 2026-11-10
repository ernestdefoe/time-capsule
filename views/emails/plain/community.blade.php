<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans('ernestdefoe-time-capsule.email.community.plain.body', [
'{title}' => $blueprint->community->title,
'{url}' => $url->to('forum')->route('time-capsule.community', ['id' => $blueprint->community->id]),
]) !!}
</x-slot:body>
</x-mail::plain.notification>
