<?php

namespace App\Support;

use App\Models\WorkflowLead;

class LeadRoute
{
    public static function isAdminContext(?bool $admin = null): bool
    {
        if ($admin !== null) {
            return $admin;
        }

        return request()->is('admin*') || str_starts_with((string) request()->route()?->getName(), 'admin.');
    }

    public static function show(WorkflowLead|int $lead, ?bool $admin = null): string
    {
        $id = $lead instanceof WorkflowLead ? $lead->id : $lead;

        return self::isAdminContext($admin)
            ? route('admin.leads.show', $id)
            : route('portal.leads.show', $id);
    }

    public static function name(string $suffix, ?bool $admin = null): string
    {
        $prefix = self::isAdminContext($admin) ? 'admin' : 'portal';

        return "{$prefix}.leads.{$suffix}";
    }
}
