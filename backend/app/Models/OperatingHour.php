<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatingHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'day_of_week',
        'opening_time',
        'closing_time',
        'is_closed'
    ];

    /**
     * Casts para garantir que o Laravel trate os campos corretamente.
     * Isso ajuda na hora de comparar horas no Controller ou Model.
     */
    protected $casts = [
        'day_of_week' => 'integer',
        'is_closed' => 'boolean',
        'opening_time' => 'datetime:H:i',
        'closing_time' => 'datetime:H:i',
        // 'opening_time' => 'datetime:H:i', // Opcional, dependendo da versão do Laravel
    ];
    /**
     * Helper para legibilidade dos dias da semana
     */
    public const DAYS = [
        0 => 'Domingo',
        1 => 'Segunda-feira',
        2 => 'Terça-feira',
        3 => 'Quarta-feira',
        4 => 'Quinta-feira',
        5 => 'Sexta-feira',
        6 => 'Sábado',
    ];

    /**
     * Relacionamento Inverso: O horário pertence a uma loja.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
