@extends('printing.layout')

@section('title', 'Name/Number List '.$jobNo)

@section('content')
<h1>Name/Number List — {{ $jobNo }}</h1>
<div class="meta">
    Version {{ $list['version']['version_no'] }} · {{ $list['version']['total_quantity'] }} pcs · confirmed {{ $list['version']['locked_at'] ?? '—' }}
</div>
<table>
    <tr>
        <th>#</th><th>Size</th><th>Qty</th>
        @foreach ($list['schema']['fields'] as $field)
            <th>{{ $field['label'] }}</th>
        @endforeach
        <th>Notes</th>
    </tr>
    @foreach ($list['items'] as $item)
        <tr>
            <td>{{ $item['line_no'] }}</td>
            <td>{{ $item['size'] }}</td>
            <td>{{ $item['quantity'] }}</td>
            @foreach ($list['schema']['fields'] as $field)
                @php
                    $raw = match ($field['key']) {
                        'player_name' => $item['player_name'],
                        'number' => $item['number'],
                        default => $item['values'][$field['key']] ?? null,
                    };
                    $label = collect($field['options'])->firstWhere('code', $raw)['label'] ?? $raw;
                @endphp
                <td>{{ $label }}</td>
            @endforeach
            <td>{{ $item['notes'] }}</td>
        </tr>
    @endforeach
</table>
@endsection
