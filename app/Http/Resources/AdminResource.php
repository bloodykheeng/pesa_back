<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A record as the admin dashboard reads it: the model with the relations the controller loaded,
 * minus anything secret at any depth. `device_token` stays visible to the mobile app's own routes
 * (User::$hidden is left alone), so it is stripped here instead.
 */
class AdminResource extends JsonResource
{
    private const SECRET_KEYS = ['password', 'remember_token', 'device_token'];

    public function toArray(Request $request): array
    {
        $data = self::strip(parent::toArray($request));

        if ($this->resource instanceof User) {
            $data['role'] = $this->resource->relationLoaded('roles')
                ? $this->resource->roles->first()?->name
                : $this->resource->getRoleNames()->first();
            unset($data['roles']);
            $data['has_device'] = ! empty($this->resource->device_token);
        }

        return $data;
    }

    private static function strip(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::strip($value);
            }
        }

        return $data;
    }
}
