<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantRejectionReason extends Model
{
    use HasFactory;

    public const FIELD_LABELS = [
        'name_invalid' => 'Nombre del restaurante',
        'description_invalid' => 'Descripción',
        'address_invalid' => 'Dirección',
        'phone_invalid' => 'Teléfono',
        'email_invalid' => 'Email',
        'categories_missing' => 'Categorías',
        'photos_missing' => 'Fotos',
        'website_invalid' => 'Sitio web',
        'hours_invalid' => 'Horarios',
    ];

    protected $fillable = [
        'restaurant_id',
        'name_invalid',
        'description_invalid',
        'address_invalid',
        'phone_invalid',
        'email_invalid',
        'categories_missing',
        'photos_missing',
        'website_invalid',
        'hours_invalid',
        'notes',
        'rejected_by',
    ];

    protected $casts = [
        'name_invalid' => 'boolean',
        'description_invalid' => 'boolean',
        'address_invalid' => 'boolean',
        'phone_invalid' => 'boolean',
        'email_invalid' => 'boolean',
        'categories_missing' => 'boolean',
        'photos_missing' => 'boolean',
        'website_invalid' => 'boolean',
        'hours_invalid' => 'boolean',
    ];

    /**
     * Relación con el restaurante
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Relación con el usuario administrador que rechazó
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Obtiene los campos que están marcados como inválidos
     */
    public function getInvalidFields(): array
    {
        $invalidFields = [];

        foreach (array_keys(self::FIELD_LABELS) as $field) {
            if ($this->{$field}) {
                $invalidFields[] = $field;
            }
        }

        return $invalidFields;
    }

    /**
     * Devuelve etiquetas legibles para los campos inválidos.
     */
    public function getInvalidFieldLabels(): array
    {
        return array_map(
            fn (string $field) => self::FIELD_LABELS[$field] ?? $field,
            $this->getInvalidFields()
        );
    }
}
