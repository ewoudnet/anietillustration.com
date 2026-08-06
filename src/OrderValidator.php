<?php

declare(strict_types=1);

namespace App;

final class OrderValidator
{
    private const MAX_QUANTITY = 20;

    /**
     * @param array<string,mixed> $input
     * @param array<int, array<string,mixed>> $variants Actieve prijsvarianten van de special.
     * @return array{0: array<string,mixed>, 1: string[]} [sanitizedData, errors]
     */
    public static function validate(array $input, array $variants): array
    {
        $errors = [];

        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $street = trim((string) ($input['street'] ?? ''));
        $houseNumber = trim((string) ($input['house_number'] ?? ''));
        $postalCode = trim((string) ($input['postal_code'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $countryCode = strtoupper(trim((string) ($input['country_code'] ?? '')));
        $email = trim((string) ($input['email'] ?? ''));
        $quantityRaw = $input['quantity'] ?? '';
        $variantId = filter_var($input['price_variant_id'] ?? '', FILTER_VALIDATE_INT);

        if ($firstName === '' || mb_strlen($firstName) > 100) {
            $errors[] = 'Vul een geldige voornaam in.';
        }
        if ($lastName === '' || mb_strlen($lastName) > 100) {
            $errors[] = 'Vul een geldige achternaam in.';
        }
        if ($street === '' || mb_strlen($street) > 150) {
            $errors[] = 'Vul een geldige straatnaam in.';
        }
        if ($houseNumber === '' || mb_strlen($houseNumber) > 20) {
            $errors[] = 'Vul een geldig huisnummer in.';
        }
        if ($postalCode === '' || mb_strlen($postalCode) > 20) {
            $errors[] = 'Vul een geldige postcode in.';
        }
        if ($city === '' || mb_strlen($city) > 100) {
            $errors[] = 'Vul een geldige plaats in.';
        }
        if (!Countries::isShippableForStorefront($countryCode)) {
            $errors[] = 'We verzenden alleen binnen Nederland en de EU. Kies een geldig land.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors[] = 'Vul een geldig e-mailadres in.';
        }

        $quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
            $errors[] = 'Kies een aantal tussen 1 en ' . self::MAX_QUANTITY . '.';
            $quantity = 1;
        }

        $selectedVariant = null;
        if ($variantId !== false) {
            foreach ($variants as $variant) {
                if ((int) $variant['id'] === $variantId) {
                    $selectedVariant = $variant;
                    break;
                }
            }
        }

        if ($selectedVariant === null) {
            $errors[] = 'Kies een geldige prijsvariant.';
        }

        $sanitized = [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'street' => $street,
            'houseNumber' => $houseNumber,
            'postalCode' => $postalCode,
            'city' => $city,
            'countryCode' => $countryCode,
            'email' => $email,
            'quantity' => $quantity,
            'variant' => $selectedVariant,
        ];

        return [$sanitized, $errors];
    }
}
