<?php

namespace App\Services;

class WhatsappEvolutionPayload
{
    public function isInboundMessageEvent(string $event): bool
    {
        $normalized = strtolower($event);

        return str_contains($normalized, 'message')
            && ! str_contains($normalized, 'send');
    }

    /**
     * @return array<int, array{phone: string, text: string, from_me: bool}>
     */
    public function extractInboundMessages(array $payload): array
    {
        $messages = [];
        $candidates = $this->messageCandidates($payload);

        foreach ($candidates as $item) {
            if (! is_array($item)) {
                continue;
            }

            $fromMe = (bool) (
                data_get($item, 'key.fromMe')
                ?? data_get($item, 'fromMe')
                ?? false
            );

            if ($fromMe) {
                continue;
            }

            $remoteJid = (string) (
                data_get($item, 'key.remoteJid')
                ?? data_get($item, 'remoteJid')
                ?? ''
            );

            if ($remoteJid === '' || str_contains($remoteJid, '@g.us')) {
                continue;
            }

            $phone = $this->normalizePhoneFromJid($remoteJid);

            if ($phone === '') {
                continue;
            }

            $text = $this->extractText(data_get($item, 'message') ?? $item);

            if ($text === '') {
                continue;
            }

            $messages[] = [
                'phone' => $phone,
                'text' => $text,
                'from_me' => false,
            ];
        }

        return $messages;
    }

    private function messageCandidates(array $payload): array
    {
        $data = $payload['data'] ?? $payload;

        if (isset($data['messages']) && is_array($data['messages'])) {
            return $data['messages'];
        }

        if (isset($data['message']) && is_array($data['message']) && isset($data['key'])) {
            return [$data];
        }

        if (is_array($data) && array_is_list($data)) {
            return $data;
        }

        if (is_array($data)) {
            return [$data];
        }

        return [];
    }

    private function extractText(mixed $message): string
    {
        if (! is_array($message)) {
            return trim((string) $message);
        }

        $text = data_get($message, 'conversation')
            ?? data_get($message, 'extendedTextMessage.text')
            ?? data_get($message, 'imageMessage.caption')
            ?? data_get($message, 'buttonsResponseMessage.selectedDisplayText')
            ?? data_get($message, 'listResponseMessage.title')
            ?? data_get($message, 'text');

        return trim((string) $text);
    }

    private function normalizePhoneFromJid(string $jid): string
    {
        $digits = preg_replace('/\D+/', '', explode('@', $jid)[0] ?? '') ?? '';

        if (strlen($digits) === 11) {
            return '55'.$digits;
        }

        if (strlen($digits) === 10) {
            return '55'.substr($digits, 0, 2).'9'.substr($digits, 2);
        }

        return $digits;
    }
}
