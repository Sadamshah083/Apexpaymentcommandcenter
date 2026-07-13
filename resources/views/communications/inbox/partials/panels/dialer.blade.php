<div class="ghl-inbox-conversation-scroll ghl-inbox-conversation-scroll--center">
    @include('communications.partials.center-dialer-hub', [
        'routePrefix' => $routePrefix,
        'hubAccess' => $hubAccess ?? [],
        'phoneUsers' => $phoneUsers ?? [],
        'morpheusExtensions' => $morpheusExtensions ?? [],
        'defaultCallerId' => $defaultCallerId ?? null,
        'prefillNumber' => $prefillNumber ?? null,
        'callLogs' => $callLogs ?? [],
        'dialerCallLogsHasMore' => $dialerCallLogsHasMore ?? false,
        'dialerImportedLeads' => $dialerImportedLeads ?? [],
        'dialerImportedLeadsHasMore' => $dialerImportedLeadsHasMore ?? false,
        'dialerCampaignOptions' => $dialerCampaignOptions ?? [],
        'filters' => $filters ?? [],
        'clickToCall' => $clickToCall ?? null,
    ])
</div>
