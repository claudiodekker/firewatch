<h1>Receipt {{ $receipt }}</h1>
@php
    throw new RuntimeException("Receipt [{$receipt}] has no total.");
@endphp
