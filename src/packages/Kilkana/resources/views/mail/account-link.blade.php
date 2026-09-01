{{--
    Invitación a la redacción o enlace de recuperación: el mismo enlace, dos textos. En inglés
    como todo lo que ve quien entra al panel, y en Markdown para que la maqueta la ponga Laravel.
--}}
@component('mail::message')
@if ($kind === 'invite')
# You have a seat at {{ $newspaper }}

Someone at **{{ $newspaper }}** has given you a seat in the newsroom. Choose a password and you
are in.

@component('mail::button', ['url' => $url])
Choose my password
@endcomponent
@else
# Reset your password

Someone —hopefully you— asked for a new password for your account at **{{ $newspaper }}**. If it
was not you, ignore this message: nothing changes until you press the button.

@component('mail::button', ['url' => $url])
Choose a new password
@endcomponent
@endif

The link works for {{ $expiresInMinutes }} minutes. After that, ask for a new one from the panel.

{{ $newspaper }}
@endcomponent
