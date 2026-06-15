<?php

namespace App\Services;

use App\Models\PlatformSetting;

class LandingPageService
{
    public const SETTING_KEY = 'landing_page';

    public static function defaults(): array
    {
        return [
            'published' => true,
            'hero' => [
                'eyebrow' => 'Cardápio digital + delivery',
                'title' => 'Seu delivery profissional',
                'highlight' => 'sem complicação',
                'subtitle' => 'Cardápio online, pedidos em tempo real, WhatsApp automático, cupons de desconto e integração iFood — tudo em um painel pensado para restaurantes e dark kitchens.',
                'cta_primary_text' => 'Quero conhecer',
                'cta_primary_url' => '#interesse',
                'cta_secondary_text' => 'Ver recursos',
                'cta_secondary_url' => '#recursos',
            ],
            'features_section' => [
                'title' => 'Recursos que vendem mais',
                'subtitle' => 'Do pedido ao relatório, sua operação inteira em um só lugar.',
            ],
            'features' => [
                [
                    'icon' => 'utensils',
                    'title' => 'Cardápio digital',
                    'description' => 'Monte categorias, produtos, complementos e fotos com link pronto para compartilhar.',
                ],
                [
                    'icon' => 'shopping-bag',
                    'title' => 'Pedidos em tempo real',
                    'description' => 'Receba pedidos no painel com alerta sonoro e acompanhe cada etapa da cozinha.',
                ],
                [
                    'icon' => 'message-circle',
                    'title' => 'WhatsApp automático',
                    'description' => 'Envie status do pedido e mantenha o cliente informado sem digitar manualmente.',
                ],
                [
                    'icon' => 'package',
                    'title' => 'Integração iFood',
                    'description' => 'Centralize pedidos e catálogo do iFood no mesmo fluxo da sua loja.',
                ],
                [
                    'icon' => 'ticket',
                    'title' => 'Cupons de desconto',
                    'description' => 'Crie códigos promocionais com desconto fixo ou percentual e acompanhe o uso no checkout do cardápio.',
                ],
                [
                    'icon' => 'map-pin',
                    'title' => 'Áreas de entrega',
                    'description' => 'Defina bairros, taxas e prazos para evitar pedidos fora da sua operação.',
                ],
                [
                    'icon' => 'bar-chart',
                    'title' => 'Relatórios financeiros',
                    'description' => 'Veja faturamento, ticket médio, produtos mais vendidos e formas de pagamento.',
                ],
                [
                    'icon' => 'sparkles',
                    'title' => 'Inteligência com IA',
                    'description' => 'Dicas personalizadas para vender mais com base nos dados da sua loja.',
                ],
                [
                    'icon' => 'users',
                    'title' => 'Equipe e filiais',
                    'description' => 'Convide funcionários e opere matriz e filiais no mesmo ecossistema.',
                ],
            ],
            'plans_section' => [
                'title' => 'Planos que crescem com você',
                'subtitle' => 'Comece no trial e evolua conforme sua operação.',
                'show_plans' => true,
            ],
            'cta_section' => [
                'title' => 'Pronto para digitalizar seu delivery?',
                'subtitle' => 'Entre na lista de espera e seja avisado quando abrirmos vagas na sua região.',
            ],
            'lead_form' => [
                'enabled' => true,
                'title' => 'Lista de interesse',
                'subtitle' => 'Deixe seus dados e nossa equipe entra em contato.',
                'button_text' => 'Quero saber mais',
                'success_message' => 'Recebemos seu interesse! Em breve entraremos em contato.',
            ],
            'footer' => [
                'text' => '© PartiuMenu — tecnologia para delivery e restaurantes.',
            ],
        ];
    }

    public static function content(): array
    {
        $raw = PlatformSetting::get(self::SETTING_KEY);

        if (! is_string($raw) || trim($raw) === '') {
            return static::defaults();
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return static::defaults();
        }

        return static::mergeDefaults(static::defaults(), $decoded);
    }

    public static function save(array $payload): array
    {
        $merged = static::mergeDefaults(static::defaults(), $payload);
        PlatformSetting::set(self::SETTING_KEY, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $merged;
    }

    private static function mergeDefaults(array $defaults, array $payload): array
    {
        $merged = $defaults;

        foreach ($payload as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && static::isAssoc($defaults[$key]) && static::isAssoc($value)) {
                $merged[$key] = array_replace_recursive($defaults[$key], $value);
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
