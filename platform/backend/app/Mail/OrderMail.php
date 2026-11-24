<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderMail extends Mailable
{
    use Queueable;

    public function __construct(public Order $order, public string $kind) {}

    public function envelope(): Envelope
    {
        $store = (string) Setting::get('store_name', 'Perfumarathi');
        $n = $this->order->number;
        $status = ucwords(str_replace('_', ' ', $this->order->status));
        $subject = match ($this->kind) {
            'placed' => "$store: we received your order $n",
            'paid' => "$store: payment received for order $n",
            'admin_new' => "New order $n (".number_format((float) $this->order->total, 2).' '.$this->order->currency.')',
            default => "$store: your order $n is now $status",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order', with: [
            'store' => (string) Setting::get('store_name', 'Perfumarathi'),
            'trackUrl' => rtrim((string) config('app.url'), '/').'/track-order?number='.urlencode($this->order->number),
            'whatsapp' => (string) Setting::get('whatsapp_number'),
        ]);
    }
}
