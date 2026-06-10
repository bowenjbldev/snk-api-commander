<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ config('app.name') }} - API Docs</title>
    <script type="module" src="https://unpkg.com/rapidoc/dist/rapidoc-min.js"></script>
    <style>
        html, body { margin: 0; height: 100%; }
    </style>
</head>
<body>
    <rapi-doc 
        theme="{{ config('scramble.ui.theme', 'light') }}"
        @foreach(config('scramble.rapidoc', []) as $attribute => $value)
            @if(is_scalar($value))
                {{ $attribute }}="{{ $value }}"
            @endif
        @endforeach
    ></rapi-doc>
</body>
</html>
