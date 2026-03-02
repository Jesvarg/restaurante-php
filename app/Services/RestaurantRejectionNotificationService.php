<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantRejectionReason;
use App\Notifications\RestaurantRejectedNotification;

class RestaurantRejectionNotificationService
{
    /**
     * Envía notificación de rechazo al propietario del restaurante
     */
    public function sendRejectionNotification(Restaurant $restaurant, RestaurantRejectionReason $rejectionReason): void
    {
        $rejectedFields = $this->formatRejectedFields($rejectionReason);
        
        $restaurant->user->notify(new RestaurantRejectedNotification(
            $restaurant,
            $rejectedFields,
            $rejectionReason->notes
        ));
    }
    
    /**
     * Formatea los campos rechazados en un mensaje legible
     */
    private function formatRejectedFields(RestaurantRejectionReason $rejectionReason): array
    {
        $fieldMessages = [
            'name_invalid' => 'El nombre del restaurante es inválido o inapropiado',
            'description_invalid' => 'La descripción necesita ser mejorada o es inadecuada',
            'address_invalid' => 'La dirección está incorrecta o incompleta',
            'phone_invalid' => 'El teléfono es inválido o incompleto',
            'email_invalid' => 'El email de contacto es inválido',
            'categories_missing' => 'Faltan categorías o son incorrectas',
            'photos_missing' => 'Faltan fotos o las existentes son inadecuadas',
            'website_invalid' => 'El sitio web es inválido',
            'hours_invalid' => 'Los horarios son incorrectos o incompletos',
        ];
        
        $rejectedFields = [];
        $invalidFields = $rejectionReason->getInvalidFields();
        
        foreach ($invalidFields as $field) {
            if (isset($fieldMessages[$field])) {
                $rejectedFields[] = $fieldMessages[$field];
            }
        }
        
        return $rejectedFields;
    }
    
    /**
     * Genera un mensaje de rechazo completo
     */
    public function generateRejectionMessage(Restaurant $restaurant, RestaurantRejectionReason $rejectionReason): string
    {
        $rejectedFields = $this->formatRejectedFields($rejectionReason);
        
        $message = "Su restaurante '{$restaurant->name}' ha sido rechazado por las siguientes razones:\n\n";
        
        foreach ($rejectedFields as $index => $field) {
            $message .= ($index + 1) . ". {$field}\n";
        }
        
        if ($rejectionReason->notes) {
            $message .= "\nNotas adicionales del administrador:\n{$rejectionReason->notes}";
        }
        
        $message .= "\n\nPor favor, corrija estos aspectos y vuelva a enviar su restaurante para revisión.";
        
        return $message;
    }
}
