@php($o = $order)
<div style="font-family:Arial,Helvetica,sans-serif;color:#1f1711;max-width:600px;margin:0 auto">
  <h1 style="font-family:Georgia,serif;font-weight:400;letter-spacing:-.02em;margin:0 0 4px">{{ strtoupper($store) }}</h1>
  <hr style="border:0;border-top:3px solid #ec7d28;margin:0 0 18px">
  @if ($kind === 'admin_new')
    <p><strong>New order {{ $o->number }}</strong> from {{ $o->customer_name }} ({{ $o->customer_email }}, {{ $o->customer_phone }}).</p>
    <p>Payment: {{ $o->payment_method === 'cod' ? 'Cash on Delivery' : 'Card' }} · {{ str_replace('_', ' ', $o->payment_status) }}</p>
  @else
    <p>Hello {{ $o->customer_name }},</p>
    @if ($kind === 'placed')
      <p>Thank you for your order <strong>{{ $o->number }}</strong>. We will let you know when it ships.</p>
    @elseif ($kind === 'paid')
      <p>We received your payment for order <strong>{{ $o->number }}</strong>.</p>
    @else
      <p>Your order <strong>{{ $o->number }}</strong> is now <strong>{{ ucwords(str_replace('_', ' ', $o->status)) }}</strong>.</p>
      @if ($o->tracking_number && in_array($o->status, ['shipped'], true))
        <p>Carrier: {{ $o->carrier }}<br>Tracking number: {{ $o->tracking_number }}
          @if ($o->tracking_url)<br><a href="{{ $o->tracking_url }}">Track the parcel</a>@endif</p>
      @endif
    @endif
  @endif
  <table style="width:100%;border-collapse:collapse;margin:16px 0">
    @foreach ($o->items as $i)
      <tr><td style="padding:6px 0;border-bottom:1px solid #eadbc7">{{ $i->product_name }}@if($i->variant_name) · {{ $i->variant_name }}@endif × {{ $i->quantity }}</td>
          <td style="padding:6px 0;border-bottom:1px solid #eadbc7;text-align:right">{{ number_format((float) $i->line_total, 2) }} {{ $o->currency }}</td></tr>
    @endforeach
    @if ((float) $o->discount_total > 0)<tr><td>Discount</td><td style="text-align:right">−{{ number_format((float) $o->discount_total, 2) }}</td></tr>@endif
    <tr><td>Shipping</td><td style="text-align:right">{{ number_format((float) $o->shipping_total, 2) }}</td></tr>
    @if ((float) $o->payment_fee > 0)<tr><td>Payment fee</td><td style="text-align:right">{{ number_format((float) $o->payment_fee, 2) }}</td></tr>@endif
    <tr><td><strong>Total</strong></td><td style="text-align:right"><strong>{{ number_format((float) $o->total, 2) }} {{ $o->currency }}</strong></td></tr>
  </table>
  @if ($kind !== 'admin_new')
    <p><a href="{{ $trackUrl }}" style="display:inline-block;background:#4b2c16;color:#fff;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:700">Track your order</a></p>
    @if ($whatsapp)<p style="color:#6b5d53;font-size:13px">Questions? WhatsApp +{{ $whatsapp }}</p>@endif
  @endif
</div>
