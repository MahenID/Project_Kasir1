<?php

namespace App\Exceptions;

class InventoryFrozenException extends DomainException
{
    public function __construct(string $message = 'Operasi inventaris ditolak karena toko sedang dalam jeda stocktake (audit stok fisik).', array $fields = [])
    {
        parent::__construct($message, 'INVENTORY_FROZEN', 409, $fields);
    }
}
