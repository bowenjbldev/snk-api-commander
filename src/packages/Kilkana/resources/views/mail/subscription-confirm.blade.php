{{--
    Confirmacion de alta al boletin. Markdown para que la maqueta la ponga Laravel: un
    periodico no gana nada con plantilla propia hasta que tenga marca que aplicarle, y este
    correo se lee igual en cualquier cliente.

    En INGLES como todo lo que ve el lector: el contrato del producto lo es.
--}}
@component('mail::message')
# One last step

Someone —hopefully you— asked to receive the newsletter from **{{ $newspaper }}**.

Confirm the address and you are on the list. If it was not you, ignore this message: nothing
is ever sent until you press the button.

@component('mail::button', ['url' => $confirmUrl])
Confirm my subscription
@endcomponent

Thanks for reading,<br>
{{ $newspaper }}

@slot('subcopy')
Changed your mind? [Unsubscribe]({{ $unsubscribeUrl }}) — no questions asked, and the link
keeps working for as long as this address is on the list.
@endslot
@endcomponent
