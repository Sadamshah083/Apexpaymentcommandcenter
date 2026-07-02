<?php

namespace App\Providers;

use App\Models\BusinessResearch;
use App\Models\ContentAnalysis;
use App\Models\CrmCampaign;
use App\Models\CrmLead;
use App\Models\DeliverabilityTest;
use App\Models\WorkflowLead;
use App\Policies\WorkflowLeadPolicy;
use App\Support\SqliteConcurrency;
use App\View\Composers\PortalSidebarComposer;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureHttpClient();
        $this->configureSqlite();

        Paginator::defaultView('vendor.pagination.tailwind');

        Route::model('deliverability', DeliverabilityTest::class);
        Route::model('contentAnalysis', ContentAnalysis::class);
        Route::model('businessResearch', BusinessResearch::class);
        Route::model('crm', CrmCampaign::class);
        Route::model('crmLead', CrmLead::class);

        Route::bind('lead', function (string $value) {
            return WorkflowLead::findOrFail($value);
        });

        Gate::policy(WorkflowLead::class, WorkflowLeadPolicy::class);

        View::composer('layouts.partials.sidebar-nav-portal', PortalSidebarComposer::class);
    }

    protected function configureHttpClient(): void
    {
        $verify = config('http.verify');

        if ($verify === false || $verify === 'false' || $verify === '0') {
            Http::globalOptions(['verify' => false]);

            return;
        }

        $bundle = config('http.ca_bundle');

        if (is_string($bundle) && $bundle !== '' && is_readable($bundle)) {
            Http::globalOptions(['verify' => $bundle]);
        }
    }

    protected function configureSqlite(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        SqliteConcurrency::configureConnection();

        $queueConnection = config('queue.connections.database.connection');
        if ($queueConnection && $queueConnection !== config('database.default')) {
            SqliteConcurrency::configureConnection($queueConnection);
        }
    }
}
