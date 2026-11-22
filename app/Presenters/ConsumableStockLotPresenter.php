<?php

namespace App\Presenters;

class ConsumableStockLotPresenter extends Presenter
{
    public function __construct(private \App\Models\ConsumableStockLot $lot)
    {
        parent::__construct($lot);
    }

    public function name(): string
    {
        return ($this->lot->consumable->name ?? '').' — '.$this->lot->number;
    }

    public function calendarUrl(): string
    {
        return route('consumables.stock.show', $this->lot->consumable_id).'#stock-lot-'.$this->lot->id;
    }
}
