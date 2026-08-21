<?php

namespace App\Modules\WNE\Services;

/**
 * §3L: `{{var}}` substitution against a payload — the one renderer used by both the live
 * preview pane and the actual send path (SendNotificationDeliveryJob), so preview can never
 * show something a real send wouldn't produce.
 */
class TemplateRenderingService
{
    /** A missing variable stays as its raw `{{token}}` in the output — "never a silent blank in production sends." */
    public function render(string $template, array $data): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $match) use ($data) {
            $value = data_get($data, $match[1]);

            return $value !== null ? (string) $value : $match[0];
        }, $template);
    }

    /** Every `{{token}}` actually present in the template text — used both to render and to validate the declared `variables` list covers them. */
    public function extractVariables(string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }
}
