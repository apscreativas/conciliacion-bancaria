<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankFormat extends Model
{
    use \App\Models\Traits\TeamOwned;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = [
        'team_id',
        'banco_id',
        'name',
        'start_row',
        'date_column',
        'description_column',
        'amount_column',
        'debit_column',
        'credit_column',
        'reference_column',
        'type_column',
        'color',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function banco()
    {
        return $this->belongsTo(Banco::class);
    }
}
