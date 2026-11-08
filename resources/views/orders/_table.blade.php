@if ($orders->isEmpty())
    <x-empty icon="receipt" title="No orders yet" text="Create a payment link or call the API to start accepting UPI payments.">
        <a href="{{ route('orders.create') }}" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> Create payment link</a>
    </x-empty>
@else
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-100">
        <thead class="bg-slate-50/70">
            <tr>
                <th class="th">Order</th>
                @isset($showMerchant)<th class="th">Merchant</th>@endisset
                <th class="th">Customer</th>
                <th class="th text-right">Amount</th>
                <th class="th">Status</th>
                <th class="th">UTR</th>
                <th class="th">Date</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($orders as $order)
                <tr class="hover:bg-slate-50/80 cursor-pointer transition" data-href="{{ route('orders.show', $order) }}">
                    <td class="td">
                        <a href="{{ route('orders.show', $order) }}" class="font-mono text-[13px] font-medium text-slate-900 hover:text-brand-600">{{ $order->order_no }}</a>
                        @if ($order->client_order_id !== $order->order_no)
                            <div class="text-xs text-slate-500 mt-0.5">Ref: {{ $order->client_order_id }}</div>
                        @endif
                    </td>
                    @isset($showMerchant)<td class="td text-slate-700">{{ $order->user->displayName() }}</td>@endisset
                    <td class="td text-slate-600">{{ $order->customer_name ?: ($order->customer_mobile ?: '—') }}</td>
                    <td class="td text-right font-semibold text-slate-900 tabular-nums">₹{{ number_format($order->amount, 2) }}</td>
                    <td class="td"><x-status :status="$order->status" /></td>
                    <td class="td font-mono text-xs text-slate-600">
                        @if ($order->status === 'review')
                            ••••••••{{ substr($order->customer_utr, -4) }}<div class="font-sans text-[11px] text-sky-600">submitted by customer</div>
                        @else
                            {{ $order->utr ?? '—' }}
                        @endif
                    </td>
                    <td class="td text-slate-500 whitespace-nowrap">
                        <div>{{ $order->created_at->format('d M Y') }}</div>
                        <div class="text-xs">{{ $order->created_at->format('h:i A') }}</div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
