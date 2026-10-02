{{--
    Shared PDF document layout (rendered by App\Services\PdfService with dompdf, never served to browsers).
    $pdfStyles is resources/css/pdf/document.css with token values substituted; $logo is a data URI or null.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>{!! $pdfStyles !!}</style>
</head>
<body>
{{-- Footer repeated on every page (page numbers are drawn by PdfService). --}}
<div class="doc-footer">Malawi Airlines · Roster Builder · {{ $footer ?? 'Times are base local (LT) unless marked UTC.' }} · Not an approved scheduling system.</div>
{{-- Header: logo, title and generation time. --}}
<table class="doc-header"><tr>
    <td>@if($logo)<img class="doc-logo" src="{{ $logo }}" alt="Malawi Airlines">@endif</td>
    <td><div class="doc-title">{{ $title }}</div>@isset($subtitle)<div class="doc-subtitle">{{ $subtitle }}</div>@endisset</td>
    <td class="doc-meta">Generated {{ $generatedAt }}@isset($generatedBy)<br>by {{ $generatedBy }}@endisset</td>
</tr></table>
@yield('body')
</body>
</html>
