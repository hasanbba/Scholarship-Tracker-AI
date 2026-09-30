<?php

namespace App\Support;

final class ApiResponse
{
    /** @return array{success: bool, message: string, data?: mixed, errors?: array} */
    public static function success(string $message, mixed $data = []): array
    {
        return ['success' => true, 'message' => $message, 'data' => $data];
    }

    /** @return array{success: bool, message: string, errors?: array} */
    public static function failure(string $message, array $errors = []): array
    {
        $response = ['success' => false, 'message' => $message];

        if ($errors !== []) {
            $response['errors'] = $errors;
        }

        return $response;
    }
}
