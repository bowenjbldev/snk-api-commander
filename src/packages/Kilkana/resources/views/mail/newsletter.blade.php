{{--
    Un numero del boletin. Markdown para que la maqueta la ponga Laravel, como el resto de
    correos del paquete. En INGLES como todo lo que ve el lector: el contrato del producto lo es.

    El pixel va al final y solo cuando hay a quien contarselo: una prueba no lleva.
--}}
@component('mail::message')
@if ($preheader)
<span style="display:none;max-height:0;overflow:hidden;color:transparent">{{ $preheader }}</span>
@endif
# {{ $issueSubject }}

@foreach ($posts as $post)
## [{{ $post['title'] }}]({{ $post['url'] }})

{{ $post['excerpt'] }}

@endforeach

Thanks for reading,<br>
{{ $newspaper }}

@slot('subcopy')
You receive this because you confirmed your subscription to {{ $newspaper }}.
[Unsubscribe]({{ $unsubscribeUrl }}) — one click, no login, and the link keeps working.
@if ($openUrl)
<img src="{{ $openUrl }}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0">
@endif
@endslot
@endcomponent
