<?php

namespace App\Services;

class MetaWhatsappPayload
{
    /**
     * @return array<int, array{phone: string, text: string, phone_number_id: ?string}>
     */
    public function extractInboundMessages(array $payload): array
    {
        $messages = [];

        foreach (data_get($payload, 'entry', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (data_get($entry, 'changes', []) as $change) {
                if (! is_array($change)) {
                    continue;
                }

                if (data_get($change, 'field') !== 'messages') {
                    continue;
                }

                $value = data_get($change, 'value', []);
                $phoneNumberId = data_get($value, 'metadata.phone_number_id');

                foreach (data_get($value, 'messages', []) as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    if (data_get($message, 'type') !== 'text') {
                        continue;
                    }

                    $phone = preg_replace('/\D+/', '', (string) data_get($message, 'from', '')) ?? '';
                    $text = trim((string) data_get($message, 'text.body', ''));

                    if ($phone === '' || $text === '') {
                        continue;
                    }

                    $messages[] = [
                        'phone' => $phone,
                        'text' => $text,
                        'phone_number_id' => is_string($phoneNumberId) ? $phoneNumberId : null,
                    ];
                }
            }
        }

        return $messages;
    }
}
