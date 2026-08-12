<?php

declare(strict_types=1);

namespace App;

/**
 * Koppeling met de Orderchamp API voor Wholesale - nog NIET geïmplementeerd
 * buiten isConfigured(). Orderchamp is een GraphQL-API (niet REST zoals Faire)
 * met een directe/private access token voor een eigen koppeling (zie
 * docs/wholesale.md). Wordt in een latere fase uitgebreid met orders ophalen,
 * voorraad lezen/schrijven en webhook-verificatie (X-Orderchamp-Signature).
 *
 * Credential hoort in .env (ORDERCHAMP_ACCESS_TOKEN), niet hier.
 */
final class OrderchampService
{
    public static function isConfigured(): bool
    {
        return Config::get('ORDERCHAMP_ACCESS_TOKEN', '') !== '';
    }
}
