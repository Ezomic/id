<x-mail::message>
# Your login code

Use the code below to sign in to Thijssensoftware ID. It expires in 10 minutes.

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

If you didn't try to sign in, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
