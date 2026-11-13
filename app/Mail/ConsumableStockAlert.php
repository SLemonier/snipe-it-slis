<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class ConsumableStockAlert extends Mailable
{
    public function __construct(public array $rows)
    {
    }

    public function build()
    {
        return $this->subject(trans('consumable_stock.alert_subject'))
            ->view('mail.consumable-stock-alert')->with(['rows' => $this->rows]);
    }
}
