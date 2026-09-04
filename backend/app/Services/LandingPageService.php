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
                'eyebrow' => 'Cardápio digital PartiuMenu',
                'title' => 'Seu cardápio digital',
                'highlight' => 'com pedidos em tempo real',
                'subtitle' => 'Crie o cardápio digital do restaurante, receba pedidos online, use Pix, cupons e WhatsApp automático — sem comissão por pedido e sem precisar falar com vendedor.',
                'cta_primary_text' => 'Criar conta grátis',
                'cta_primary_url' => '/register',
                'cta_secondary_text' => 'Ver cardápio demo',
                'cta_secondary_url' => 'https://app.partiumenu.com.br/lojademo',
            ],
            'features_section' => [
                'title' => 'Cardápio digital simples de operar',
                'subtitle' => 'Publique o menu, receba pedidos ao vivo e mantenha o cliente informado — feito para restaurantes e dark kitchens.',
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
                    'icon' => 'zap',
                    'title' => 'Fácil de usar',
                    'description' => 'Quem testou elogiou: montar cardápio, aceitar pedidos e operar o dia a dia sem curva de aprendizado.',
                ],
                [
                    'icon' => 'bookmark',
                    'title' => 'Endereço salvo',
                    'description' => 'Cliente que já pediu não precisa digitar o endereço de novo — repete o pedido em poucos toques.',
                ],
                [
                    'icon' => 'message-circle',
                    'title' => 'WhatsApp automático',
                    'description' => 'Envie confirmação e status do pedido pelo WhatsApp e mantenha o cliente informado sem digitar manualmente.',
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
                    'title' => 'Inteligência de dados',
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
                'title' => 'Cardápio digital no ar sem falar com vendedor',
                'subtitle' => 'Crie a conta, monte o menu e compartilhe o link. Ou teste a loja demo antes de cadastrar.',
            ],
            'lead_form' => [
                'enabled' => true,
                'title' => 'Ainda tem dúvida?',
                'subtitle' => 'Deixe uma mensagem — ou crie a conta e comece sozinho.',
                'button_text' => 'Enviar mensagem',
                'success_message' => 'Mensagem recebida. Enquanto isso, você já pode criar sua conta.',
            ],
            'footer' => [
                'text' => '© PartiuMenu — cardápio digital para restaurantes e delivery.',
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
