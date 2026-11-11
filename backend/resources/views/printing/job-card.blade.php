@extends('printing.layout')

@section('title', 'Job Card '.$card['job_no'])

@section('content')
<h1>Job Card — {{ $card['job_no'] }}</h1>
<div class="meta">
    {{ $card['customer'] }} · {{ $card['branch'] }} / {{ $card['page'] }} · Due {{ $card['due_date'] }}@if ($card['is_urgent']) · URGENT @endif
</div>
<div class="grid">
    <div>
        @if ($card['design_image_url'])
            <img class="design" src="{{ $card['design_image_url'] }}" alt="Approved design">
        @else
            <p>No design preview.</p>
        @endif
    </div>
    <div>
        <table>
            <tr><th>Category</th><td>{{ $card['product']['category'] }}</td></tr>
            <tr><th>Order type</th><td>{{ $card['product']['order_type'] ?? '—' }}</td></tr>
            <tr><th>Material</th><td>{{ $card['material'] ?? '—' }} {{ $card['material_details'] ?? '' }}</td></tr>
            <tr><th>Quantity</th><td>{{ $card['quantity'] }}</td></tr>
            @foreach (array_filter([$card['bulk_meter'], $card['patch_meter'], ...$card['other_measurements']]) as $m)
                <tr><th>{{ $m['label'] }}</th><td>{{ $m['value'] === null ? 'Not entered' : $m['value'].' '.$m['unit'] }}</td></tr>
            @endforeach
            <tr><th>Production</th><td>{{ ucfirst((string) ($card['bulk']['mode'] ?? '—')) }}</td></tr>
        </table>
    </div>
</div>
@if ($card['list'])
    <h2>Confirmed name/number list — version {{ $card['list']['version_no'] }} ({{ $card['list']['total_quantity'] }} pcs)</h2>
    <table>
        <tr><th>Size</th><th>Quantity</th></tr>
        @foreach ($card['list']['by_size'] as $row)
            <tr><td>{{ $row['size'] }}</td><td>{{ $row['quantity'] }}</td></tr>
        @endforeach
    </table>
    @if (count($card['list']['groups']) > 0)
        <table>
            <tr><th>Type</th><th>Quantity</th></tr>
            @foreach ($card['list']['groups'] as $group)
                <tr><td>{{ $group['label'] }}</td><td>{{ $group['quantity'] }}</td></tr>
            @endforeach
        </table>
    @endif
@endif
@endsection
